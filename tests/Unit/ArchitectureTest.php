<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Watcher\Config\WatchConfig;

/** Source-text rules that keep the package generic, read-only and framework-free (constitution II, VI). */
final class ArchitectureTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** @return list<string> absolute paths of files under $dir (relative to the package root) with the given extension */
    private static function files(string $dir, string $ext = 'php'): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f instanceof \SplFileInfo && $f->isFile() && $f->getExtension() === $ext) {
                $out[] = $f->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    /**
     * @param list<string> $files
     * @return list<string> "file:line: text" for each line matching $pattern
     */
    private static function grep(array $files, string $pattern): array
    {
        $hits = [];
        foreach ($files as $file) {
            foreach (file($file) ?: [] as $i => $line) {
                if (preg_match($pattern, $line) === 1) {
                    $hits[] = substr($file, strlen(self::ROOT) + 1) . ':' . ($i + 1) . ': ' . trim($line);
                }
            }
        }

        return $hits;
    }

    /** @return list<string> */
    private static function shipped(): array
    {
        return [...self::files('src'), ...self::files('config'), self::ROOT . '/README.md'];
    }

    public function testRedisStateStoreWritesOnlyThroughTheTtlGuard(): void
    {
        $src = (string) file_get_contents(self::ROOT . '/src/State/RedisStateStore.php');
        self::assertSame(0, preg_match('/->(incrbyfloat|hset|setnx|setex|psetex|append|incr|decr)\(/i', $src), 'a Redis command that can create a key without a TTL');
        self::assertSame(1, substr_count($src, '->set('), 'one SET call, inside the guarded writer');
        self::assertMatchesRegularExpression('/private function write\([^{]*\{[^}]*->set\(/s', $src, 'the only SET is inside write()');
        foreach (['PhpRedisClient', 'PredisClient'] as $adapter) {
            $a = (string) file_get_contents(self::ROOT . "/src/State/Redis/{$adapter}.php");
            self::assertSame(0, preg_match('/(->|\'|")(incrbyfloat|hset|setnx|setex)\b/i', $a), $adapter . ' issues a key-creating command without a TTL');
        }
    }

    public function testNoModelIdUnderSrcOrConfig(): void
    {
        self::assertSame([], self::grep([...self::files('src'), ...self::files('src', 'md'), ...self::files('src', 'json'), ...self::files('config')], '/claude-/i'));
    }

    public function testNothingConsumerSpecificIsShipped(): void
    {
        $files = array_values(array_filter(self::shipped(), 'is_file'));

        self::assertSame([], self::grep($files, '/tenant-a|core-api|users-api|bff-a|bff-b/i'));
    }

    public function testLokiSourceOnlyReadsQueryEndpointsWithGet(): void
    {
        $this->assertReadOnly(self::ROOT . '/src/Source/LokiSource.php', ['GET'], ['/loki/api/v1/query', '/loki/api/v1/query_range']);
    }

    public function testDatadogSourceOnlyPostsToReadEndpoints(): void
    {
        $this->assertReadOnly(self::ROOT . '/src/Source/DatadogSource.php', ['POST'], ['/api/v2/logs/analytics/aggregate', '/api/v2/logs/events/search']);
    }

    public function testEverySourceImplementationIsCovered(): void
    {
        $sources = array_map('basename', glob(self::ROOT . '/src/Source/*Source.php') ?: []);
        sort($sources);

        // LogSource is the contract; a new adapter needs its own read-only rule.
        self::assertSame(['DatadogSource.php', 'LogSource.php', 'LokiSource.php'], $sources);
    }

    /**
     * @param list<string> $methods
     * @param list<string> $paths
     */
    private function assertReadOnly(string $file, array $methods, array $paths): void
    {
        $code = (string) file_get_contents($file);
        $name = basename($file);

        preg_match_all("/->request\(\s*'([A-Z]+)'/", $code, $m);
        self::assertSame($methods, array_values(array_unique($m[1])), "$name: HTTP methods");
        self::assertSame(0, preg_match('/\b(PUT|PATCH|DELETE|HEAD)\b/', $code), "$name: write verbs");

        preg_match_all("~'(/[a-z]+(?:/[a-z0-9_]+)*/(?:api|v\\d)/[^']*)'~", $code, $p);
        $found = array_values(array_unique($p[1]));
        sort($found);
        $expected = $paths;
        sort($expected);
        self::assertSame($expected, $found, "$name: request paths");
    }

    public function testTelegramNotifierOnlyPostsToSendMessage(): void
    {
        $code = (string) file_get_contents(self::ROOT . '/src/Notify/TelegramNotifier.php');

        preg_match_all("/->request\(\s*'([A-Z]+)'/", $code, $verbs);
        self::assertSame(['POST'], $verbs[1], 'one request, POST');
        self::assertSame(1, preg_match_all('~https?://[^\s\'"]+~', $code, $urls));
        self::assertSame(["https://api.telegram.org/bot"], $urls[0], 'the only host the notifier names');
        self::assertSame(1, substr_count($code, "/sendMessage'"));
        self::assertSame(0, preg_match('/\b(PUT|PATCH|DELETE|HEAD)\b/', $code));
        self::assertSame(0, preg_match('/allow_redirects\W+true/', $code));
    }

    public function testNoOtherBotApiMethodIsNamedUnderSrc(): void
    {
        $methods = 'getMe|getUpdates|getChat\w*|getFile|setWebhook|deleteWebhook|getWebhookInfo|deleteMessage\w*|editMessage\w*|forwardMessage\w*|copyMessage\w*|send(?!Message\b)[A-Z]\w*|pinChatMessage|leaveChat|banChatMember|answerCallbackQuery';

        self::assertSame([], self::grep(self::files('src'), "~['\"/]($methods)['\"/(]~"));
    }

    public function testDiscordNotifierOnlyPostsToTheWebhook(): void
    {
        $code = (string) file_get_contents(self::ROOT . '/src/Notify/DiscordNotifier.php');

        preg_match_all("/->request\(\s*'([A-Z]+)',\s*([^,]+),/", $code, $m);
        self::assertSame(['POST'], $m[1]);
        self::assertSame(['$to->webhook()'], $m[2], 'URL comes from the destination, never built here');
        self::assertSame(0, preg_match('~https?://~', $code), 'no host literal: the destination re-checks it');
        self::assertSame(0, preg_match('/\b(PUT|PATCH|DELETE|HEAD)\b/', $code));
    }

    public function testSlackNotifierOnlyPostsToTheWebhook(): void
    {
        $code = (string) file_get_contents(self::ROOT . '/src/Notify/SlackWebhookNotifier.php');

        preg_match_all("/->request\(\s*'([A-Z]+)',\s*([^,]+),/", $code, $m);
        self::assertSame(['POST'], $m[1]);
        self::assertSame(['$to->webhook()'], $m[2]);
    }

    public function testEveryNotifierImplementationIsCovered(): void
    {
        $notifiers = array_map('basename', glob(self::ROOT . '/src/Notify/*Notifier.php') ?: []);
        sort($notifiers);

        // Notifier is the contract; a new transport needs its own endpoint rule above. NoneNotifier (dashboard-only) has no transport.
        self::assertSame(['DiscordNotifier.php', 'NoneNotifier.php', 'Notifier.php', 'RoutingNotifier.php', 'SlackWebhookNotifier.php', 'TelegramNotifier.php'], $notifiers);
    }

    public function testNoNotifierPutsASecretInAnExceptionMessage(): void
    {
        $notifiers = array_values(array_filter(glob(self::ROOT . '/src/Notify/*Notifier.php') ?: [], static fn (string $f): bool => basename($f) !== 'Notifier.php'));

        self::assertSame([], self::grep($notifiers, '/new DeliveryFailed\([^;]*(?:botToken|chatId|webhook|\$url|\$to\b|getPrevious|\$e\b)/i'));
        self::assertSame([], self::grep($notifiers, '/DeliveryFailed\([^;]*,\s*\d+\s*,\s*\$/'), 'no previous exception chained');
    }

    public function testCoreImportsNothingFromIlluminate(): void
    {
        $core = array_values(array_filter(self::files('src'), static fn (string $f): bool => !str_contains($f, '/src/Laravel/')));

        self::assertSame([], self::grep($core, '/\bIlluminate\\\\/'));
    }

    public function testEveryConcreteClassIsFinal(): void
    {
        $bad = self::grep(self::files('src'), '/^\s*(?:readonly\s+)?class\s+\w+/');

        self::assertSame([], $bad, 'concrete classes must be final (abstract/interface/enum are fine)');
    }

    public function testNoDebugOutputInSrc(): void
    {
        self::assertSame([], self::grep(self::files('src'), '/\b(?:var_dump|print_r|error_log)\s*\(|(?<![\w$>:])dd\s*\(/'));
    }

    public function testExampleConfigValidates(): void
    {
        $env = [
            'WATCHER_LOKI_URL' => 'https://loki.example.com', 'WATCHER_LOKI_TOKEN' => 't',
            'WATCHER_GEMINI_KEY' => 'k', 'WATCHER_SLACK_WEBHOOK' => 'https://hooks.example.com/x',
            'WATCHER_MODEL_TRIAGE' => 'model-a', 'WATCHER_MODEL_ANALYZE' => 'model-b', 'WATCHER_MODEL_ESCALATE' => 'model-c',
            'WATCHER_PRICE_TRIAGE_IN' => '1', 'WATCHER_PRICE_TRIAGE_OUT' => '5',
            'WATCHER_PRICE_ANALYZE_IN' => '3', 'WATCHER_PRICE_ANALYZE_OUT' => '15',
            'WATCHER_PRICE_ESCALATE_IN' => '5', 'WATCHER_PRICE_ESCALATE_OUT' => '25',
            'WATCHER_DB_DSN' => 'sqlite::memory:',
        ];
        $before = [];
        foreach ($env as $k => $v) {
            $before[$k] = getenv($k);
            putenv("$k=$v");
        }
        try {
            $config = require self::ROOT . '/config/watcher.example.php';
            $parsed = WatchConfig::fromArray($config);
        } finally {
            foreach ($before as $k => $v) {
                putenv($v === false ? $k : "$k=$v");
            }
        }

        self::assertSame('orders-api', $parsed->service);
        self::assertSame(['model-a', 'model-b', 'model-c'], array_values($parsed->analysis['models']));
    }

    public function testPublishableConfigUsesOnlyKeysTheLoaderReads(): void
    {
        // config/watcher.php is what Lumen/Laravel hosts publish: an unknown nested key would be silently ignored.
        $text = (string) file_get_contents(self::ROOT . '/config/watcher.php');

        self::assertSame(1, preg_match("/'redis'\\s*=>\\s*\\[(?<body>[^\\]]*)\\]/s", $text, $m), 'state.redis block not found');
        preg_match_all("/'(\\w+)'\\s*=>/", $m['body'] ?? '', $keys);
        self::assertSame([], array_diff($keys[1], ['instance', 'host', 'port', 'password', 'database', 'client', 'compress_min_bytes', 'read_timeout', 'prefix', 'username']), 'state.redis holds a key WatchConfig does not read');

        self::assertSame(1, preg_match("/'pdo'\\s*=>\\s*\\[(?<body>[^\\]]*)\\]/s", $text, $p), 'state.pdo block not found');
        preg_match_all("/'(\\w+)'\\s*=>/", $p['body'] ?? '', $pk);
        self::assertSame([], array_diff($pk[1], ['dsn', 'username', 'password', 'table']), 'state.pdo holds a key WatchConfig does not read');
    }

    public function testSmokeConfigInTheGuideValidates(): void
    {
        $env = [
            'WATCHER_LOKI_URL' => 'https://grafana.example.com/api/datasources/proxy/uid/loki', 'WATCHER_LOKI_TOKEN' => 't',
            'WATCHER_GEMINI_KEY' => 'k', 'WATCHER_SLACK_WEBHOOK' => 'https://hooks.example.com/x',
            'WATCHER_MODEL_TRIAGE' => 'model-a', 'WATCHER_MODEL_ANALYZE' => 'model-b', 'WATCHER_MODEL_ESCALATE' => 'model-c',
            'WATCHER_PRICE_TRIAGE_IN' => '1', 'WATCHER_PRICE_TRIAGE_OUT' => '5',
            'WATCHER_PRICE_ANALYZE_IN' => '3', 'WATCHER_PRICE_ANALYZE_OUT' => '15',
            'WATCHER_PRICE_ESCALATE_IN' => '5', 'WATCHER_PRICE_ESCALATE_OUT' => '25',
        ];
        $before = [];
        foreach ($env as $k => $v) {
            $before[$k] = getenv($k);
            putenv("$k=$v");
        }
        try {
            $parsed = WatchConfig::fromArray(require self::ROOT . '/docs/examples/smoke.config.php');
        } finally {
            foreach ($before as $k => $v) {
                putenv($v === false ? $k : "$k=$v");
            }
        }

        self::assertSame('smoke-service', $parsed->service);
        self::assertSame('file', $parsed->state['driver']);
    }

    /** @return list<string> */
    private static function observeFiles(): array
    {
        return self::files('src/Observe');
    }

    public function testObserveReadsNoLogEventAndNoSourceInternals(): void
    {
        // Events are built from the typed results of a run (Finding, RedactedGroup, RunStats), never from raw log lines.
        self::assertSame([], self::grep(self::observeFiles(), '/\bLogEvent\b|use Watcher\\\\Source\\\\(?!Retry;|ResponseLimit;)/'));
    }

    public function testTheOnlyIoInObserveIsTheConfiguredFilesStdStreamsAndAPostToTheConfiguredUrl(): void
    {
        self::assertSame([], self::grep(self::observeFiles(), '/\b(curl_\w+|fsockopen|stream_socket_client|mail|exec|shell_exec|system|passthru|proc_open|popen)\s*\(/'));
        self::assertSame([], self::grep(self::observeFiles(), '~file_get_contents\(\s*[\'"]https?://|fopen\(\s*[\'"]https?://~'));
        $requests = self::grep(self::observeFiles(), '/->request\(/');
        self::assertCount(1, $requests);
        self::assertStringContainsString("src/Observe/HttpSink.php", $requests[0]);
        self::assertStringContainsString("'POST', \$this->target->url()", $requests[0]);
        self::assertSame([], self::grep(self::observeFiles(), '/php:\/\/(?!stderr|stdout|memory)/'));
    }

    public function testNoObserveClassReadsTheEnvironment(): void
    {
        self::assertSame([], self::grep(self::observeFiles(), '/\$_(ENV|SERVER)\b|\bgetenv\s*\(|\bEnv::/'));
    }

    public function testNoConfigKeyDisablesTheSanitizer(): void
    {
        $text = (string) file_get_contents(self::ROOT . '/src/Observe/ObserveSettings.php');
        preg_match_all('/self::only\(\$\w+, \[([^\]]*)\]/', $text, $m);

        self::assertNotSame([], $m[1]);
        foreach ($m[1] as $keys) {
            self::assertDoesNotMatchRegularExpression('/sanitiz|redact|scrub|privacy|raw|unsafe/i', $keys);
        }
        // The dispatcher sanitizes every copy it hands to a sink, with no switch.
        $dispatcher = (string) file_get_contents(self::ROOT . '/src/Observe/SinkDispatcher.php');
        self::assertStringContainsString('$this->sanitizer->sanitize($e, $includeText)', $dispatcher);
    }

    public function testNoSinkSwallowsAThrowableThatIsAWriteFailure(): void
    {
        foreach (['LogSink', 'FileSink', 'HttpSink', 'PrometheusSink'] as $sink) {
            $code = (string) file_get_contents(self::ROOT . "/src/Observe/$sink.php");
            $found = 0;
            preg_match_all('/catch \((?:\\\\)?Throwable[^)]*\)\s*\{/', $code, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[0] as [$head, $at]) {
                $start = $at + strlen($head);
                $depth = 1;
                for ($end = $start; $depth > 0 && $end < strlen($code); ++$end) {
                    $depth += $code[$end] === '{' ? 1 : ($code[$end] === '}' ? -1 : 0);
                }
                $block = substr($code, $start, $end - $start);
                ++$found;
                self::assertTrue(str_contains($block, 'throw') || str_contains($block, 'not a write failure'), "$sink hides a Throwable from the dispatcher's failure counters");
            }
            self::assertGreaterThan(0, $found, $sink);
        }
    }

    /** Spec 009 T041: the trace id cannot be carried by a group, the model payload or state: none of these files knows the word. */
    public function testNoGroupPayloadOrStateFileMentionsATraceId(): void
    {
        $files = array_merge([self::ROOT . '/src/Privacy/RedactedGroup.php', self::ROOT . '/src/Reduce/ProblemGroup.php', self::ROOT . '/src/Analyze/CompactPayload.php'], glob(self::ROOT . '/src/State/*.php') ?: []);
        foreach ($files as $f) {
            self::assertStringNotContainsStringIgnoringCase('trace', (string) file_get_contents($f), $f);
        }
    }
}
