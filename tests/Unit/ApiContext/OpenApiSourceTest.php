<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\ApiContext;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\InflateStream;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Watcher\ApiContext\OpenApiSource;
use Watcher\Source\SourceUnavailable;

final class OpenApiSourceTest extends TestCase
{
    private const URL = 'https://docs.internal.example/openapi.json?secret=URLSECRET';
    private const DOC = '{"openapi":"3.0.0","paths":{}}';

    /** @var list<array{request: RequestInterface, options: array<string, mixed>}> */
    private array $history = [];

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/watcher-api-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        self::remove($this->dir);
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (glob($path . '/*') ?: [] as $f) {
            self::remove($f);
        }
        @rmdir($path);
    }

    /** @param list<Response|\Throwable> $queue */
    private function http(array $queue): Client
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $log = [];
        $this->history = &$log; // Guzzle fills it by reference
        $stack->push(Middleware::history($log));

        return new Client(['handler' => $stack]);
    }

    /** @param list<Response|\Throwable> $queue */
    private function url(array $queue, ?string $token = null, int $max = 1048576): OpenApiSource
    {
        return new OpenApiSource($this->http($queue), self::URL, $token, $max);
    }

    private function file(string $content): string
    {
        $p = $this->dir . '/doc.json';
        file_put_contents($p, $content);

        return $p;
    }

    /** Peak memory is monotonic: reset it so the assertion measures the read, not the test's own fixtures. */
    private function resetPeak(): int
    {
        if (!function_exists('memory_reset_peak_usage')) {
            self::markTestSkipped('memory_reset_peak_usage() needs PHP 8.2');
        }
        memory_reset_peak_usage();

        return memory_get_usage();
    }

    private function token(callable $fn): string
    {
        try {
            $fn();
        } catch (SourceUnavailable $e) {
            return $e->getMessage();
        }
        self::fail('expected SourceUnavailable');
    }

    public function testReadsAFile(): void
    {
        $src = new OpenApiSource($this->http([]), $this->file(self::DOC), null);
        self::assertSame(['openapi' => '3.0.0', 'paths' => []], $src->read());
    }

    public function testFileFailures(): void
    {
        $http = $this->http([]);
        self::assertSame('unreadable', $this->token(fn () => (new OpenApiSource($http, $this->dir . '/missing.json', null))->read()));
        self::assertSame('unreadable', $this->token(fn () => (new OpenApiSource($http, $this->dir, null))->read()), 'a directory');
        self::assertSame('too_large', $this->token(fn () => (new OpenApiSource($http, $this->file(str_repeat(' ', 100) . self::DOC), null, 64))->read()));
        foreach (['php://stdin', 'phar:///x.phar/doc.json', 'data:text/plain,{}', 'ftp://h/x.json'] as $bad) {
            self::assertSame('unreadable', $this->token(fn () => (new OpenApiSource($http, $bad, null))->read()), $bad);
        }
        self::assertSame([], $this->history, 'a refused source never reaches the network');
    }

    public function testUrlIsOneGetWithJsonAcceptAndNoBody(): void
    {
        self::assertSame(['openapi' => '3.0.0', 'paths' => []], $this->url([new Response(200, [], self::DOC)])->read());

        self::assertCount(1, $this->history);
        $r = $this->history[0]['request'];
        self::assertSame('GET', $r->getMethod());
        self::assertSame('application/json', $r->getHeaderLine('Accept'));
        self::assertSame('', (string) $r->getBody());
        self::assertFalse($r->hasHeader('Authorization'), 'no token configured, none sent');
        $o = $this->history[0]['options'];
        self::assertFalse($o['allow_redirects'], 'a redirect is never followed');
        self::assertTrue($o['verify'], 'TLS verification is asserted, not left to the injected client');
        self::assertTrue($o['decode_content']);
    }

    public function testBearerOnlyWhenConfigured(): void
    {
        $this->url([new Response(200, [], self::DOC)], 'TOKEN123')->read();
        self::assertSame('Bearer TOKEN123', $this->history[0]['request']->getHeaderLine('Authorization'));
    }

    public function testHttpFailureTokens(): void
    {
        $req = new Request('GET', self::URL);
        $cases = [
            'unauthorized' => new Response(401),
            'unauthorized ' => new Response(403),
            'unreachable' => new Response(503),
            'unreachable ' => new Response(404),
            'timeout' => new ConnectException('cURL error 28: Operation timed out after 15000 milliseconds', $req, null, ['errno' => 28]),
            'unreachable  ' => new ConnectException('cURL error 7: Failed to connect', $req, null, ['errno' => 7]),
        ];
        foreach ($cases as $expected => $outcome) {
            $this->history = [];
            self::assertSame(trim($expected), $this->token(fn () => $this->url([$outcome], 'TOKEN123')->read()));
        }
    }

    public function testARedirectIsNeverFollowed(): void
    {
        $queue = [new Response(302, ['Location' => 'https://169.254.169.254/latest/meta-data']), new Response(200, [], self::DOC)];
        self::assertSame('unreachable', $this->token(fn () => $this->url($queue)->read()));
        self::assertCount(1, $this->history, 'the second hop is never requested');
    }

    public function testContentLengthOverTheCapFailsBeforeReading(): void
    {
        $r = new Response(200, ['Content-Length' => '5000'], self::DOC);
        self::assertSame('too_large', $this->token(fn () => $this->url([$r], null, 1000)->read()));
    }

    public function testBodyOverTheCapFailsEvenWithoutContentLength(): void
    {
        $r = new Response(200, [], self::DOC . str_repeat(' ', 5000));
        self::assertSame('too_large', $this->token(fn () => $this->url([$r], null, 1000)->read()));
    }

    public function testYamlIsRefusedFast(): void
    {
        $t = microtime(true);
        foreach (["openapi: 3.0.0\npaths: {}\n", "---\nopenapi: 3.0.0", "# comment\nopenapi: 3.0.0", "\n  swagger: '2.0'"] as $yaml) {
            self::assertSame('unsupported_format', $this->token(fn () => $this->url([new Response(200, [], $yaml)])->read()));
        }
        self::assertLessThan(1.0, microtime(true) - $t);
    }

    public function testMalformedJsonAndNonObject(): void
    {
        self::assertSame('bad_format', $this->token(fn () => $this->url([new Response(200, [], '{"openapi":')])->read()));
        self::assertSame('bad_format', $this->token(fn () => $this->url([new Response(200, [], '')])->read()));
    }

    public function testNestingBombIsRefusedQuicklyAndCheaply(): void
    {
        $bomb = '{"a":' . str_repeat('[', 1_000_000) . str_repeat(']', 1_000_000) . '}';
        self::assertGreaterThan(2_000_000, strlen($bomb));
        $before = $this->resetPeak();
        $t = microtime(true);
        $token = $this->token(fn () => $this->url([new Response(200, [], $bomb)], null, 8_388_608)->read());
        self::assertSame('bad_format', $token);
        self::assertLessThan(1.0, microtime(true) - $t);
        self::assertLessThan(32 * 1048576, memory_get_peak_usage() - $before, 'research R1');
    }

    public function testFlatDocumentAtTheDefaultCapStaysUnderBudget(): void
    {
        $flat = '{"openapi":"3.0.0","paths":{},"x":[' . implode(',', array_fill(0, 500_000, '1')) . ']}';
        self::assertLessThanOrEqual(OpenApiSource::DEFAULT_MAX_BYTES, strlen($flat));
        $src = new OpenApiSource($this->http([new Response(200, [], $flat)]), self::URL, null);
        $t = microtime(true);
        $before = $this->resetPeak();
        $src->read();
        self::assertLessThan(1.0, microtime(true) - $t);
        self::assertLessThan(32 * 1048576, memory_get_peak_usage() - $before);
    }

    public function testNoUrlPathOrTokenInAnyMessage(): void
    {
        $req = new Request('GET', self::URL);
        $outcomes = [new Response(401), new Response(500), new ConnectException('cURL error 7 for ' . self::URL . ' TOKEN123', $req), new Response(200, [], 'openapi: x')];
        foreach ($outcomes as $o) {
            $msg = $this->token(fn () => $this->url([$o], 'TOKEN123')->read());
            self::assertMatchesRegularExpression('/^[a-z_]+$/', $msg);
        }
        $path = $this->dir . '/missing-SECRETDIR.json';
        self::assertDoesNotMatchRegularExpression('/SECRETDIR/', $this->token(fn () => (new OpenApiSource($this->http([]), $path, null))->read()));
    }

    public function testSourceIdIsAShortHashOfTheSource(): void
    {
        $a = $this->url([]);
        self::assertSame(substr(hash('sha256', self::URL), 0, 12), $a->id());
        self::assertStringNotContainsString('docs', $a->id());
    }

    public function testGzipBombIsCappedOnTheDecodedStream(): void
    {
        $gz = gzencode(str_repeat('{', 50_000_000), 9);
        self::assertLessThan(100_000, strlen((string) $gz), 'a small download that inflates to 50 MB');
        $body = new InflateStream(Utils::streamFor($gz));
        $before = $this->resetPeak();

        self::assertSame('too_large', $this->token(fn () => $this->url([new Response(200, [], $body)], null, 1_000_000)->read()));
        self::assertLessThan(32 * 1048576, memory_get_peak_usage() - $before, 'stopped at the cap, not after inflating everything');
    }

    public function testATricklingServerHitsTheWallClockDeadline(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            $now += 4.0; // every look at the clock costs 4 s

            return $now;
        };
        $trickle = new PumpStream(static fn (int $n): string => 'x'); // endless
        $src = new OpenApiSource($this->http([new Response(200, [], $trickle)]), self::URL, null, 8_388_608, Closure::fromCallable($clock));

        self::assertSame('timeout', $this->token(fn () => $src->read()));
    }

    public function testAFileThatGrowsAfterFilesizeIsStillCapped(): void
    {
        $path = $this->file(str_repeat(' ', 5000) . self::DOC); // 5,030 bytes on disk, filesize() claimed 10
        $src = new OpenApiSource($this->http([]), $path, null, 1000, null, static fn (string $p): int => 10);

        self::assertSame('too_large', $this->token(fn () => $src->read()));
    }

    public function testMaxBytesIsCappedAtTwoMiBWhateverTheCallerAsks(): void
    {
        self::assertSame(2097152, OpenApiSource::MAX_BYTES_CEILING);
        $ok = str_repeat(' ', 2097152 - strlen(self::DOC)) . self::DOC;
        $over = ' ' . $ok;
        self::assertSame(2097153, strlen($over));

        self::assertSame(['openapi' => '3.0.0', 'paths' => []], $this->url([new Response(200, [], $ok)], null, 8_388_608)->read());
        self::assertSame('too_large', $this->token(fn () => $this->url([new Response(200, [], $over)], null, 8_388_608)->read()));
        self::assertSame('too_large', $this->token(fn () => $this->url([new Response(200, ['Content-Length' => '2097153'], '{}')], null, 8_388_608)->read()));
        self::assertSame('too_large', $this->token(fn () => (new OpenApiSource($this->http([]), $this->file($over), null, 8_388_608))->read()));
    }

    /** @return array<string, array{string}> */
    public static function unsafeUrls(): array
    {
        $out = [];
        foreach ([
            'http://docs.example/openapi.json', 'https://user:pass@docs.example/openapi.json', 'https://:pw@docs.example/x', 'https:///x',
            'https://localhost/openapi.json', 'https://api.localhost/openapi.json', 'https://127.0.0.1/x', 'https://[::1]/x',
            'https://169.254.169.254/latest/meta-data', 'https://169.254.0.1/x', 'https://2130706433/x', 'https://0x7f000001/x',
            'https://0177.0.0.1/x', 'https://127.1/x', 'https://0x7f.1/x', 'https://2852039166/x', 'https://0xA9FEA9FE/x', 'https://0251.0376.0251.0376/x',
            'https://10.0.0.1/x', 'https://172.16.5.5/x', 'https://192.168.1.1/x', 'https://100.64.0.1/x', 'https://0.0.0.0/x',
            'https://[fd00::1]/x', 'https://[fc00::1]/x', 'https://[fe80::1]/x', 'https://[febf::1]/x', 'https://[::ffff:169.254.169.254]/x',
            'https://[::ffff:a9fe:a9fe]/x', 'https://[::]/x', 'https://[fd00:ec2::254]/x',
            'https://metadata.google.internal/x', 'https://METADATA.GOOGLE.INTERNAL./x', 'https://[64:ff9b:1::7f00:1]/x', 'https://kubernetes.default.svc/x',
            'https://grafana.monitoring.svc.cluster.local/x', 'https://instance-data/x', 'https://198.18.0.1/x', 'https://[::ffff:0:7f00:1]/x',
            'https://localhost./x', 'https://127.0.0.1./x', 'https://169.254.169.254./x', 'https://a..b/x', 'https://.docs.example/x', 'https://docs.example../x',
            'https://[::127.0.0.1]/x', 'https://[::7f00:1]/x', 'https://[::a9fe:a9fe]/x', 'https://[64:ff9b::a9fe:a9fe]/x', 'https://[64:ff9b::808:808]/x',
            'https://[2002:a9fe:a9fe::1]/x', 'https://[2002:7f00:1::1]/x', 'https://[2001::1]/x', 'https://[2001:0:4136:e378:8000:63bf:3fff:fdd2]/x',
            'https://[fec0::1]/x', 'https://[feff::1]/x', 'https://[fe80::1%25eth0]/x', 'https://[fe80::1%eth0]/x', 'https://[2606:4700::1%25eth0]/x', 'https://[not:an:ip]/x',
        ] as $u) {
            $out[$u] = [$u];
        }

        return $out;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeUrls')]
    public function testUnsafeRemoteSourcesNeverReachTheNetwork(string $url): void
    {
        $src = new OpenApiSource($this->http([new Response(200, [], self::DOC)]), $url, 'TOKEN123');
        self::assertSame('unsafe_source', $this->token(fn () => $src->read()));
        self::assertSame([], $this->history);
    }

    public function testPublicHostsAndAddressesAreAccepted(): void
    {
        foreach (['https://docs.example/openapi.json', 'https://8.8.8.8/x', 'https://[2606:4700::1111]/x', 'https://172.32.0.1/x', 'https://docs.example./x', 'https://[2002:0808:0808::1]/x', 'https://[2001:db8::1]/x'] as $u) {
            $this->history = [];
            $src = new OpenApiSource($this->http([new Response(200, [], self::DOC)]), $u);
            self::assertSame(['openapi' => '3.0.0', 'paths' => []], $src->read(), $u);
        }
    }

    public function testPlainHttpNeedsAllowInsecureAndAPrivateHostAndAllowPrivateNetwork(): void
    {
        $ok = ['http://127.0.0.1:8080/openapi.json', 'http://localhost:3000/openapi.json', 'http://[::1]/x', 'http://10.1.2.3/x', 'http://api.default.svc/x', 'https://localhost/x', 'https://10.1.2.3/x'];
        foreach ($ok as $u) {
            $src = new OpenApiSource($this->http([new Response(200, [], self::DOC)]), $u, allowInsecure: true, allowPrivateNetwork: true);
            self::assertSame(['openapi' => '3.0.0', 'paths' => []], $src->read(), $u);
        }
        // either flag alone is not enough for plain http, and allow_insecure alone opens no private host at all
        foreach (['http://127.0.0.1:8080/x', 'http://10.1.2.3/x', 'https://127.0.0.1/x', 'https://10.1.2.3/x'] as $u) {
            foreach ([[true, false], [false, true], [false, false]] as [$insecure, $private]) {
                if ($u[4] === 's' && $private) {
                    continue; // https to a private host is what allow_private_network alone admits
                }
                $src = new OpenApiSource($this->http([new Response(200, [], self::DOC)]), $u, allowInsecure: $insecure, allowPrivateNetwork: $private);
                self::assertSame('unsafe_source', $this->token(fn () => $src->read()), $u . ' insecure=' . (int) $insecure . ' private=' . (int) $private);
            }
        }
        // no flag opens metadata, link-local, CGNAT or a public host over http
        $this->history = [];
        foreach (['http://169.254.169.254/x', 'https://169.254.169.254/x', 'http://metadata.google.internal/x', 'http://100.64.0.1/x', 'http://docs.example/x', 'https://[fd00::1]/x'] as $u) {
            $src = new OpenApiSource($this->http([new Response(200, [], self::DOC)]), $u, allowInsecure: true, allowPrivateNetwork: true);
            self::assertSame('unsafe_source', $this->token(fn () => $src->read()), $u);
        }
        self::assertSame([], $this->history);
    }

    public function testFilePrefixAndAbsolutePathAreAccepted(): void
    {
        $p = $this->file(self::DOC);
        foreach ([$p, 'file:' . $p, 'file://' . $p] as $source) {
            self::assertSame(['openapi' => '3.0.0', 'paths' => []], (new OpenApiSource($this->http([]), $source))->read(), $source);
        }
    }

    public function testRiskyFilePathsAreRefused(): void
    {
        $http = $this->http([]);
        $p = $this->file(self::DOC);
        $cwd = (string) getcwd();
        chdir($this->dir);
        foreach (['doc.json', './doc.json', 'file:doc.json', $this->dir . '/../' . basename($this->dir) . '/doc.json', 'file:' . $this->dir . '/../x/doc.json'] as $bad) {
            self::assertSame('unreadable', $this->token(fn () => (new OpenApiSource($http, $bad))->read()), $bad);
        }
        chdir($cwd);
        $link = $this->dir . '/link.json';
        symlink($p, $link);
        self::assertSame('unreadable', $this->token(fn () => (new OpenApiSource($http, $link))->read()), 'symlink');
        self::assertSame('unreadable', $this->token(fn () => (new OpenApiSource($http, 'file:' . $link))->read()), 'file: symlink');
        unlink($link);
        chmod($p, 0666);
        self::assertSame('unreadable', $this->token(fn () => (new OpenApiSource($http, $p))->read()), 'world-writable');
        chmod($p, 0644);
        self::assertNotEmpty((new OpenApiSource($http, $p))->read());
    }

    public function testASymlinkInAParentDirectoryIsRefused(): void
    {
        mkdir($this->dir . '/real');
        file_put_contents($this->dir . '/real/s.json', self::DOC);
        symlink($this->dir . '/real', $this->dir . '/link');
        $http = $this->http([]);

        self::assertSame(['openapi' => '3.0.0', 'paths' => []], (new OpenApiSource($http, $this->dir . '/real/s.json'))->read());
        foreach ([$this->dir . '/link/s.json', 'file:' . $this->dir . '/link/s.json'] as $bad) {
            self::assertSame('unreadable', $this->token(fn () => (new OpenApiSource($http, $bad))->read()), $bad);
        }
    }

    /** Lexical clean-up (`//`, `/./`) names the same file; what is refused is a path whose real location differs (a symlink). */
    public function testLexicallyUntidyPathsStillNameTheSameFile(): void
    {
        $this->file(self::DOC);
        foreach ([$this->dir . '//doc.json', $this->dir . '/./doc.json'] as $source) {
            self::assertSame(['openapi' => '3.0.0', 'paths' => []], (new OpenApiSource($this->http([]), $source))->read(), $source);
        }
    }

    public function testAWorldWritableAncestorWithoutTheStickyBitIsRefused(): void
    {
        mkdir($this->dir . '/open');
        file_put_contents($this->dir . '/open/s.json', self::DOC);
        chmod($this->dir . '/open', 0777);
        self::assertSame('unreadable', $this->token(fn () => (new OpenApiSource($this->http([]), $this->dir . '/open/s.json'))->read()));

        chmod($this->dir . '/open', 01777); // like /tmp: other users cannot replace our file
        self::assertSame(['openapi' => '3.0.0', 'paths' => []], (new OpenApiSource($this->http([]), $this->dir . '/open/s.json'))->read());
    }

    public function testASpecialFileIsRefusedWithoutBlocking(): void
    {
        if (!function_exists('posix_mkfifo')) {
            self::markTestSkipped('posix_mkfifo needs ext-posix');
        }
        posix_mkfifo($this->dir . '/pipe.json', 0600);
        self::assertSame('unreadable', $this->token(fn () => (new OpenApiSource($this->http([]), $this->dir . '/pipe.json'))->read()));
    }

    public function testOnlyBelowAnAllowedRootWhenRootsAreConfigured(): void
    {
        mkdir($this->dir . '/specs');
        mkdir($this->dir . '/other');
        file_put_contents($this->dir . '/specs/a.json', self::DOC);
        file_put_contents($this->dir . '/other/b.json', self::DOC);
        $roots = [$this->dir . '/specs'];

        self::assertSame(['openapi' => '3.0.0', 'paths' => []], (new OpenApiSource($this->http([]), $this->dir . '/specs/a.json', allowedRoots: $roots))->read());
        self::assertSame('unreadable', $this->token(fn () => (new OpenApiSource($this->http([]), $this->dir . '/other/b.json', allowedRoots: $roots))->read()));
        // a sibling that merely shares the prefix is not below the root
        mkdir($this->dir . '/specs-evil');
        file_put_contents($this->dir . '/specs-evil/c.json', self::DOC);
        self::assertSame('unreadable', $this->token(fn () => (new OpenApiSource($this->http([]), $this->dir . '/specs-evil/c.json', allowedRoots: $roots))->read()));
        // without roots the configured source itself is read
        self::assertNotEmpty((new OpenApiSource($this->http([]), $this->dir . '/other/b.json'))->read());
    }

    public function testOutsideTheRootsNothingAboutTheFileIsRevealed(): void
    {
        mkdir($this->dir . '/specs');
        file_put_contents($this->dir . '/hosts', "127.0.0.1 localhost\n");
        file_put_contents($this->dir . '/notopenapi.json', '{"a":1}');
        file_put_contents($this->dir . '/big.json', str_repeat(' ', 100) . self::DOC);
        $roots = [$this->dir . '/specs'];
        $tokens = [];
        foreach (['hosts', 'notopenapi.json', 'big.json', 'missing.json'] as $f) {
            $tokens[$f] = $this->token(fn () => (new OpenApiSource($this->http([]), $this->dir . '/' . $f, maxBytes: 64, allowedRoots: $roots))->read());
        }

        self::assertSame(['hosts' => 'unreadable', 'notopenapi.json' => 'unreadable', 'big.json' => 'unreadable', 'missing.json' => 'unreadable'], $tokens);
    }
}
