<?php

declare(strict_types=1);

namespace Watcher\Metrics;

use Closure;
use DateTimeImmutable;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use Watcher\Config\InvalidConfig;
use Watcher\Config\SecureUrl;
use Watcher\Source\GuardedRequest;
use Watcher\Source\Retry;
use Watcher\Source\Slug;
use Watcher\Source\SourceUnavailable;

/**
 * Reads numbers from the Prometheus HTTP API, directly or through the Grafana datasource proxy. Read-only: only the
 * two query paths, GET (or a form POST of the same query when the URL would be long). Labels are dropped here, at the
 * boundary; only allowlisted label names survive, as at most 5 distinct raw values. Failures carry a fixed token,
 * never a URL, token, query or body.
 *
 * UNVERIFIED: the Grafana proxy path shape (`/api/datasources/proxy/uid/<uid>`); pinned by a request-line test, not a live instance.
 */
final class PrometheusSource implements MetricSource
{
    private const INSTANT = '/api/v1/query';
    private const RANGE = '/api/v1/query_range';
    private const PROXY = '/api/datasources/proxy/uid/';
    private const MAX_REQUEST_LINE = 2000;
    private const MAX_LABEL_VALUES = 5;

    private readonly string $prefix;
    /** @var array<string, string> */
    private readonly array $headers;
    private readonly GuardedRequest $request;

    /**
     * @param string|null  $datasourceUid set = Grafana proxy mode
     * @param list<string> $labelAllowlist label NAMES whose values may be shown
     */
    public function __construct(
        ClientInterface $http,
        string $baseUrl,
        ?string $bearerToken = null,
        ?string $datasourceUid = null,
        private readonly array $labelAllowlist = [],
        private readonly int $maxPoints = 500,
        ?callable $sleeper = null,
        int $retryBudget = 120,
        bool $allowInsecure = false,
        int $budgetSeconds = 60,
        ?Closure $stop = null,
        ?callable $now = null,
    ) {
        if (($problem = SecureUrl::problem($baseUrl, $allowInsecure, true)) !== null) {
            throw new InvalidConfig('metrics.base_url ' . $problem);
        }
        if ($datasourceUid !== null && !Slug::valid($datasourceUid)) {
            throw new InvalidConfig('metrics.datasource_uid must be letters, digits, _ . - and not only dots');
        }
        $this->prefix = rtrim($baseUrl, '/') . ($datasourceUid === null ? '' : self::PROXY . rawurlencode($datasourceUid));
        $this->headers = ['Accept' => 'application/json'] + ($bearerToken === null || $bearerToken === '' ? [] : ['Authorization' => 'Bearer ' . $bearerToken]);
        $this->request = new GuardedRequest($http, new Retry($sleeper, $retryBudget), 'metrics', $budgetSeconds, $stop, $now);
    }

    public function instant(string $query, DateTimeImmutable $at): MetricResult
    {
        return $this->parse($this->call(self::INSTANT, ['query' => $query, 'time' => (string) $at->getTimestamp()]), false);
    }

    public function range(string $query, DateTimeImmutable $start, DateTimeImmutable $end, int $stepSeconds): MetricResult
    {
        $span = $end->getTimestamp() - $start->getTimestamp();
        if ($stepSeconds < 1 || $span / $stepSeconds > $this->maxPoints) {
            throw new InvalidArgumentException('Metric range would return more than max_points samples.');
        }

        return $this->parse($this->call(self::RANGE, [
            'query' => $query,
            'start' => (string) $start->getTimestamp(),
            'end' => (string) $end->getTimestamp(),
            'step' => (string) $stepSeconds,
        ]), true);
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<mixed>
     */
    private function call(string $path, array $params): array
    {
        $url = $this->prefix . $path;
        $post = strlen($url) + strlen(http_build_query($params)) + 1 > self::MAX_REQUEST_LINE;
        $this->request->begin();
        [$data] = $this->request->send($post ? 'POST' : 'GET', $url, [($post ? 'form_params' : 'query') => $params, 'headers' => $this->headers]);
        if (($data['status'] ?? null) !== 'success' || !is_array($data['data'] ?? null)) {
            throw new SourceUnavailable('bad_response');
        }

        return $data['data'];
    }

    /** @param array<mixed> $data */
    private function parse(array $data, bool $matrix): MetricResult
    {
        $result = $data['result'] ?? null;
        $type = $data['resultType'] ?? null;
        if (!is_array($result)) {
            throw new SourceUnavailable('bad_response');
        }
        if ($type === 'scalar') {
            $result = [['metric' => [], 'value' => $result]];
        } elseif ($type !== ($matrix ? 'matrix' : 'vector')) {
            throw new SourceUnavailable('bad_response');
        }

        $values = [];
        $stamps = [];
        $labels = [];
        foreach ($result as $series) {
            if (!is_array($series)) {
                throw new SourceUnavailable('bad_response');
            }
            foreach ($this->labelAllowlist as $name) {
                $v = $series['metric'][$name] ?? null;
                if (is_string($v) && !in_array($v, $labels, true) && count($labels) < self::MAX_LABEL_VALUES) {
                    $labels[] = $v;
                }
            }
            $samples = $matrix ? ($series['values'] ?? []) : [$series['value'] ?? []];
            foreach (is_array($samples) ? $samples : [] as $sample) {
                $n = is_array($sample) ? ($sample[1] ?? null) : null;
                if (is_array($sample) && !is_numeric($sample[0] ?? null)) {
                    continue; // a sample without a numeric timestamp is malformed: skipped, never guessed
                }
                if ((is_string($n) || is_int($n) || is_float($n)) && is_numeric($n) && is_finite((float) $n)) {
                    $values[] = (float) $n;
                    $stamps[] = (int) $sample[0];
                }
            }
        }
        $count = count($result);
        // range is single series only: with several, the reader reports ambiguity from `series`, so samples are not kept
        if ($matrix && $count > 1) {
            return new MetricResult([], $count, $labels);
        }

        return new MetricResult($values, $count, $labels, $matrix ? $stamps : []);
    }
}
