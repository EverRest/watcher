<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Source\Kubernetes;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Source\Kubernetes\KubeApi;
use Watcher\Source\Kubernetes\KubeSettings;
use Watcher\Source\SourceUnavailable;
use Watcher\Tests\Fakes\FrozenClock;

/** token_file and ca_file (spec 008): validated at load, read at call time, fail closed, never echoing a path or a content. */
final class KubeCredentialFilesTest extends TestCase
{
    private const PEM = "-----BEGIN CERTIFICATE-----\nMIIBszCCAVmgAwIBAgIUXXXX\n-----END CERTIFICATE-----\n";

    private string $dir;
    /** @var list<array<string, mixed>> */
    private array $history = [];
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kube-creds-' . bin2hex(random_bytes(8)); // unique per test: ParaTest-safe
        mkdir($this->dir, 0700);
        $this->history = [];
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-24T06:00:00Z'));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @chmod($f, 0600);
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function file(string $name, string $content, int $mode = 0600): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $content);
        chmod($path, $mode);

        return $path;
    }

    /**
     * @param array<string, mixed> $api
     * @return array<string, mixed>
     */
    private static function settingsInput(array $api): array
    {
        return ['namespaces' => ['shop'], 'api' => $api];
    }

    /** @param array<string, mixed> $api */
    private static function message(array $api): string
    {
        try {
            KubeSettings::fromArray(self::settingsInput($api), true, true, false);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return '';
    }

    public function testATokenFileIsAcceptedAndIsExclusive(): void
    {
        $t = $this->file('token', "abc\n");
        $s = KubeSettings::fromArray(self::settingsInput(['server' => 'https://k8s.example', 'token_file' => $t]), true, true, false);

        self::assertFalse($s->inCluster);
        self::assertNull($s->token());
        self::assertSame($t, $s->tokenFile());
        self::assertSame(['abc'], $s->maskValues(), 'the content of the file is a secret, not its path');
        file_put_contents($t, "rotated\n");
        self::assertSame(['rotated'], $s->maskValues(), 'read when asked, so a rotated token is masked too');
        unlink($t);
        self::assertSame([], $s->maskValues(), 'an unreadable file masks nothing and throws nothing');
    }

    public function testTheSettingsDoNotDumpTheTokenOrTheTokenFilePath(): void
    {
        $s = KubeSettings::fromArray(self::settingsInput(['server' => 'https://k8s.example', 'token' => 'SECRET-TOKEN-VALUE']), true, true, false);
        $f = KubeSettings::fromArray(self::settingsInput(['server' => 'https://k8s.example', 'token_file' => $this->file('token', 'abc')]), true, true, false);

        foreach ([$s, $f] as $settings) {
            ob_start();
            var_dump($settings);
            print_r($settings);
            $dump = (string) ob_get_clean() . json_encode($settings);
            self::assertStringNotContainsString('SECRET-TOKEN-VALUE', $dump);
            self::assertStringNotContainsString($this->dir, $dump);
        }
        $this->expectException(\LogicException::class);
        serialize($s);
    }

    public function testTheInClusterServiceAccountTokenIsMaskedToo(): void
    {
        $dir = $this->dir . '/sa';
        mkdir($dir, 0700);
        file_put_contents($dir . '/token', "  eyJ-service-account-token \n");
        $s = KubeSettings::fromArray(self::settingsInput([]), true, true, false);

        self::assertTrue($s->inCluster);
        self::assertSame(['eyJ-service-account-token'], $s->maskValues($dir), 'read when asked, so a rotated token is masked too');
        self::assertSame([], $s->maskValues($dir . '/missing'), 'nothing to read, nothing thrown');
    }

    public function testNoDebugOrExportPathPrintsTheTokenOrWhereItLives(): void
    {
        $inline = KubeSettings::fromArray(self::settingsInput(['server' => 'https://k8s.example', 'token' => 'SECRET-TOKEN-VALUE']), true, true, false);
        $file = KubeSettings::fromArray(self::settingsInput(['server' => 'https://k8s.example', 'token_file' => $this->file('token', 'abc')]), true, true, false);
        $api = new KubeApi(new Client(), $inline, $this->clock);

        foreach ([$inline, $file, $api] as $object) {
            $dump = var_export($object, true) . print_r($object, true) . json_encode((array) $object, JSON_PARTIAL_OUTPUT_ON_ERROR) . (string) json_encode($object);
            self::assertStringNotContainsString('SECRET-TOKEN-VALUE', $dump, $object::class);
            self::assertStringNotContainsString($this->dir, $dump, $object::class);
            ob_start();
            var_dump((array) $object);
            self::assertStringNotContainsString('SECRET-TOKEN-VALUE', (string) ob_get_clean(), $object::class . ' cast to array');
        }
        self::assertSame('SECRET-TOKEN-VALUE', $inline->token());
        self::assertSame($this->dir . '/token', $file->tokenFile());
    }

    public function testTheTokenIsASensitiveParameterSoAStackTraceNeverHoldsIt(): void
    {
        $ctor = (new \ReflectionClass(KubeSettings::class))->getConstructor();
        self::assertNotNull($ctor);
        foreach ($ctor->getParameters() as $p) {
            if ($p->getName() === 'token') {
                self::assertCount(1, $p->getAttributes(\SensitiveParameter::class));
            }
        }
        $from = new \ReflectionMethod(KubeSettings::class, 'fromArray');
        self::assertCount(1, $from->getParameters()[0]->getAttributes(\SensitiveParameter::class), 'the array carries the token');
    }

    public function testExactlyOneOfTokenTokenFileOrInCluster(): void
    {
        $t = $this->file('token', 'abc');
        self::assertStringContainsString('kubernetes.api', self::message(['server' => 'https://k8s.example', 'token' => 'x', 'token_file' => $t]));
        self::assertStringContainsString('kubernetes.api', self::message(['in_cluster' => true, 'token_file' => $t]));
        self::assertStringContainsString('kubernetes.api.token', self::message(['server' => 'https://k8s.example']));
    }

    /** @return array<string, array{string}> */
    public static function badTokenFiles(): array
    {
        return ['relative' => ['relative/token'], 'missing' => ['/nonexistent-dir-xyz/token'], 'empty string' => ['']];
    }

    #[DataProvider('badTokenFiles')]
    public function testBadTokenFilePathsNameTheKeyAndNeverTheValue(string $path): void
    {
        $m = self::message(['server' => 'https://k8s.example', 'token_file' => $path]);

        self::assertStringContainsString('kubernetes.api.token_file', $m);
        if ($path !== '') {
            self::assertStringNotContainsString($path, $m);
        }
    }

    public function testTokenFileMustBeARegularFileNotWorldWritableAndSmall(): void
    {
        $dir = $this->dir . '/subdir';
        mkdir($dir);
        $open = $this->file('open', 'abc', 0666);
        $big = $this->file('big', str_repeat('a', 16 * 1024 + 1));
        foreach ([$dir, $open, $big] as $bad) {
            $m = self::message(['server' => 'https://k8s.example', 'token_file' => $bad]);
            self::assertStringContainsString('kubernetes.api.token_file', $m, $bad);
            self::assertStringNotContainsString($bad, $m);
        }
        $edge = $this->file('edge', str_repeat('a', 16 * 1024));
        self::assertSame('', self::message(['server' => 'https://k8s.example', 'token_file' => $edge]));
        @rmdir($dir);
    }

    /** @return array<string, array{string}> */
    public static function badCaFiles(): array
    {
        return ['empty' => [''], 'not pem' => ['hello'], 'key only' => ["-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----\n"]];
    }

    #[DataProvider('badCaFiles')]
    public function testACaFileWithoutACertificateIsRejected(string $content): void
    {
        $ca = $this->file('ca.crt', $content);
        $m = self::message(['server' => 'https://k8s.example', 'token' => 't', 'ca_file' => $ca]);

        self::assertStringContainsString('kubernetes.api.ca_file', $m);
        self::assertStringNotContainsString($ca, $m);
    }

    public function testACaFileMustExistAndBeAFile(): void
    {
        self::assertStringContainsString('kubernetes.api.ca_file', self::message(['server' => 'https://k8s.example', 'token' => 't', 'ca_file' => $this->dir . '/missing.crt']));
        self::assertStringContainsString('kubernetes.api.ca_file', self::message(['server' => 'https://k8s.example', 'token' => 't', 'ca_file' => $this->dir]));
        self::assertStringContainsString('kubernetes.api.ca_file', self::message(['server' => 'https://k8s.example', 'token' => 't', 'ca_file' => 'relative.crt']));
    }

    public function testAValidCaFileIsAccepted(): void
    {
        $ca = $this->file('ca.crt', self::PEM);

        self::assertSame('', self::message(['server' => 'https://k8s.example', 'token' => 't', 'ca_file' => $ca]));
    }

    public function testTheCredentialKeySeparatesTokenFiles(): void
    {
        $a = KubeSettings::fromArray(self::settingsInput(['server' => 'https://k8s.example', 'token_file' => $this->file('a', 'x')]), true, true, false);
        $b = KubeSettings::fromArray(self::settingsInput(['server' => 'https://k8s.example', 'token_file' => $this->file('b', 'x')]), true, true, false);

        self::assertNotSame($a->credentialKey(), $b->credentialKey());
    }

    /**
     * @param array<string, mixed> $api
     * @param list<Response> $queue
     */
    private function api(array $api, array $queue): KubeApi
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $historyLog = [];
        $this->history = &$historyLog; // Guzzle fills it by reference
        $stack->push(Middleware::history($historyLog));
        $settings = KubeSettings::fromArray(self::settingsInput($api), true, true, false);

        return new KubeApi(new Client(['handler' => $stack]), $settings, $this->clock, static function (int $s): void {
        });
    }

    private static function ok(): Response
    {
        return new Response(200, [], '{"items":[]}');
    }

    public function testTheTokenFileIsReadAtCallTimeSoARotatedTokenIsUsedAndWhitespaceIsTrimmed(): void
    {
        $t = $this->file('token', "  first-token \n");
        $api = $this->api(['server' => 'https://k8s.example', 'token_file' => $t], [self::ok(), self::ok()]);
        $api->listEvents('shop');
        self::assertSame('Bearer first-token', $this->history[0]['request']->getHeaderLine('Authorization'));

        file_put_contents($t, "rotated-token\n");
        $this->clock->advance('+2 minutes'); // past the list memo
        $api->listEvents('shop');
        self::assertSame('Bearer rotated-token', $this->history[1]['request']->getHeaderLine('Authorization'));
    }

    public function testAnEmptyOrVanishedTokenFileFailsAtUseTimeWithAReasonCodeOnly(): void
    {
        $t = $this->file('token', "\n");
        $api = $this->api(['server' => 'https://k8s.example', 'token_file' => $t], [self::ok()]);
        foreach ([0 => 'empty', 1 => 'removed'] as $round => $what) {
            if ($round === 1) {
                unlink($t);
            }
            try {
                $api->listEvents('shop');
                self::fail("expected SourceUnavailable ($what)");
            } catch (SourceUnavailable $e) {
                self::assertSame('kubernetes credential unreadable', $e->getMessage());
                self::assertStringNotContainsString($t, $e->getMessage());
            }
        }
        self::assertSame([], $this->history, 'nothing is sent without a token');
    }

    public function testACaFileThatDisappearsAfterLoadFailsClosedWithoutSendingAnything(): void
    {
        $ca = $this->file('ca.crt', self::PEM);
        $api = $this->api(['server' => 'https://k8s.example', 'token' => 't', 'ca_file' => $ca], [self::ok()]);
        unlink($ca);

        try {
            $api->listEvents('shop');
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertSame('kubernetes credential unreadable', $e->getMessage());
            self::assertStringNotContainsString($ca, $e->getMessage());
        }
        self::assertSame([], $this->history);
    }

    /** @return array<string, array{string}> */
    public static function unsafeTokenFilesAtUseTime(): array
    {
        return ['symlink' => ['symlink'], 'dotdot' => ['dotdot'], 'group-writable' => ['groupw'], 'world-writable' => ['worldw'], 'oversize' => ['big'], 'fifo' => ['fifo'], 'directory' => ['dir']];
    }

    /** The standard Secret / projected-volume layout: token -> ..data/token, ..data -> ..2026_10_24_01 (a directory). */
    private function projectedVolume(string $content, string $generation = '..2026_10_24_06_00_00.111'): string
    {
        mkdir($this->dir . '/' . $generation, 0700);
        file_put_contents($this->dir . '/' . $generation . '/token', $content);
        chmod($this->dir . '/' . $generation . '/token', 0644);
        @unlink($this->dir . '/..data');
        symlink($generation, $this->dir . '/..data');
        if (!is_link($this->dir . '/token')) {
            symlink('..data/token', $this->dir . '/token');
        }

        return $this->dir . '/token';
    }

    public function testTheStandardProjectedVolumeLayoutIsAcceptedAtLoadAndAtUseAndRotationIsFollowed(): void
    {
        $t = $this->projectedVolume('first-token');
        $api = $this->api(['server' => 'https://k8s.example', 'token_file' => $t], [self::ok(), self::ok()]);
        $api->listEvents('shop');
        self::assertSame('Bearer first-token', $this->history[0]['request']->getHeaderLine('Authorization'));

        $this->projectedVolume('rotated-token', '..2026_10_24_07_00_00.222'); // the kubelet swaps ..data atomically
        $this->clock->advance('+2 minutes'); // past the list memo
        $api->listEvents('shop');
        self::assertSame('Bearer rotated-token', $this->history[1]['request']->getHeaderLine('Authorization'));
        self::assertSame(['rotated-token'], KubeSettings::fromArray(self::settingsInput(['server' => 'https://k8s.example', 'token_file' => $t]), true, true, false)->maskValues());
    }

    public function testAProjectedTokenThatEscapesItsDirectoryOrIsWritableIsRefusedAtLoadToo(): void
    {
        $outside = sys_get_temp_dir() . '/kube-outside-' . bin2hex(random_bytes(6));
        file_put_contents($outside, 'outside-secret');
        symlink($outside, $this->dir . '/escape');
        $writable = $this->projectedVolume('w-token');
        chmod(dirname($writable) . '/..data/token', 0662);

        foreach ([$this->dir . '/escape', $writable] as $t) {
            self::assertStringContainsString('kubernetes.api.token_file', self::message(['server' => 'https://k8s.example', 'token_file' => $t]));
        }
        @unlink($outside);
    }

    public function testWithoutExtPosixOnlyRootOwnedFilesPass(): void
    {
        $st = ['mode' => 0100600, 'size' => 10, 'uid' => 1234];

        self::assertTrue(KubeSettings::safeTokenStat($st, 1234), 'owned by the effective user');
        self::assertFalse(KubeSettings::safeTokenStat($st, null), 'no way to know who we are: fail closed');
        self::assertFalse(KubeSettings::safeTokenStat($st, 999), 'someone else');
        self::assertTrue(KubeSettings::safeTokenStat(['uid' => 0] + $st, null), 'root-owned');
    }

    public function testATokenDirectoryAnyoneCanWriteIsRefusedUnlessSticky(): void
    {
        $t = $this->file('token', 'abc');
        chmod($this->dir, 0777);
        self::assertStringContainsString('kubernetes.api.token_file', self::message(['server' => 'https://k8s.example', 'token_file' => $t]));
        chmod($this->dir, 01777);
        self::assertSame('', self::message(['server' => 'https://k8s.example', 'token_file' => $t]), 'the sticky bit makes a shared directory acceptable');
        chmod($this->dir, 0700);
        self::assertNull(KubeSettings::resolveTokenFile('/'));
        self::assertNull(KubeSettings::resolveTokenFile('/token-that-does-not-exist'));
    }

    public function testAPathComponentSwappedBetweenTheCheckAndTheOpenIsCaught(): void
    {
        $t = $this->projectedVolume('good-token'); // dir/token -> ..data/token -> ..2026.../token
        $outside = sys_get_temp_dir() . '/kube-swap-' . bin2hex(random_bytes(6));
        mkdir($outside, 0700);
        file_put_contents($outside . '/token', 'outside-token');
        $generation = $this->dir . '/..2026_10_24_06_00_00.111';
        $swap = function () use ($generation, $outside): void {
            rename($generation, $generation . '.old');
            symlink($outside, $generation); // the directory the resolved path runs through now points outside
        };
        $stack = HandlerStack::create(new MockHandler([self::ok()]));
        $historyLog = [];
        $this->history = &$historyLog; // Guzzle fills it by reference
        $stack->push(Middleware::history($historyLog));
        $settings = KubeSettings::fromArray(self::settingsInput(['server' => 'https://k8s.example', 'token_file' => $t]), true, true, false);
        $api = new KubeApi(new Client(['handler' => $stack]), $settings, $this->clock, static function (int $s): void {
        }, null, [], 16 * 1048576, 60, null, $swap);

        try {
            $api->listEvents('shop');
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertSame('kubernetes credential unreadable', $e->getMessage());
        } finally {
            @unlink($generation);
            @unlink($outside . '/token');
            @rmdir($outside);
        }
        self::assertSame([], $this->history, 'nothing was sent with the swapped-in token');
    }

    #[DataProvider('unsafeTokenFilesAtUseTime')]
    public function testATokenFileThatTurnsUnsafeAfterLoadIsRefusedAtReadTimeAndNothingIsSent(string $kind): void
    {
        $t = $this->file('token', 'good-token'); // valid at load
        $api = $this->api(['server' => 'https://k8s.example', 'token_file' => $t], [self::ok()]);
        $real = $this->file('real', 'some-other-secret');
        $outside = sys_get_temp_dir() . '/kube-outside-' . bin2hex(random_bytes(6));
        file_put_contents($outside, 'outside-secret');
        unlink($t);
        match ($kind) {
            'symlink' => symlink($outside, $t), // a symlink to a file OUTSIDE the token's own directory tree
            'dotdot' => symlink('../' . basename($outside), $t),
            'groupw' => $this->file('token', 'good-token', 0620),
            'worldw' => $this->file('token', 'good-token', 0602),
            'big' => $this->file('token', str_repeat('a', KubeSettings::MAX_TOKEN_BYTES + 1)),
            'fifo' => posix_mkfifo($t, 0600),
            'dir' => mkdir($t, 0700),
            default => self::fail($kind),
        };

        try {
            $api->listEvents('shop');
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertSame('kubernetes credential unreadable', $e->getMessage());
        } finally {
            if ($kind === 'dir') {
                rmdir($t);
            }
        }
        self::assertSame([], $this->history);
    }

    public function testATokenExactlyAtTheSizeCapIsAcceptedAtReadTime(): void
    {
        $t = $this->file('token', str_repeat('a', KubeSettings::MAX_TOKEN_BYTES));
        $this->api(['server' => 'https://k8s.example', 'token_file' => $t], [self::ok()])->listEvents('shop');

        self::assertCount(1, $this->history);
    }

    public function testTheCaFileIsHandedToTheClientNeverFalse(): void
    {
        $ca = $this->file('ca.crt', self::PEM);
        $this->api(['server' => 'https://k8s.example', 'token' => 't', 'ca_file' => $ca], [self::ok()])->listEvents('shop');

        self::assertSame($ca, $this->history[0]['options']['verify']);
    }
}
