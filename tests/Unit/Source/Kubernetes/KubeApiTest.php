<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Source\Kubernetes;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Watcher\Source\Kubernetes\KubeApi;
use Watcher\Source\Kubernetes\KubeSettings;
use Watcher\Source\SourceUnavailable;
use Watcher\Tests\Fakes\FrozenClock;

final class KubeApiTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../Fixtures/kubernetes/';
    private const TOKEN = 'BEARER-TEST-TOKEN-0123456789';

    /** @var list<array{request: RequestInterface, options: array<string, mixed>}> */
    private array $history = [];
    private FrozenClock $clock;

    private string $ca = '';

    protected function setUp(): void
    {
        $this->ca = sys_get_temp_dir() . '/kube-api-ca-' . bin2hex(random_bytes(8)) . '.crt';
        file_put_contents($this->ca, "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n");
        $this->history = [];
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-24T06:00:00Z'));
    }

    protected function tearDown(): void
    {
        @unlink($this->ca);
    }

    private function fixture(string $name, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) file_get_contents(self::DIR . $name));
    }

    /**
     * @param list<Response|\Throwable> $queue
     * @param array<string, mixed>|null $api
     * @param array<string, string> $env
     */
    private function api(array $queue, ?array $api = null, ?string $saDir = null, array $env = [], int $maxBody = 16 * 1024 * 1024, int $maxRequests = 60): KubeApi
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $historyLog = [];
        $this->history = &$historyLog; // Guzzle fills it by reference
        $stack->push(Middleware::history($historyLog));
        $settings = KubeSettings::fromArray(['namespaces' => ['shop'], 'api' => $api ?? ['server' => 'https://k8s.example:6443', 'token' => self::TOKEN, 'ca_file' => $this->ca]], true, true, false);

        return new KubeApi(new Client(['handler' => $stack]), $settings, $this->clock, static function (int $s): void {
        }, $saDir, $env, $maxBody, $maxRequests);
    }

    public function testListsEventsFromTheEventsApiWithBearerAndCa(): void
    {
        $items = $this->api([$this->fixture('events-v1.json')])->listEvents('shop');

        self::assertCount(3, $items);
        self::assertCount(1, $this->history);
        $r = $this->history[0]['request'];
        self::assertSame('GET', $r->getMethod());
        self::assertSame('/apis/events.k8s.io/v1/namespaces/shop/events', $r->getUri()->getPath());
        self::assertSame('limit=500', $r->getUri()->getQuery());
        self::assertSame('Bearer ' . self::TOKEN, $r->getHeaderLine('Authorization'));
        self::assertSame($this->ca, $this->history[0]['options']['verify']);
    }

    public function testFallsBackToCoreEventsWhenTheEventsApiIsMissing(): void
    {
        $items = $this->api([new Response(404, [], '{}'), $this->fixture('events-core.json')])->listEvents('shop');

        self::assertCount(22, $items);
        self::assertSame('/api/v1/namespaces/shop/events', $this->history[1]['request']->getUri()->getPath());
    }

    public function testFollowsTheContinueTokenAndSendsTheSelector(): void
    {
        $items = $this->api([$this->fixture('pods.json'), $this->fixture('pods-page2.json')])->listPods('shop', 'app in (api,bff)');

        self::assertCount(10, $items);
        self::assertSame('/api/v1/namespaces/shop/pods', $this->history[0]['request']->getUri()->getPath());
        parse_str($this->history[0]['request']->getUri()->getQuery(), $q0);
        parse_str($this->history[1]['request']->getUri()->getQuery(), $q1);
        self::assertSame('app in (api,bff)', $q0['labelSelector']);
        self::assertArrayNotHasKey('continue', $q0);
        self::assertSame('ENCODED-CONTINUE-TOKEN', $q1['continue']);
    }

    public function testEveryRequestIsAGet(): void
    {
        $api = $this->api([$this->fixture('events-v1.json'), $this->fixture('pods.json'), $this->fixture('pods-page2.json')]);
        $api->listEvents('shop');
        $api->listPods('shop', null);

        foreach ($this->history as $h) {
            self::assertSame('GET', $h['request']->getMethod());
        }
    }

    /** @return array<string, array{int, string}> */
    public static function failures(): array
    {
        return [
            'http 401' => [401, 'unauthorised'],
            'http 403' => [403, 'unauthorised'],
            'http 404' => [404, 'not found'],
            'http 500' => [500, 'unavailable'],
            'http 503' => [503, 'unavailable'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testFailureClassesCarryNeitherUrlNorToken(int $status, string $class): void
    {
        $responses = array_fill(0, 4, new Response($status, [], '{"message":"secret body ' . self::TOKEN . '"}'));
        try {
            $this->api($status === 404 ? [new Response(404, [], 'x'), new Response(404, [], 'x')] : $responses)->listEvents('shop');
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertSame('kubernetes ' . $class, $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringNotContainsString('k8s.example', $e->getMessage());
        }
    }

    public function testRateLimitIsRetriedThenReported(): void
    {
        $items = $this->api([new Response(429, ['Retry-After' => '1'], ''), $this->fixture('events-v1.json')])->listEvents('shop');
        self::assertCount(3, $items);

        $this->history = [];
        try {
            $this->api(array_fill(0, 3, new Response(429, ['Retry-After' => '1'], '')))->listEvents('shop');
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertSame('kubernetes rate limited', $e->getMessage());
        }
    }

    public function testTransportAndBodyErrors(): void
    {
        try {
            $this->api(array_fill(0, 3, new ConnectException('boom https://k8s.example ' . self::TOKEN, new Request('GET', 'https://k8s.example'))))->listEvents('shop');
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertSame('kubernetes timeout', $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
        try {
            $this->api([new Response(200, [], 'not json')])->listEvents('shop');
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertSame('kubernetes invalid response', $e->getMessage());
        }
    }

    public function testListsAreMemoisedForAMinuteOnTheInjectedClock(): void
    {
        $api = $this->api([$this->fixture('events-v1.json'), $this->fixture('events-v1.json')]);
        $api->listEvents('shop');
        $this->clock->advance('+59 seconds');
        $api->listEvents('shop');
        self::assertCount(1, $this->history);

        $this->clock->advance('+2 seconds');
        $api->listEvents('shop');
        self::assertCount(2, $this->history);
    }

    public function testADifferentSelectorIsADifferentRequest(): void
    {
        $api = $this->api([$this->fixture('pods-page2.json'), $this->fixture('pods-page2.json')]);
        $api->listPods('shop', 'app=api');
        $api->listPods('shop', 'app=api');
        $api->listPods('shop', 'app=bff');

        self::assertCount(2, $this->history);
        self::assertSame(2, $api->requests());
    }

    public function testTruncationIsRememberedWithTheCachedList(): void
    {
        $page = static fn (): Response => new Response(200, [], json_encode(['items' => [], 'metadata' => ['continue' => 'more']], JSON_THROW_ON_ERROR));
        $api = $this->api([...array_fill(0, KubeApi::MAX_PAGES, $page()), $this->fixture('pods-page2.json')]);
        $api->listEvents('shop');
        self::assertTrue($api->lastTruncated());

        $api->listPods('shop', null); // a complete list in between resets nothing it should not
        self::assertFalse($api->lastTruncated());
        $api->listEvents('shop'); // served from the memo, and still known to be cut
        self::assertTrue($api->lastTruncated());
        self::assertCount(KubeApi::MAX_PAGES + 1, $this->history);
    }

    public function testAnythingThrownByTheHttpClientBecomesTheFixedVocabulary(): void
    {
        try {
            $this->api([new \RuntimeException('SSL CA bundle not found: /secret/path ' . self::TOKEN)])->listEvents('shop');
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertSame('kubernetes unavailable', $e->getMessage());
        }
    }

    public function testNamespaceIsValidatedBeforeAnythingIsSent(): void
    {
        $api = $this->api([]);
        foreach (['../x', 'a/b', 'Shop', '', 'a?b=c'] as $bad) {
            $this->expectsRejection(static fn () => $api->listEvents($bad));
        }
        self::assertSame([], $this->history);
    }

    public function testInClusterCredentialsAreReadFromTheServiceAccountDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/kube-sa-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/token', "SA-TOKEN-VALUE\n");
        file_put_contents($dir . '/ca.crt', 'x');
        try {
            $api = $this->api([$this->fixture('events-v1.json')], ['in_cluster' => true], $dir, ['KUBERNETES_SERVICE_HOST' => '10.96.0.1', 'KUBERNETES_SERVICE_PORT' => '443']);
            $api->listEvents('shop');
            $r = $this->history[0]['request'];
            self::assertSame('https://10.96.0.1/apis/events.k8s.io/v1/namespaces/shop/events?limit=500', (string) $r->getUri());
            self::assertSame('Bearer SA-TOKEN-VALUE', $r->getHeaderLine('Authorization'));
            self::assertSame($dir . '/ca.crt', $this->history[0]['options']['verify']);
        } finally {
            @unlink($dir . '/token');
            @unlink($dir . '/ca.crt');
            @rmdir($dir);
        }
    }

    public function testRedirectsAreNeverFollowed(): void
    {
        try {
            $this->api([new Response(302, ['Location' => 'https://elsewhere.example/'], '')])->listEvents('shop');
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertSame('kubernetes unavailable', $e->getMessage());
        }
        self::assertFalse($this->history[0]['options']['allow_redirects']);
        self::assertCount(1, $this->history);
    }

    public function testInClusterWithoutACaBundleFailsClosedInsteadOfUsingTheSystemStore(): void
    {
        $dir = sys_get_temp_dir() . '/kube-sa-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/token', 'SA-TOKEN-VALUE');
        try {
            $this->api([$this->fixture('events-v1.json')], ['in_cluster' => true], $dir, ['KUBERNETES_SERVICE_HOST' => '10.96.0.1']);
            $api = $this->api([$this->fixture('events-v1.json')], ['in_cluster' => true], $dir, ['KUBERNETES_SERVICE_HOST' => '10.96.0.1']);
            $api->listEvents('shop');
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertSame('kubernetes unavailable', $e->getMessage());
            self::assertSame([], $this->history, 'the token must not be sent anywhere');
        } finally {
            @unlink($dir . '/token');
            @rmdir($dir);
        }
    }

    public function testAnOversizedBodyIsRefusedBeforeItIsDecoded(): void
    {
        $api = $this->api([new Response(200, [], str_repeat(' ', 2000) . '{"items":[]}')], null, null, [], 1000);

        $this->expectException(SourceUnavailable::class);
        $this->expectExceptionMessage('kubernetes response too large');
        $api->listEvents('shop');
    }

    /**
     * @param array<string, string> $headers
     * @return array{KubeApi, \stdClass} an API whose handler streams `$chunks` of `$size` bytes into the sink, as curl would */
    private function streaming(int $chunks, int $size, array $headers = [], bool $gzip = false, int $cap = 1048576): array
    {
        $probe = new \stdClass();
        $probe->written = 0;
        $probe->decode = null;
        $handler = function (RequestInterface $r, array $o) use ($chunks, $size, $headers, $gzip, $probe) {
            $probe->decode = $o['decode_content'] ?? null;
            $response = new Response(200, $headers);
            if (isset($o['on_headers'])) {
                $o['on_headers']($response);
            }
            $sink = $o['sink'];
            $ctx = inflate_init(ZLIB_ENCODING_GZIP);
            self::assertNotFalse($ctx);
            $zip = $gzip ? (string) gzencode(str_repeat("\0", $chunks * $size), 9) : '';
            for ($i = 0; $i < $chunks; ++$i) {
                // gzip: a 1 KiB slice of compressed zeros inflates to about a megabyte, like curl's decoder would hand over
                $piece = $gzip ? (string) inflate_add($ctx, substr($zip, $i * 1024, 1024)) : str_repeat('x', $size);
                $probe->written += strlen($piece);
                $sink->write($piece);
            }

            return \GuzzleHttp\Promise\Create::promiseFor($response);
        };
        $settings = KubeSettings::fromArray(['namespaces' => ['shop'], 'api' => ['server' => 'https://k8s.example:6443', 'token' => self::TOKEN, 'ca_file' => $this->ca]], true, true, false);

        return [new KubeApi(new Client(['handler' => HandlerStack::create($handler)]), $settings, $this->clock, static function (int $s): void {
        }, null, [], $cap), $probe];
    }

    public function testADeclaredContentLengthOverTheCapIsRefusedBeforeAnyBodyIsRead(): void
    {
        [$api, $probe] = $this->streaming(1000, 65536, ['Content-Length' => '999999999']);
        $before = memory_get_peak_usage();

        try {
            $api->listEvents('shop');
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertSame('kubernetes response too large', $e->getMessage());
        }
        self::assertSame(0, $probe->written, 'on_headers aborted before the first body byte');
        self::assertLessThan(8 * 1048576, memory_get_peak_usage() - $before);
    }

    public function testAChunkedBodyWithoutContentLengthIsCutAtTheCapWhileStreaming(): void
    {
        [$api, $probe] = $this->streaming(20000, 65536); // 1.3 GB if it were ever read
        $before = memory_get_peak_usage();

        try {
            $api->listEvents('shop');
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertSame('kubernetes response too large', $e->getMessage());
        }
        self::assertLessThanOrEqual(1048576 + 65536, $probe->written, 'the write that crossed the cap is the last one');
        self::assertLessThan(8 * 1048576, memory_get_peak_usage() - $before);
    }

    public function testAGzipBombIsCountedAfterDecodingAndNeverGrowsPastTheCap(): void
    {
        [$api, $probe] = $this->streaming(300, 1048576, ['Content-Encoding' => 'gzip'], true); // 300 MB inflated
        $before = memory_get_peak_usage();

        try {
            $api->listEvents('shop');
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertSame('kubernetes response too large', $e->getMessage());
        }
        self::assertTrue($probe->decode, 'the client must be asked to decode, so the cap counts decoded bytes');
        self::assertLessThanOrEqual(3 * 1048576, $probe->written);
    }

    public function testAJsonNestedPastTheDepthLimitIsAnInvalidResponse(): void
    {
        $deep = '{"items":' . str_repeat('[', 100) . str_repeat(']', 100) . '}';

        $this->expectException(SourceUnavailable::class);
        $this->expectExceptionMessage('kubernetes invalid response');
        $this->api([new Response(200, [], $deep)])->listEvents('shop');
    }

    public function testAnIngestBudgetStopsTheReadsAndNamesTheCause(): void
    {
        $page = static fn (): Response => new Response(200, [], json_encode(['items' => [], 'metadata' => ['continue' => 'more']], JSON_THROW_ON_ERROR));
        $api = $this->api(array_fill(0, 10, $page()), null, null, [], 1000, 3);
        $api->beginBudget();

        try {
            $api->listEvents('shop');
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertSame('kubernetes timeout', $e->getMessage());
        }
        self::assertCount(3, $this->history);
    }

    public function testTheRunGuardEndsTheIngestBeforeTheNextRequest(): void
    {
        $page = static fn (): Response => new Response(200, [], json_encode(['items' => [], 'metadata' => ['continue' => 'more']], JSON_THROW_ON_ERROR));
        $api = $this->api(array_fill(0, 10, $page()), null, null, [], 1000, 60);
        $reason = null;
        $api->beginBudget(function () use (&$reason): ?string {
            return $reason;
        });

        $reason = 'deadline'; // the run's deadline passed: not one request may start
        try {
            $api->listEvents('shop');
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertSame('kubernetes timeout', $e->getMessage());
        }
        self::assertCount(0, $this->history);
    }

    public function testAMemoryStopIsReportedAsMemoryNotAsATimeout(): void
    {
        $api = $this->api([new Response(200, [], '{"items":[]}')]);
        $api->beginBudget(static fn (): string => 'memory');

        try {
            $api->listEvents('shop');
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertSame('kubernetes memory', $e->getMessage());
        }
        self::assertCount(0, $this->history);

        $api->beginBudget(static fn (): string => 'deadline');
        $this->expectExceptionMessage('kubernetes timeout');
        $api->listEvents('shop');
    }

    public function testABodyWhoseDecodedSizeWouldNotFitInMemoryIsRefusedBeforeJsonDecode(): void
    {
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], str_repeat(' ', 2000) . '{"items":[]}')]));
        $historyLog = [];
        $this->history = &$historyLog; // Guzzle fills it by reference
        $stack->push(Middleware::history($historyLog));
        $settings = KubeSettings::fromArray(['namespaces' => ['shop'], 'api' => ['server' => 'https://k8s.example:6443', 'token' => self::TOKEN, 'ca_file' => $this->ca]], true, true, false);
        // 40 KB of room, 2 KB of body: json_decode costs about thirteen times the text.
        $api = new KubeApi(new Client(['handler' => $stack]), $settings, $this->clock, static function (int $s): void {
        }, null, [], 16 * 1048576, 60, static fn (): array => [10_000, 40_000]);

        try {
            $api->listEvents('shop');
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertSame('kubernetes memory', $e->getMessage());
        }
    }

    public function testPodsAreKeptSlimWhateverTheClusterSent(): void
    {
        $pod = json_decode((string) file_get_contents(__DIR__ . '/../../../Fixtures/kubernetes/pods.json'), true, flags: JSON_THROW_ON_ERROR)['items'][0];
        $pod['spec']['containers'] = [['name' => 'x', 'env' => [['name' => 'DB_PASSWORD', 'value' => 'hunter2-prod']]]];
        $api = $this->api([new Response(200, [], json_encode(['items' => [$pod]], JSON_THROW_ON_ERROR))]);

        $kept = $api->listPods('shop', null)[0];

        self::assertStringNotContainsString('hunter2-prod', json_encode($kept, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('annotations', $kept['metadata']);
        self::assertArrayNotHasKey('managedFields', $kept['metadata']);
        self::assertSame($pod['metadata']['name'], $kept['metadata']['name']);
        self::assertSame($pod['metadata']['ownerReferences'][0]['name'], $kept['metadata']['ownerReferences'][0]['name']);
    }

    public function testARefusedOrUnresolvableConnectionIsNotCalledATimeout(): void
    {
        if ((new \ReflectionMethod(ConnectException::class, '__construct'))->getNumberOfParameters() < 4) {
            self::markTestSkipped('Guzzle 8 no longer carries the handler context (errno) on the exception');
        }
        foreach ([6 => 'kubernetes unreachable', 7 => 'kubernetes unreachable', 28 => 'kubernetes timeout'] as $errno => $expected) {
            $e = (new \ReflectionClass(ConnectException::class))->newInstanceArgs(['boom', new Request('GET', 'https://k8s.example'), null, ['errno' => $errno]]);
            try {
                $this->api(array_fill(0, 3, $e))->listEvents('shop');
                self::fail('expected SourceUnavailable');
            } catch (SourceUnavailable $x) {
                self::assertSame($expected, $x->getMessage(), (string) $errno);
            }
        }
    }

    public function testExpiredListMemosAreDroppedSoALongLivedWorkerKeepsOnlyTheLiveOnes(): void
    {
        $api = $this->api([new Response(200, [], '{"items":[]}'), new Response(200, [], '{"items":[]}')]);
        $api->listEvents('shop');
        $this->clock->advance('+2 minutes');
        $api->listEvents('shop');

        self::assertSame(1, $api->memoSize());
    }

    /** @return array<string, array{string}> */
    public static function notAList(): array
    {
        return ['empty object' => ['{}'], 'status document' => ['{"kind":"Status","status":"Failure","message":"x"}'], 'null items' => ['{"items":null}'],
            'string items' => ['{"items":"x"}'], 'bare list' => ['[]'], 'status with empty items' => ['{"kind":"Status","items":[]}']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notAList')]
    public function testA200AnswerThatIsNotAListIsAnUnreadableClusterNotAnEmptyOne(string $body): void
    {
        foreach (['listEvents', 'listPods'] as $call) {
            $api = $this->api([new Response(200, [], $body)]);
            try {
                $call === 'listEvents' ? $api->listEvents('shop') : $api->listPods('shop', null);
                self::fail('expected SourceUnavailable');
            } catch (SourceUnavailable $e) {
                self::assertSame('kubernetes invalid response', $e->getMessage(), $call);
            }
        }
    }

    public function testAnEmptyItemsListIsALegitimateEmptyCluster(): void
    {
        self::assertSame([], $this->api([new Response(200, [], '{"kind":"EventList","items":[]}')])->listEvents('shop'));
        self::assertSame([], $this->api([new Response(200, [], '{"items":{}}')])->listPods('shop', null));
    }

    public function testATimeoutIsRecognisedEvenWhenRetryDoesNotChainTheCause(): void
    {
        // The detection must not rely on getPrevious() (main's Retry stopped chaining Guzzle exceptions).
        try {
            $this->api(array_fill(0, 3, new ConnectException('boom', new Request('GET', 'https://k8s.example'))))->listEvents('shop');
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertSame('kubernetes timeout', $e->getMessage());
        }
    }

    public function testInClusterWithoutACluster(): void
    {
        $this->expectException(SourceUnavailable::class);
        $this->expectExceptionMessage('kubernetes unavailable');
        $this->api([], ['in_cluster' => true], '/nonexistent-dir', [])->listEvents('shop');
    }

    private function expectsRejection(\Closure $call): void
    {
        $rejected = false;
        try {
            $call();
        } catch (InvalidArgumentException) {
            $rejected = true;
        }
        self::assertTrue($rejected);
    }
}
