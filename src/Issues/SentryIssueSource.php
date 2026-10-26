<?php

declare(strict_types=1);

namespace Watcher\Issues;

use DateTimeImmutable;
use DateTimeZone;
use Closure;
use GuzzleHttp\ClientInterface;
use Psr\Clock\ClockInterface;
use Throwable;
use Watcher\Config\InvalidConfig;
use Watcher\Config\SecureUrl;
use Watcher\Level;
use Watcher\Schedule\Window;
use Watcher\Source\GuardedRequest;
use Watcher\Source\Retry;
use Watcher\Source\Slug;
use Watcher\Source\SourceUnavailable;

/**
 * Reads the unresolved issue list of one Sentry project. Read-only: one GET per page of the issue list, nothing else.
 * Nine named keys are picked and type-checked; everything else in the response is never read into an object
 * (Principle I). An issue that cannot be read honestly is dropped and counted, never guessed. Failures carry a fixed
 * token, never a URL, token or body.
 *
 * UNVERIFIED against a live Sentry: parameter names (`query`, `sort`, `limit`, `groupStatsPeriod`), the `stats`
 * shape (`{"24h": [[ts, count], ...]}`) and the `Link` header `cursor` are from public docs; pinned by fixtures.
 */
final class SentryIssueSource implements IssueSource
{
    // Bounds on the RAW text only. The display cut (IssueGroupBuilder::TITLE/CULPRIT) comes after masking: cutting first
    // can leave half of an e-mail or token that no rule matches any more.
    private const MAX_TITLE = 2000;
    private const MAX_CULPRIT = 1000;
    private const MAX_PAGE = 100;
    private const LEVELS = ['fatal' => Level::Critical, 'error' => Level::Error, 'warning' => Level::Warning, 'info' => Level::Info, 'debug' => Level::Debug];

    private readonly string $baseUrl;
    /** @var \Closure(): string the credential lives in a closure, so var_export/print_r/var_dump show no value */
    private readonly Closure $token;
    private readonly GuardedRequest $request;
    private int $dropped = 0;
    private int $requests = 0;

    public function __construct(
        ClientInterface $http,
        string $baseUrl,
        private readonly string $organization,
        private readonly string $project,
        #[\SensitiveParameter] string $token,
        private readonly int $maxIssues = 50,
        ?callable $sleeper = null,
        int $retryBudget = 120,
        private readonly ?ClockInterface $clock = null,
        bool $allowInsecure = false,
        int $budgetSeconds = 60,
        ?Closure $stop = null,
        ?callable $now = null,
        bool $allowPrivateNetwork = false,
    ) {
        // The shared classifier (SecureUrl::internalHost): loopback only with allow_insecure, RFC 1918 and `*.svc` only with allow_private_network.
        if (($problem = SecureUrl::problem($baseUrl, $allowInsecure, true, $allowPrivateNetwork)) !== null) {
            throw new InvalidConfig('sentry.base_url ' . $problem);
        }
        if (!Slug::valid($organization) || !Slug::valid($project)) {
            throw new InvalidConfig('sentry.organization and sentry.project must be letters, digits, _ . - and not only dots');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = static fn (): string => $token;
        $this->request = new GuardedRequest($http, new Retry($sleeper, $retryBudget), 'sentry', $budgetSeconds, $stop, $now);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('SentryIssueSource is not serializable');
    }

    public function issues(Window $window, int $limit): array
    {
        $this->dropped = 0;
        $this->requests = 0;
        $limit = max(0, min($limit, $this->maxIssues));
        $period = ($window->end->getTimestamp() - $window->start->getTimestamp()) <= 86400 ? '24h' : '14d';
        $bucket = $period === '24h' ? 3600 : 86400;
        // stats cover the last <period> up to now, in buckets: a window that STARTS before the oldest bucket is only partly
        // covered (the count would silently be a fraction of the window), so it is refused as `window_too_old`, never counted
        // short. One bucket of slack: the bucket holding the start still overlaps it and is counted whole.
        $now = ($this->clock?->now() ?? new DateTimeImmutable('now'))->getTimestamp();
        if ($now - $window->start->getTimestamp() > ($period === '24h' ? 86400 : 14 * 86400) + $bucket) {
            throw new SourceUnavailable('window_too_old');
        }
        $this->request->begin();
        $maxRequests = (int) ceil($limit / self::MAX_PAGE) + 2; // dropped items must not keep the loop alive
        $out = [];
        $cursor = null;
        while (count($out) < $limit && $this->requests < $maxRequests) {
            $query = [
                'query' => 'is:unresolved lastSeen:>=' . $window->start->format('Y-m-d\TH:i:s\Z'),
                'sort' => 'freq',
                'limit' => (string) min(self::MAX_PAGE, $limit),
                'groupStatsPeriod' => $period,
            ] + ($cursor === null ? [] : ['cursor' => $cursor]);
            [$items, $next] = $this->page($query);
            foreach ($items as $item) {
                $record = $this->record($item, $period, $window, $bucket);
                if ($record === null) {
                    ++$this->dropped;
                } elseif (count($out) < $limit) {
                    $out[] = $record;
                }
            }
            if ($items === [] || $next === null || $next === $cursor) {
                break;
            }
            $cursor = $next;
        }

        return $out;
    }

    /** Issues dropped by the last call (resolved, malformed, unreadable). */
    public function dropped(): int
    {
        return $this->dropped;
    }

    public function requests(): int
    {
        return $this->requests;
    }

    /**
     * @param array<string, string> $query
     *
     * @return array{list<mixed>, ?string} items and the next cursor
     */
    private function page(array $query): array
    {
        $url = $this->baseUrl . '/api/0/projects/' . rawurlencode($this->organization) . '/' . rawurlencode($this->project) . '/issues/';
        ++$this->requests;
        [$data, $response] = $this->request->send('GET', $url, ['query' => $query, 'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . ($this->token)()]]);
        if (!array_is_list($data)) {
            throw new SourceUnavailable('bad_response');
        }

        // only the cursor is taken from the Link header; the URL in it is never followed (no credential redirect)
        $next = null;
        foreach (explode(',', $response->getHeaderLine('Link')) as $part) {
            if (str_contains($part, 'rel="next"') && str_contains($part, 'results="true"') && preg_match('/cursor="([0-9:]{1,40})"/', $part, $m) === 1) {
                $next = $m[1];
            }
        }

        return [$data, $next];
    }

    private function record(mixed $item, string $period, Window $window, int $bucket): ?IssueRecord
    {
        if (!is_array($item)) {
            return null;
        }
        $id = $item['id'] ?? null;
        $title = $item['title'] ?? null;
        $culprit = $item['culprit'] ?? '';
        $level = $item['level'] ?? null;
        if (!is_string($id) || preg_match('/^\d{1,20}$/D', $id) !== 1 || ($item['status'] ?? null) !== 'unresolved'
            || !is_string($title) || !is_string($culprit) || !is_string($level) || !isset(self::LEVELS[$level])
            || preg_match('//u', $title . $culprit) !== 1) {
            return null;
        }
        $first = self::time($item['firstSeen'] ?? null);
        $last = self::time($item['lastSeen'] ?? null);
        $count = self::windowed(is_array($item['stats'] ?? null) ? ($item['stats'][$period] ?? null) : null, $window, $bucket);
        if ($first === null || $last === null || $count === null) {
            return null;
        }

        return new IssueRecord(
            $id,
            mb_substr($title, 0, self::MAX_TITLE),
            mb_substr($culprit, 0, self::MAX_CULPRIT),
            self::LEVELS[$level],
            $first,
            $last,
            $count,
            ($item['substatus'] ?? null) === 'regressed' || ($item['isRegression'] ?? null) === true,
        );
    }

    private static function time(mixed $v): ?DateTimeImmutable
    {
        if (!is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}T/', $v) !== 1) {
            return null;
        }
        try {
            return (new DateTimeImmutable($v))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    /** Sum of buckets overlapping [start, end) (a bucket is `$length` seconds from its timestamp); null when the shape is not a list of [ts, count]. */
    private static function windowed(mixed $buckets, Window $window, int $length): ?int
    {
        if (!is_array($buckets)) {
            return null;
        }
        $sum = 0;
        foreach ($buckets as $b) {
            if (!is_array($b) || !isset($b[0], $b[1]) || !is_numeric($b[0]) || !is_numeric($b[1])) {
                return null;
            }
            if ($b[0] + $length > $window->start->getTimestamp() && $b[0] < $window->end->getTimestamp()) {
                $sum += max(0, (int) $b[1]);
            }
        }

        return $sum;
    }
}
