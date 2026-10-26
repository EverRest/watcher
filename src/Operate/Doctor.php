<?php

declare(strict_types=1);

namespace Watcher\Operate;

use Psr\Clock\ClockInterface;
use Watcher\ApiContext\ApiContextReader;
use Throwable;
use Watcher\Config\WatchConfig;
use Watcher\Issues\IssueSource;
use Watcher\Metrics\MetricSource;
use Watcher\Notify\Destination;
use Watcher\Notify\DigestExtras;
use Watcher\Notify\Message;
use Watcher\Notify\Notifier;
use Watcher\Observe\EventFactory;
use Watcher\Observe\SinkDispatcher;
use Watcher\Schedule\Window;
use Watcher\Source\KubernetesEventsSource;
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
 *              Factory::modelCatalog($config, $http), Factory::notifier($http, $config->schedule['lock_ttl']), $clock)
 * and, when WatchConfig::fromArray throws InvalidConfig: Doctor::configFailure($e->getMessage()).
 * where Factory::modelCatalog returns ?ModelCatalog: `provider === 'anthropic' ? new AnthropicModelCatalog($http, (string) $c->analysis['api_key']) : null`.
 */
final class Doctor
{
    private const STEPS = ['triage', 'analyze', 'escalate'];
    private const NAMES = ['config', 'source', 'provider_auth', 'models', 'prices', 'state', 'notifier'];
    private const SCRATCH_TTL = 60;

    /** The master switch was read off the raw config before validation (see switchedOff()). */
    private bool $switchedOff = false;

    public function __construct(
        private readonly WatchConfig $config,
        private readonly LogSource $source,
        private readonly StateStore $store,
        private readonly ?ModelCatalog $catalog,
        private readonly Notifier $notifier,
        private readonly ClockInterface $clock,
        private readonly bool $kubernetes = false,
        private readonly ?KubernetesEventsSource $events = null,
        private readonly ?SinkDispatcher $observe = null,
        private readonly ?MetricSource $metrics = null,
        private readonly ?IssueSource $issues = null,
        private readonly ?ApiContextReader $apiContext = null,
    ) {
    }

    /**
     * A Doctor for a config whose master switch is off, read before validation: nothing is validated, built or read, and
     * run() reports the switch only.
     */
    public static function switchedOff(ClockInterface $clock): self
    {
        $self = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $refused = RefusedAccess::state();
        foreach (['switchedOff' => true, 'source' => RefusedAccess::source(), 'store' => $refused, 'notifier' => RefusedAccess::notifier(), 'clock' => $clock] as $name => $value) {
            (new \ReflectionProperty(self::class, $name))->setValue($self, $value); // readonly, set as Hygiene::fromConfig does
        }

        return $self;
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
        if ($this->switchedOff || !$this->config->enabled) {
            // The config was validated; a disabled watcher calls nothing, so there is nothing else to check.
            return new CheckReport([CheckResult::pass('config', 'disabled (enabled=false)'), ...array_map(static fn (string $n): CheckResult => CheckResult::skip($n, 'disabled'), array_slice(self::NAMES, 1))]);
        }
        $results = [CheckResult::pass('config')];
        $results[] = $this->guard('source', fn (): CheckResult => $this->source());
        if ($this->config->kubernetes !== null && $this->config->kubernetes->events) {
            $results[] = $this->guard('events', fn (): CheckResult => $this->events());
        }
        [$auth, $models] = $this->provider();
        $results[] = $auth;
        $results[] = $models;
        $results[] = $this->guard('prices', fn (): CheckResult => $this->prices());
        $results[] = $this->guard('state', fn (): CheckResult => $this->state());
        $results[] = $this->guard('notifier', fn (): CheckResult => $this->notifier());
        if ($this->metrics !== null) {
            $results[] = $this->guard('metrics', fn (): CheckResult => $this->metrics());
        }
        if ($this->issues !== null) {
            $results[] = $this->guard('sentry', fn (): CheckResult => $this->sentry());
        }
        $observing = $this->config->observe->anyEnabled();
        if ($observing) {
            $results[] = $this->guard('observe', fn (): CheckResult => $this->observe());
        }
        if ($this->apiContext !== null) {
            $results[] = $this->guard('api_context', fn (): CheckResult => $this->apiContext());
        }
        if ($postTest) {
            $results[] = $this->guard('post_test', fn (): CheckResult => $this->postTest());
            if ($observing) {
                $results[] = $this->guard('post_test_observe', fn (): CheckResult => $this->postTestObserve());
            }
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

    /** Spec 008: reads only (GET, limit=1); the wording is a fixed vocabulary, never a URL, a token or a body. */
    private function events(): CheckResult
    {
        $k = $this->config->kubernetes;
        if ($k === null || !$this->config->on('kubernetes') || $this->events === null) {
            return CheckResult::skip('events', 'disabled');
        }
        foreach ($this->events->probe() as $ns => $reason) {
            if ($reason !== '') {
                return CheckResult::fail('events', 'namespace ' . $ns . ': ' . $reason);
            }
        }
        $tags = implode(' ', array_map('strval', $this->config->source['stream_tags'] ?? []));
        if ($k->exporter || preg_match('/event[-_]?(exporter|router)|kube[-_]?events/i', $tags) === 1) {
            return CheckResult::warn('events', 'native events and an event exporter are both configured; the same events would be counted twice');
        }

        return CheckResult::pass('events');
    }

    private function source(): CheckResult
    {
        if ($this->source instanceof KubernetesEventsSource) {
            foreach ($this->source->probe() as $ns => $reason) {
                if ($reason !== '') {
                    return CheckResult::fail('source', 'namespace ' . $ns . ': ' . $reason);
                }
            }

            return CheckResult::pass('source');
        }
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

    /** One trivial query (`vector(1)`): proves the URL, the credential and the answer shape; no configured query is run. */
    private function metrics(): CheckResult
    {
        try {
            $r = $this->metrics?->instant('vector(1)', $this->clock->now());
        } catch (SourceUnavailable $e) {
            return CheckResult::fail('metrics', self::token($e));
        }

        return $r?->values === [1.0] ? CheckResult::pass('metrics') : CheckResult::fail('metrics', 'bad_response');
    }

    /** One read of at most one issue over the last five minutes. */
    private function sentry(): CheckResult
    {
        try {
            $this->issues?->issues(Window::endingAt($this->clock->now(), 'PT5M'), 1);
        } catch (SourceUnavailable $e) {
            return CheckResult::fail('sentry', self::token($e));
        }

        return CheckResult::pass('sentry');
    }

    /** The fixed failure vocabulary; the exception message (it may carry a URL or a token) is never copied. */
    private static function token(SourceUnavailable $e): string
    {
        return in_array($e->getMessage(), DigestExtras::TOKENS, true) ? $e->getMessage() : 'unreachable';
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
        $apiKey = $probe['x-api-key'] ?? 'error';
        if ($apiKey !== 200) {
            return [CheckResult::fail('provider_auth', $apiKey === 'timeout' ? 'timeout' : 'x-api-key rejected (HTTP ' . $apiKey . ')'), CheckResult::skip('models', 'provider auth failed')];
        }

        return [CheckResult::pass('provider_auth', 'x-api-key accepted'), $this->models($ids)];
    }

    /** @param list<string> $ids */
    private function models(array $ids): CheckResult
    {
        $bad = [];
        foreach (self::STEPS as $step) {
            $want = (string) $this->config->analysis['models'][$step];
            // An alias ("m-mid") is listed under its dated form ("m-mid-20250929").
            $found = array_filter($ids, static fn (string $id): bool => $id === $want || preg_match('/^' . preg_quote($want, '/') . '-\d{8}$/D', $id) === 1);
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
        } catch (\Watcher\Config\InvalidConfig $e) {
            return CheckResult::fail('state', $e->getMessage()); // names the setting, never its value
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
        if (!$first || $second) {
            return CheckResult::fail('state', 'lock not exclusive');
        }
        $warnings = self::stateWarnings($this->config->state, $this->kubernetes, extension_loaded('zlib'));

        return CheckResult::pass('state', $warnings === [] ? '' : 'warning: ' . implode('; ', $warnings));
    }

    /**
     * Setups that work but lose data or space; shown as a `warning:` on a passing state check.
     *
     * @param array<string, mixed> $state
     * @return list<string>
     */
    public static function stateWarnings(array $state, bool $kubernetes, bool $zlib): array
    {
        $out = [];
        if (($state['driver'] ?? null) === 'file' && $kubernetes) {
            $out[] = 'state.driver=file inside Kubernetes (KUBERNETES_SERVICE_HOST is set): a pod-local directory loses history on every new pod; use state.driver=redis (docs/operations.md)';
        }
        $redis = is_array($state['redis'] ?? null) ? $state['redis'] : [];
        if (($state['driver'] ?? null) === 'redis' && ($redis['compress_min_bytes'] ?? 512) !== 0 && !$zlib) {
            $out[] = 'ext-zlib is not loaded: Redis values are stored uncompressed';
        }

        return $out;
    }

    private function notifier(): CheckResult
    {
        $bad = [];
        foreach (['digest', 'notice'] as $kind) {
            $key = $this->config->destination($kind)->invalidKey();
            if ($key !== null) {
                $bad[] = $kind . ' ' . $key . ' invalid'; // the key name only, never its value
            }
        }

        return $bad === [] ? CheckResult::pass('notifier', $this->config->destination('digest')->driver() === 'none' ? 'none (dashboard-only)' : '') : CheckResult::fail('notifier', implode(', ', $bad));
    }

    /** Spec 010: reads and reduces the OpenAPI source once, skipping the cache; no model call, no post. The reason is a count or a fixed token. */
    private function apiContext(): CheckResult
    {
        $read = $this->apiContext?->readFresh();
        if ($read === null || $read->spec === null) {
            return CheckResult::fail('api_context', $read->failure ?? 'unreadable');
        }

        return CheckResult::pass('api_context', sprintf('%d operations kept, %d not kept', count($read->spec->ops), $read->spec->dropped));
    }

    /** Local and syntactic only, no request: the sink, then the key that is wrong (never a path, URL or token). */
    private function observe(): CheckResult
    {
        $o = $this->config->observe;
        $ok = [];
        $bad = [];
        if ($o->log['enabled']) {
            $ok[] = 'log ok';
        }
        if ($o->file['enabled']) {
            $dir = dirname($o->file['path']);
            while (!file_exists($dir) && $dir !== dirname($dir)) {
                $dir = dirname($dir); // a missing directory is created at the first flush: its nearest existing parent must be writable
            }
            self::sinkOk($ok, $bad, 'file', 'path', is_dir($dir) && is_writable($dir) && !is_link($o->file['path']));
        }
        if ($o->http['enabled']) {
            $ok[] = 'http ok'; // the URL was validated at load; no request is made
        }
        if ($o->prometheus['enabled']) {
            self::sinkOk($ok, $bad, 'prometheus', 'dir', is_dir($o->prometheus['dir']) && is_writable($o->prometheus['dir']));
        }

        return $bad === [] ? CheckResult::pass('observe', implode(', ', $ok)) : CheckResult::fail('observe', implode(', ', $bad));
    }

    /**
     * @param list<string> $ok
     * @param list<string> $bad
     */
    private static function sinkOk(array &$ok, array &$bad, string $sink, string $key, bool $good): void
    {
        $good ? $ok[] = $sink . ' ok' : $bad[] = $sink . ' ' . $key . ' invalid';
    }

    /** One check_test event to every enabled sink except prometheus (a one-event file would replace the last real metrics). */
    private function postTestObserve(): CheckResult
    {
        if ($this->observe === null) {
            return CheckResult::skip('post_test_observe', 'no sink');
        }
        $event = (new EventFactory($this->clock, JsonSummary::packageVersion('everrest/watcher')))->checkTest($this->config->service, $this->config->environment);
        $failed = $this->observe->dispatch([$event], 30);
        $o = $this->config->observe;
        $parts = [];
        foreach (['log', 'file', 'http'] as $sink) {
            if ($o->{$sink}['enabled']) {
                $parts[] = $sink . (isset($failed[$sink]) ? ' failed' : ' ok');
            }
        }

        return $failed === [] ? CheckResult::pass('post_test_observe', implode(', ', $parts)) : CheckResult::fail('post_test_observe', implode(', ', $parts));
    }

    private function postTest(): CheckResult
    {
        /** @var list<array{Destination, list<string>}> $groups one per distinct destination */
        $groups = [];
        foreach (['digest', 'notice'] as $kind) {
            $to = $this->config->destination($kind);
            foreach ($groups as $i => [$seen]) {
                if ($seen->sameAs($to)) {
                    $groups[$i][1][] = $kind;
                    continue 2;
                }
            }
            $groups[] = [$to, [$kind]];
        }
        $failed = [];
        foreach ($groups as [$to, $kinds]) {
            $label = implode(', ', $kinds);
            try {
                $this->notifier->send(new Message("watcher check: test message for {$this->config->service} ({$label}) - safe to ignore"), $to);
            } catch (Throwable) {
                $failed[] = $label; // the exception text may carry a credential
            }
        }

        return $failed === [] ? CheckResult::pass('post_test') : CheckResult::fail('post_test', 'delivery failed (' . implode('; ', $failed) . ')');
    }
}
