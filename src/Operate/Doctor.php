<?php

declare(strict_types=1);

namespace Watcher\Operate;

use Psr\Clock\ClockInterface;
use Throwable;
use Watcher\Config\WatchConfig;
use Watcher\Notify\Destination;
use Watcher\Notify\Message;
use Watcher\Notify\Notifier;
use Watcher\Schedule\Window;
use Watcher\Source\LogSource;
use Watcher\Source\SourceUnavailable;
use Watcher\Source\StreamSelector;
use Watcher\State\StateStore;

/**
 * `watcher check`: every check runs, independently, and none makes a billable call. There is no Analyzer here by design.
 * Reasons are a fixed vocabulary (contracts/cli.md); exception messages are never copied into the report.
 *
 * The integrator builds the collaborators from config (after WatchConfig::fromArray succeeded):
 *   new Doctor($config, Factory::source($config, $http, $clock), Factory::state($config, $clock),
 *              Factory::modelCatalog($config, $http), Factory::notifier($http), $clock)
 * and, when WatchConfig::fromArray throws InvalidConfig: Doctor::configFailure($e->getMessage()).
 * where Factory::modelCatalog returns ?ModelCatalog: `provider === 'anthropic' ? new AnthropicModelCatalog($http, (string) $c->analysis['api_key']) : null`.
 */
final class Doctor
{
    private const STEPS = ['triage', 'analyze', 'escalate'];
    private const NAMES = ['config', 'source', 'provider_auth', 'models', 'prices', 'state', 'notifier'];
    private const SCRATCH_TTL = 60;

    public function __construct(
        private readonly WatchConfig $config,
        private readonly LogSource $source,
        private readonly StateStore $store,
        private readonly ?ModelCatalog $catalog,
        private readonly Notifier $notifier,
        private readonly ClockInterface $clock,
    ) {
    }

    /** The report when the config could not even be loaded: nothing else can be built, so the rest are skipped. */
    public static function configFailure(string $reason): CheckReport
    {
        $results = [CheckResult::fail('config', $reason)];
        foreach (array_slice(self::NAMES, 1) as $name) {
            $results[] = CheckResult::skip($name, 'config invalid');
        }

        return new CheckReport($results);
    }

    /** @param bool $postTest also post one labelled test message per distinct destination */
    public function run(bool $postTest = false): CheckReport
    {
        $results = [CheckResult::pass('config')];
        $results[] = $this->guard('source', fn (): CheckResult => $this->source());
        [$auth, $models] = $this->provider();
        $results[] = $auth;
        $results[] = $models;
        $results[] = $this->guard('prices', fn (): CheckResult => $this->prices());
        $results[] = $this->guard('state', fn (): CheckResult => $this->state());
        $results[] = $this->guard('notifier', fn (): CheckResult => $this->notifier());
        if ($postTest) {
            $results[] = $this->guard('post_test', fn (): CheckResult => $this->postTest());
        }

        return new CheckReport($results);
    }

    /** @param callable(): CheckResult $check */
    private function guard(string $name, callable $check): CheckResult
    {
        try {
            return $check();
        } catch (Throwable $e) {
            return CheckResult::fail($name, 'internal error (' . basename(str_replace('\\', '/', $e::class)) . ')');
        }
    }

    private function source(): CheckResult
    {
        $window = Window::endingAt($this->clock->now(), 'PT5M');
        try {
            $this->source->totals($window, new StreamSelector(array_map('strval', $this->config->source['stream_tags'])));
        } catch (SourceUnavailable $e) {
            // Classified, never copied: the message may carry a URL, a token or a log line.
            $code = preg_match('/(?<!\d)([45]\d\d)(?!\d)/', $e->getMessage(), $m) === 1 ? (int) $m[1] : 0;

            return CheckResult::fail('source', match (true) {
                $code === 401, $code === 403 => 'unauthorised',
                $code > 0 => 'HTTP ' . $code,
                default => 'unavailable',
            });
        }

        return CheckResult::pass('source');
    }

    /** @return array{CheckResult, CheckResult} provider_auth, models */
    private function provider(): array
    {
        if ($this->catalog === null) {
            return [CheckResult::skip('provider_auth', 'provider has no model list'), CheckResult::skip('models', 'provider has no model list')];
        }
        try {
            $probe = $this->catalog->probe();
            $ids = $this->catalog->modelIds();
        } catch (Throwable $e) {
            $reason = 'internal error (' . basename(str_replace('\\', '/', $e::class)) . ')';

            return [CheckResult::fail('provider_auth', $reason), CheckResult::skip('models', 'provider auth failed')];
        }
        $bearer = $probe['bearer'] ?? 'error';
        $apiKey = $probe['x-api-key'] ?? 'error';
        if ($bearer !== 200) {
            $auth = $apiKey === 200
                ? CheckResult::fail('provider_auth', 'package scheme (Authorization: Bearer) rejected (HTTP ' . $bearer . ') but x-api-key works: the analyzer needs a code change')
                : CheckResult::fail('provider_auth', $bearer === 'timeout' && $apiKey === 'timeout' ? 'timeout' : 'no scheme accepted (HTTP ' . $bearer . '/' . $apiKey . ')');

            return [$auth, CheckResult::skip('models', 'provider auth failed')];
        }

        return [CheckResult::pass('provider_auth', 'package scheme accepted'), $this->models($ids)];
    }

    /** @param list<string> $ids */
    private function models(array $ids): CheckResult
    {
        $bad = [];
        foreach (self::STEPS as $step) {
            $want = (string) $this->config->analysis['models'][$step];
            // An alias ("m-mid") is listed under its dated form ("m-mid-20250929").
            $found = array_filter($ids, static fn (string $id): bool => $id === $want || preg_match('/^' . preg_quote($want, '/') . '-\d{8}$/', $id) === 1);
            if ($found === []) {
                $bad[] = $step;
            }
        }

        return $bad === [] ? CheckResult::pass('models') : CheckResult::fail('models', 'unknown model for ' . implode(', ', $bad));
    }

    private function prices(): CheckResult
    {
        $bad = array_filter(self::STEPS, fn (string $step): bool => !isset($this->config->analysis['prices'][$this->config->analysis['models'][$step]]));

        return $bad === [] ? CheckResult::pass('prices') : CheckResult::fail('prices', 'no price for ' . implode(', ', $bad));
    }

    private function state(): CheckResult
    {
        $id = bin2hex(random_bytes(8));
        $key = 'check:' . $id;
        $counter = 'check:n:' . $id;
        $lock = 'check:lock:' . $id;

        try {
            $this->store->put($key, ['probe' => $id], self::SCRATCH_TTL);
            $this->store->increment($counter, 1.0, self::SCRATCH_TTL);
            $this->store->increment($counter, 2.0, self::SCRATCH_TTL);
        } catch (Throwable) {
            return CheckResult::fail('state', 'write failed'); // the driver message can carry a DSN or password
        }
        if ((($this->store->get($key)['probe'] ?? null) !== $id) || (float) ($this->store->get($counter)['total'] ?? -1) !== 3.0) {
            return CheckResult::fail('state', 'read-back mismatch');
        }
        $first = $this->store->acquire($lock, $id, self::SCRATCH_TTL);
        $second = $this->store->acquire($lock, $id . 'x', self::SCRATCH_TTL);
        $this->store->release($lock, $id);

        // The scratch keys expire after SCRATCH_TTL: StateStore has no delete.
        return $first && !$second ? CheckResult::pass('state') : CheckResult::fail('state', 'lock not exclusive');
    }

    private function notifier(): CheckResult
    {
        $bad = [];
        foreach (['digest', 'notice'] as $kind) {
            $url = (string) $this->config->notify[$kind]['webhook'];
            $parts = parse_url($url);
            if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
                $bad[] = $kind . ' webhook invalid';
            }
        }

        return $bad === [] ? CheckResult::pass('notifier') : CheckResult::fail('notifier', implode(', ', $bad));
    }

    private function postTest(): CheckResult
    {
        /** @var array<string, list<string>> $byWebhook */
        $byWebhook = [];
        foreach (['digest', 'notice'] as $kind) {
            $byWebhook[(string) $this->config->notify[$kind]['webhook']][] = $kind;
        }
        $failed = [];
        foreach ($byWebhook as $webhook => $kinds) {
            $label = implode(', ', $kinds);
            try {
                $this->notifier->send(
                    new Message("watcher check: test message for {$this->config->service} ({$label}) - safe to ignore"),
                    new Destination((string) $webhook),
                );
            } catch (Throwable) {
                $failed[] = $label; // the exception text may carry the webhook
            }
        }

        return $failed === [] ? CheckResult::pass('post_test') : CheckResult::fail('post_test', 'delivery failed (' . implode('; ', $failed) . ')');
    }
}
