<?php

declare(strict_types=1);

namespace Watcher\Source\Kubernetes;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Watcher\Source\ResponseLimit;
use Watcher\Schedule\RunGuard;
use Watcher\Source\Retry;
use Watcher\Source\SourceUnavailable;

/**
 * The only way the package talks to a cluster (spec 008 FR-002, FR-003): GET list calls on events and pods of one
 * namespace, nothing else. The method and the path are fixed here; a caller cannot choose either. Failure text is a fixed
 * vocabulary: never the URL, the token or a response body. List responses are kept for 60 s on the injected clock, so
 * fleet entries on one cluster share one request per namespace per trigger (research R12).
 */
final class KubeApi
{
    public const PAGE = 500;
    public const MAX_PAGES = 20;
    private const MEMO_SECONDS = 60;
    private const POD_PAGE = 200;
    private const MAX_LIST_BYTES = 64 * 1024 * 1024;
    private const BUDGET_SECONDS = 120;
    private const DECODE_FACTOR = 14;
    private const SERVICE_ACCOUNT = '/var/run/secrets/kubernetes.io/serviceaccount';
    private const NAMESPACE = '/^[a-z0-9]([-a-z0-9]{0,61}[a-z0-9])?$/';

    private readonly Retry $retry;
    private bool $truncated = false;
    /** @var array<string, array{int, list<array<mixed>>, bool}> expiry, items, whether the list was cut at the page cap */
    private array $memo = [];
    private int $requests = 0;
    private int $lastBytes = 0;
    private int $budgetUsed = 0;
    private ?int $budgetStart = null;
    private ?\Closure $stop = null;

    /**
     * @param array<string, string> $env KUBERNETES_SERVICE_HOST / _PORT (defaults to the process environment)
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly KubeSettings $settings,
        private readonly ClockInterface $clock,
        ?callable $sleeper = null,
        private readonly ?string $serviceAccountDir = null,
        private readonly array $env = [],
        private readonly int $maxBodyBytes = 16 * 1024 * 1024,
        private readonly int $maxRequests = 60,
        /** @var (\Closure(): array{0: int, 1: int})|null used bytes, limit bytes (-1 = none); default: this process */
        private readonly ?\Closure $memory = null,
        private readonly ?\Closure $afterResolve = null,
    ) {
        $this->retry = new Retry($sleeper, 30); // one slow answer must not hold a trigger for minutes
    }

    /**
     * Starts the budget of one ingest: at most `maxRequests` requests and two minutes. Every trigger reads the cluster before the
     * run gate and the lock, so a slow or looping API server must not be able to keep a trigger busy. `$stop` is the run guard
     * (deadline, memory): once it names a reason no further request starts.
     *
     * @param (\Closure(): ?string)|null $stop
     */
    public function beginBudget(?\Closure $stop = null): void
    {
        $this->stop = $stop;
        $this->budgetUsed = 0;
        $this->budgetStart = $this->clock->now()->getTimestamp();
    }

    /** True when the last list stopped at the page cap while the cluster had more. */
    public function lastTruncated(): bool
    {
        return $this->truncated;
    }

    public function memoSize(): int
    {
        return count($this->memo);
    }

    /** HTTP requests actually sent (memo hits excluded). */
    public function requests(): int
    {
        return $this->requests;
    }

    /**
     * @return list<array<mixed>> raw decoded items, parsed only by EventMapper's closed field list
     * @throws SourceUnavailable
     */
    public function listEvents(string $namespace): array
    {
        try {
            return $this->list('/apis/events.k8s.io/v1/namespaces/%s/events', $namespace, null, self::PAGE);
        } catch (SourceUnavailable $e) {
            if ($e->getMessage() !== 'kubernetes not found') {
                throw $e;
            }

            return $this->list('/api/v1/namespaces/%s/events', $namespace, null, self::PAGE); // clusters before 1.19
        }
    }

    /**
     * `check`: one GET with `limit=1` on events and one on pods of the namespace, nothing read from the response.
     *
     * @throws SourceUnavailable
     */
    public function probe(string $namespace): void
    {
        if (preg_match(self::NAMESPACE, $namespace) !== 1) {
            throw new InvalidArgumentException('invalid namespace');
        }
        try {
            $this->get(sprintf('/apis/events.k8s.io/v1/namespaces/%s/events', $namespace), ['limit' => '1']);
        } catch (SourceUnavailable $e) {
            if ($e->getMessage() !== 'kubernetes not found') {
                throw $e;
            }
            $this->get(sprintf('/api/v1/namespaces/%s/events', $namespace), ['limit' => '1']);
        }
        $this->get(sprintf('/api/v1/namespaces/%s/pods', $namespace), ['limit' => '1']);
    }

    /**
     * @return list<array<mixed>>
     * @throws SourceUnavailable
     */
    public function listPods(string $namespace, ?string $labelSelector): array
    {
        return $this->list('/api/v1/namespaces/%s/pods', $namespace, $labelSelector, self::POD_PAGE, true); // whole pod objects are large: smaller pages, each trimmed to the fields the mapper reads
    }

    /**
     * @return list<array<mixed>>
     */
    private function list(string $path, string $namespace, ?string $labelSelector, int $pageSize, bool $pods = false): array
    {
        if (preg_match(self::NAMESPACE, $namespace) !== 1) {
            throw new InvalidArgumentException('invalid namespace');
        }
        $path = sprintf($path, $namespace);
        $memoKey = $path . '?' . ($labelSelector ?? '');
        $now = $this->clock->now()->getTimestamp();
        if (isset($this->memo[$memoKey]) && $this->memo[$memoKey][0] > $now) {
            $this->truncated = $this->memo[$memoKey][2];

            return $this->memo[$memoKey][1];
        }

        $this->truncated = false;
        $items = [];
        $bytes = 0;
        $continue = null;
        for ($page = 1;; ++$page) {
            $query = ['limit' => (string) $pageSize];
            if ($labelSelector !== null) {
                $query['labelSelector'] = $labelSelector;
            }
            if ($continue !== null) {
                $query['continue'] = $continue;
            }
            $body = $this->get($path, $query);
            $bytes += $this->lastBytes;
            // A 200 that is not a list document (a gateway, an auth proxy, an aggregated API answering with a Status) is an unreadable
            // cluster, never an empty healthy one.
            if (!is_array($body['items'] ?? null) || ($body['kind'] ?? null) === 'Status') {
                throw new SourceUnavailable('kubernetes invalid response');
            }
            foreach ($body['items'] as $item) {
                if (is_array($item)) {
                    $items[] = $pods ? self::slimPod($item) : $item;
                }
            }
            $next = $body['metadata']['continue'] ?? null;
            if (!is_string($next) || $next === '') {
                break;
            }
            if ($page >= self::MAX_PAGES || $bytes >= self::MAX_LIST_BYTES) {
                $this->truncated = true;
                break;
            }
            $continue = $next;
        }
        $this->memo = array_filter($this->memo, static fn (array $m): bool => $m[0] > $now); // a long-lived worker keeps only the live lists
        $this->memo[$memoKey] = [$now + self::MEMO_SECONDS, $items, $this->truncated];

        return $items;
    }

    /**
     * @param array<string, string> $query
     * @return array<mixed>
     */
    private function get(string $path, array $query): array
    {
        [$base, $token, $verify] = $this->connection();
        if ($this->stop !== null && ($reason = ($this->stop)()) !== null) {
            throw new SourceUnavailable($reason === 'memory' ? 'kubernetes memory' : 'kubernetes timeout'); // its own code: an OOM condition is not a slow server
        }
        if ($this->budgetStart !== null && ($this->budgetUsed >= $this->maxRequests || $this->clock->now()->getTimestamp() - $this->budgetStart > self::BUDGET_SECONDS)) {
            throw new SourceUnavailable('kubernetes timeout');
        }
        ++$this->requests;
        ++$this->budgetUsed;
        $note = new class () {
            public bool $timedOut = false;
            public bool $unreachable = false;
        };
        $tripped = new \ArrayObject();
        try {
            // http_errors off: Guzzle's own exception text embeds the URL, which we never surface. No redirects: the bearer token
            // goes to the configured API server and nowhere else. The size cap is develop's ResponseLimit, as for Loki and Datadog.
            $response = $this->retry->send(function () use ($base, $path, $query, $token, $verify, $note, $tripped): ResponseInterface {
                try {
                    return $this->http->request('GET', $base . $path, [
                        'query' => $query,
                        'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $token],
                        'verify' => $verify,
                        'http_errors' => false,
                        'allow_redirects' => false,
                    ] + ResponseLimit::options($tripped, $this->maxBodyBytes));
                } catch (ConnectException $e) {
                    if (isset($tripped['large'])) {
                        throw new SourceUnavailable('response too large', true); // a curl handler wraps the sink's abort: not a timeout, not retried
                    }
                    // noted here, not read back from a chained cause; errno 6 (DNS) and 7 (refused) are a wrong or down host, not a slow one
                    // Guzzle 7 keeps the handler's context on the exception; Guzzle 8 does not, and there the cause stays a plain connection failure.
                    $errno = method_exists($e, 'getHandlerContext') ? ($e->getHandlerContext()['errno'] ?? null) : null;
                    if ($errno === 6 || $errno === 7) {
                        $note->unreachable = true;
                    } else {
                        $note->timedOut = true;
                    }
                    throw $e;
                }
            }, 'kubernetes', true);
        } catch (SourceUnavailable) {
            throw new SourceUnavailable(isset($tripped['large']) ? 'kubernetes response too large' : ($note->timedOut ? 'kubernetes timeout' : ($note->unreachable ? 'kubernetes unreachable' : 'kubernetes unavailable')));
        } catch (\Throwable) {
            // A missing CA bundle and the like are thrown by the HTTP handler as plain exceptions whose text names paths: say nothing of them.
            throw new SourceUnavailable(isset($tripped['large']) ? 'kubernetes response too large' : 'kubernetes unavailable');
        }
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new SourceUnavailable('kubernetes ' . match (true) {
                $status === 401, $status === 403 => 'unauthorised',
                $status === 404 => 'not found',
                $status === 429 => 'rate limited',
                default => 'unavailable',
            });
        }
        $this->lastBytes = $response->getBody()->getSize() ?? 0;
        $this->assertFitsInMemory($this->lastBytes);
        try {
            return ResponseLimit::decode($response, 'kubernetes', $this->maxBodyBytes);
        } catch (SourceUnavailable $e) {
            throw new SourceUnavailable($e->getMessage() === 'kubernetes response too large' ? $e->getMessage() : 'kubernetes invalid response');
        }
    }

    /**
     * json_decode of a Kubernetes list costs about thirteen times its text (measured): refuse, before decoding, a body whose decoded
     * form would take the process past 80% of memory_limit, with a code of its own.
     */
    private function assertFitsInMemory(int $bytes): void
    {
        [$used, $limit] = $this->memory === null
            ? [memory_get_usage(true), RunGuard::bytes((string) ini_get('memory_limit'))]
            : ($this->memory)();
        if ($limit > 0 && $used + self::DECODE_FACTOR * $bytes > (int) floor($limit * 0.8)) {
            throw new SourceUnavailable('kubernetes memory');
        }
    }

    /** A pod page reduced to the fields the mapper reads: no spec, env, annotations, managedFields or status detail survives the page. */
    /**
     * @param array<mixed> $pod
     * @return array<string, mixed>
     */
    private static function slimPod(array $pod): array
    {
        $meta = is_array($pod['metadata'] ?? null) ? $pod['metadata'] : [];
        $owners = [];
        foreach (is_array($meta['ownerReferences'] ?? null) ? $meta['ownerReferences'] : [] as $ref) {
            if (is_array($ref)) {
                $owners[] = ['kind' => $ref['kind'] ?? null, 'name' => $ref['name'] ?? null, 'controller' => $ref['controller'] ?? null];
            }
        }
        $statuses = [];
        foreach (is_array($pod['status']['containerStatuses'] ?? null) ? $pod['status']['containerStatuses'] : [] as $cs) {
            if (is_array($cs)) {
                $statuses[] = [
                    'name' => $cs['name'] ?? null,
                    'restartCount' => $cs['restartCount'] ?? null,
                    'lastState' => ['terminated' => ['reason' => $cs['lastState']['terminated']['reason'] ?? null, 'finishedAt' => $cs['lastState']['terminated']['finishedAt'] ?? null]],
                    'state' => ['running' => ['startedAt' => $cs['state']['running']['startedAt'] ?? null]],
                ];
            }
        }

        return [
            'metadata' => ['name' => $meta['name'] ?? null, 'namespace' => $meta['namespace'] ?? null, 'labels' => $meta['labels'] ?? [], 'ownerReferences' => $owners, 'creationTimestamp' => $meta['creationTimestamp'] ?? null],
            'spec' => ['nodeName' => $pod['spec']['nodeName'] ?? null],
            'status' => ['containerStatuses' => $statuses],
        ];
    }

    /**
     * The same rule as at load (KubeSettings::resolveTokenFile: the REAL target of a symlink must be a regular file inside the token's own
     * directory), then opens first and judges the same handle (no check-then-open race): the handle must be that file, safe, and at most 16 KB.
     * `n` opens non-blocking so a FIFO swapped in cannot hang us.
     */
    private function tokenFromFile(string $path): string
    {
        $fail = new SourceUnavailable('kubernetes credential unreadable'); // a reason code: neither the path nor the content
        $real = KubeSettings::resolveTokenFile($path);
        if ($real === null) {
            throw $fail;
        }
        if ($this->afterResolve !== null) {
            ($this->afterResolve)(); // test seam: lets a test swap a path component between the check and the open
        }
        $before = @lstat($real);
        $h = $before === false || ($before['mode'] & 0170000) !== 0100000 ? false : @fopen($real, 'rn');
        if ($before === false || $h === false) {
            throw $fail;
        }
        try {
            // Judge the opened handle, then the path once more: the file we hold must be the one the check approved, still reached by
            // the same real path, and nothing about it may have changed on the way.
            $st = fstat($h);
            $after = @stat($real);
            if ($st === false || $after === false || realpath($real) !== $real || KubeSettings::resolveTokenFile($path) !== $real
                || !KubeSettings::safeTokenStat($st, KubeSettings::effectiveUser())
                || $st['ino'] !== $before['ino'] || $st['dev'] !== $before['dev'] || $st['nlink'] !== $before['nlink']
                || $st['ino'] !== $after['ino'] || $st['dev'] !== $after['dev'] || $st['nlink'] !== $after['nlink']) {
                throw $fail;
            }
            $raw = fread($h, KubeSettings::MAX_TOKEN_BYTES + 1);
        } finally {
            fclose($h);
        }
        $token = is_string($raw) ? trim($raw) : '';
        if ($token === '' || strlen($token) > KubeSettings::MAX_TOKEN_BYTES) {
            throw $fail;
        }

        return $token;
    }

    /** @return array{string, string, string|true} base URL, token, CA path or true for the system store */
    private function connection(): array
    {
        $s = $this->settings;
        if (!$s->inCluster) {
            // Read per call: a projected or rotated token must be picked up without a restart. Fail closed on the CA: a bundle that
            // vanished or went empty is an error, never a fall back to the system store and never verify=false.
            if ($s->caFile !== null && !KubeSettings::certificateBundle($s->caFile)) {
                throw new SourceUnavailable('kubernetes credential unreadable');
            }

            return [(string) $s->server, $s->token() ?? $this->tokenFromFile((string) $s->tokenFile()), $s->caFile ?? true];
        }
        $dir = $this->serviceAccountDir ?? self::SERVICE_ACCOUNT;
        $host = $this->env['KUBERNETES_SERVICE_HOST'] ?? getenv('KUBERNETES_SERVICE_HOST');
        $port = $this->env['KUBERNETES_SERVICE_PORT'] ?? getenv('KUBERNETES_SERVICE_PORT');
        $token = @file_get_contents($dir . '/token'); // read per request: the projected token rotates
        if (!is_string($host) || $host === '' || !is_string($token) || trim($token) === '') {
            throw new SourceUnavailable('kubernetes unavailable');
        }
        $host = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $port = is_string($port) && $port !== '' && $port !== '443' ? ':' . $port : '';

        if (!is_file($dir . '/ca.crt') || !is_readable($dir . '/ca.crt')) {
            throw new SourceUnavailable('kubernetes unavailable'); // fail closed: never send the token to a host trusted by the public store
        }

        return ['https://' . $host . $port, trim($token), $dir . '/ca.crt'];
    }
}
