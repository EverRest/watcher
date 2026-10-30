<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnalysisRequest;
use Watcher\Analyze\AnalysisResult;
use Watcher\Analyze\Analyzer;
use Watcher\Analyze\Finding;
use Watcher\Analyze\FindingKind;
use Watcher\Analyze\Severity;
use Watcher\Analyze\TriageRequest;
use Watcher\Analyze\TriageResult;
use Watcher\Analyze\CompactPayload;
use Watcher\Config\WatchConfig;
use Watcher\Level;
use Watcher\RunOutcome;
use Watcher\Source\LineParser;
use Watcher\Source\LogEvent;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\InMemoryStateStore;
use Watcher\Tests\Fakes\RoutedLogSource;
use Watcher\Watcher;

/** Principle I, layers 1 and 2, end to end: no seeded personal value reaches any outbound payload or stored item. */
final class PrivacyTest extends TestCase
{
    private const ALLOWED_KEYS = ['fp', 'tpl', 'lvl', 'err', 'frames', 'n', 'prev', 'st', 'watch', 'first', 'last'];

    public function testNoSeededValueLeavesTheServiceOrIsPersisted(): void
    {
        $dir = __DIR__ . '/../Fixtures/privacy';
        /** @var list<string> $seeded */
        $seeded = require $dir . '/seeded-values.php';
        $parser = new LineParser();
        $events = [];
        foreach (file($dir . '/corpus.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $events[] = $parser->parse($line);
        }
        self::assertNotSame([], $events);

        // The first run only records a baseline when nothing is at Error or above (FR-035), so those lines are read as Warning.
        $baseline = array_map(static fn (LogEvent $e): LogEvent => $e->level->atLeast(Level::Error)
            ? new LogEvent($e->time, Level::Warning, $e->message, $e->errorType, $e->frames, $e->fields, $e->labels, $e->occurrences, $e->podId, $e->origin, $e->workloadKind)
            : $e, $events);

        $h = new Harness(); // serviceContext stays empty: its redaction scan arrives with US6
        $first = $h->watcher(new FakeLogSource(Harness::totalsOf($baseline), $baseline))->run();
        $h->nextWindow();
        $second = $h->watcher(new FakeLogSource(Harness::totalsOf($events), $events))->run();

        self::assertSame(RunOutcome::Baselined, $first->outcome);
        self::assertSame(RunOutcome::Reported, $second->outcome);
        self::assertNotSame([], $h->analyzer->requests);
        self::assertNotSame([], $h->notifier->sent);

        foreach ($h->analyzer->allRequests() as $req) {
            foreach ($req->groups as $g) {
                self::assertSame([], array_diff(array_keys($g->toArray()), self::ALLOWED_KEYS));
            }
        }

        self::assertInstanceOf(InMemoryStateStore::class, $h->state);
        $payloads = [
            'analyzer request' => $h->analyzer->allRequests(),
            'notifier message' => array_map(static fn (array $s) => $s['message'], $h->notifier->sent),
            'state store' => $h->state->all(),
        ];
        foreach ($payloads as $where => $payload) {
            // Both the readable and the \u-escaped encodings, so Cyrillic and '/' cannot hide a value.
            $haystack = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                . json_encode($payload, JSON_THROW_ON_ERROR);
            foreach ($seeded as $value) {
                self::assertStringNotContainsString($value, $haystack, "seeded value leaked into $where: $value");
            }
        }
    }

    /** P1-P4: frame paths, digits in identifiers, digit-based extra patterns and multi-line JSON messages, end to end. */
    public function testPathsIdentifierDigitsExtraPatternsAndJsonTailsNeverLeave(): void
    {
        /** @var list<string> $seeded */
        $seeded = require __DIR__ . '/../Fixtures/privacy/seeded-values.php';
        $events = self::eventsFrom('corpus.log');
        $h = new Harness(['redaction' => ['extra_patterns' => ['cust' => '/CUST-\d{6}/']]]);

        $h->watcher(new FakeLogSource(Harness::totalsOf($events), $events))->run();
        $h->nextWindow();
        $h->watcher(new FakeLogSource(Harness::totalsOf($events), $events))->run();

        $payload = json_encode([$h->analyzer->allRequests(), array_map(static fn (array $s) => $s['message'], $h->notifier->sent)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        foreach ($seeded as $value) {
            self::assertStringNotContainsString($value, $payload, "seeded value leaked: $value");
        }
        // Controls: the safe parts are present, so the absence above is the rewrite and not an empty payload.
        self::assertStringContainsString('…/app/Sync.php:12', $payload);
        self::assertStringContainsString('FkTenant<N>Exception', $payload);
        self::assertStringContainsString('Invoice <CUST> rejected', $payload);
        // Paths written inside messages: the same user and customer directory in every event must not reach a template.
        self::assertStringContainsString('<PATH>/…/Exports/Report.csv', $payload);
        self::assertStringContainsString('/Users/<USER>', $payload);
        self::assertStringContainsString('<PATH>/…/<USER>/Desktop', $payload);
    }

    /** @return list<\Watcher\Source\LogEvent> */
    private static function eventsFrom(string $file): array
    {
        $parser = new LineParser();
        $events = [];
        foreach (file(__DIR__ . '/../Fixtures/privacy/' . $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $events[] = $parser->parse($line);
        }

        return $events;
    }

    /** US6 (T097): dependencies, deploy block, links, repo and service context all configured; still no seeded value anywhere. */
    public function testNoSeededValueLeaksThroughDependencyContextLinksOrServiceContext(): void
    {
        /** @var list<string> $seeded */
        $seeded = require __DIR__ . '/../Fixtures/privacy/seeded-values.php';
        $own = self::eventsFrom('corpus.log');
        $dep = self::eventsFrom('dependency.log');
        $h = new Harness([
            'service_context' => trim((string) file_get_contents(__DIR__ . '/../Fixtures/privacy/service-context.txt')),
            'dependencies' => ['billing-gateway' => ['stream_tags' => ['app' => 'billing-gateway', 'env' => 'stg']]],
            'deploy' => ['release' => 'v2026.10.8', 'commit' => 'abc123def456'],
            'links' => ['grafana' => ['base_url' => 'https://grafana.test', 'datasource_uid' => 'loki-uid']],
            'repo' => ['provider' => 'gitlab', 'web_url' => 'https://git.test/acme/svc', 'path_map' => ['/var/www/html/' => 'app/']],
        ]);
        $source = static fn (): RoutedLogSource => new RoutedLogSource(
            ['billing-gateway' => new FakeLogSource(Harness::totalsOf($dep), $dep)],
            new FakeLogSource(Harness::totalsOf($own), $own),
        );

        $h->watcher($source())->run();
        $h->nextWindow();
        $result = $h->watcher($source())->run();
        self::assertSame(RunOutcome::Reported, $result->outcome);

        $depRead = false;
        foreach ($h->analyzer->requests as $req) {
            $depRead = $depRead || $req->dependencyContext !== [];
        }
        self::assertTrue($depRead, 'control: the dependency part must actually be present in the analysis request');
        self::assertInstanceOf(InMemoryStateStore::class, $h->state);

        $encoded = [];
        foreach ($h->analyzer->allRequests() as $req) {
            $encoded[] = $req->serviceContext;
            $encoded[] = $req instanceof AnalysisRequest ? CompactPayload::encode($req->groups, $req->dependencyContext) : CompactPayload::encode($req->groups);
        }
        $links = [];
        foreach ($h->notifier->sent as $s) {
            $links[] = $s['message']->text;
            $links[] = implode("\n", array_column($s['message']->sections, 'text'));
        }
        $payloads = [
            'analyzer request' => [$h->analyzer->allRequests(), $encoded],
            'notifier message' => [array_map(static fn (array $s) => $s['message'], $h->notifier->sent), $links],
            'state store' => $h->state->all(),
        ];
        foreach ($payloads as $where => $payload) {
            $plain = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $views = [$plain, json_encode($payload, JSON_THROW_ON_ERROR), rawurldecode($plain), rawurldecode(rawurldecode($plain))];
            foreach ($views as $haystack) {
                foreach ($seeded as $value) {
                    self::assertStringNotContainsString($value, $haystack, "seeded value leaked into $where: $value");
                }
            }
        }
        // The service context really was sent, minus its e-mail.
        self::assertStringContainsString('<EMAIL>', $h->analyzer->requests[0]->serviceContext);
        self::assertStringContainsString('grafana.test/explore', implode("\n", $links));
    }

    private const LAYER2_VALUES = ['CUST-QWERTY', 'ACC-MNBVCX'];

    /** @param array<string, mixed> $patch */
    private function runLayer2(array $patch): Harness
    {
        $parser = new LineParser();
        $events = [];
        foreach (file(__DIR__ . '/../Fixtures/privacy/layer2.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $events[] = $parser->parse($line);
        }
        $h = new Harness($patch);
        $h->watcher(new FakeLogSource(Harness::totalsOf($events), $events))->run();
        $h->nextWindow();
        $result = $h->watcher(new FakeLogSource(Harness::totalsOf($events), $events))->run();
        self::assertSame(RunOutcome::Reported, $result->outcome);

        return $h;
    }

    private static function haystack(Harness $h): string
    {
        self::assertInstanceOf(InMemoryStateStore::class, $h->state);
        $payload = [
            $h->analyzer->allRequests(),
            array_map(static fn (array $s) => $s['message'], $h->notifier->sent),
            $h->state->all(),
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function testLayer2ExtraPatternsCatchWhatTheNormalizerTableDoesNotKnow(): void
    {
        // Control: without extra_patterns the values survive layer 1, proving the fixture is not vacuous.
        $control = self::haystack($this->runLayer2([]));
        foreach (self::LAYER2_VALUES as $value) {
            self::assertStringContainsString($value, $control, "control run should leak $value");
        }

        $patterns = ['customer' => '/CUST-[A-Z0-9]{6}/', 'account' => '/ACC-[A-Z0-9]{6,}/'];
        $h = $this->runLayer2(['redaction' => ['extra_patterns' => $patterns]]);
        $haystack = self::haystack($h);
        foreach (self::LAYER2_VALUES as $value) {
            self::assertStringNotContainsString($value, $haystack, "layer 2 leaked $value");
        }
        self::assertStringContainsString('<CUSTOMER>', $haystack);
        self::assertStringContainsString('<ACCOUNT>', $haystack);
    }

    public function testModelOutputIsScrubbedBeforeRenderingAndStorage(): void
    {
        $h = new Harness();
        $analyzer = new LeakyAnalyzer();
        $events = Harness::events(\Watcher\Level::Error, 'Disk quota exceeded', 4);
        $make = fn (): Watcher => new Watcher(WatchConfig::fromArray($h->config()), new FakeLogSource(Harness::totalsOf($events), $events), $h->state, $analyzer, $h->notifier, $h->clock);
        $make()->run();
        $h->nextWindow();
        self::assertSame(RunOutcome::Reported, $make()->run()->outcome);

        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl';
        $text = str_replace("\u{200B}", '', implode("\n", $h->sentTexts())); // the renderer defangs emails with zero-width spaces
        $text = html_entity_decode($text); // the renderer escapes < and > for Slack
        self::assertStringContainsString('<EMAIL>', $text);
        self::assertStringContainsString('<TOKEN>', $text);
        self::assertStringContainsString('[withheld]', $text);
        self::assertInstanceOf(InMemoryStateStore::class, $h->state);
        $all = $text . json_encode($h->state->all(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        foreach (['anna.kowalski@example.com', $jwt] as $raw) {
            self::assertStringNotContainsString($raw, $all);
        }
    }

    private const TOKEN = '987654:ZYXwvuTSRqponMLKjihGFEdcbaZYXwvu01234';
    private const CHAT = '-1009876543210';
    private const HOOK = 'https://discord.com/api/webhooks/987654321098765432/SEEDEDhookTOKEN_xyz-789';

    /** @return array<string, array{array<string, mixed>}> */
    public static function drivers(): array
    {
        return [
            'slack' => [['driver' => 'slack', 'webhook' => 'https://hooks.slack.test/services/T9/B9/SEEDEDSLACKSECRET99']],
            'telegram' => [['driver' => 'telegram', 'bot_token' => self::TOKEN, 'chat_id' => self::CHAT]],
            'discord' => [['driver' => 'discord', 'webhook' => self::HOOK]],
        ];
    }

    /**
     * SC-001: seeded personal values and credentials appear nowhere, whichever channel carries the digest.
     *
     * @param array<string, mixed> $destination
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('drivers')]
    public function testNoSeededValueOrCredentialSurfacesForAnyDriver(array $destination): void
    {
        /** @var list<string> $seeded */
        $seeded = require __DIR__ . '/../Fixtures/privacy/seeded-values.php';
        $events = self::eventsFrom('corpus.log');
        $log = new \Watcher\Tests\Fakes\SpyLogger();
        $h = new Harness(['notify' => ['digest' => $destination]]);
        $h->hostLog = $log;

        $h->watcher(new FakeLogSource(Harness::totalsOf($events), $events))->run();
        $h->nextWindow();
        $reported = $h->watcher(new FakeLogSource(Harness::totalsOf($events), $events))->run();
        $h->nextWindow();
        $h->analyzer->failure = new \Watcher\Analyze\AnalyzerFailed('provider said ' . implode(' ', array_filter($destination, static fn (string $k): bool => $k !== 'driver', ARRAY_FILTER_USE_KEY)));
        $failed = $h->watcher(new FakeLogSource(Harness::totalsOf($events), $events))->run();

        self::assertSame(RunOutcome::Reported, $reported->outcome);
        self::assertSame(RunOutcome::Failed, $failed->outcome);
        self::assertInstanceOf(InMemoryStateStore::class, $h->state);
        $surfaces = [
            'messages' => array_map(static fn (array $s): array => [$s['message'], $s['to']], $h->notifier->sent),
            'state' => $h->state->all(),
            'host log' => $log->records,
            'summary' => [$reported->summary(), $failed->summary()],
            'failure reason' => $failed->failureReason,
        ];
        $needles = [...$seeded, ...array_values(array_filter($destination, static fn (string $k): bool => $k !== 'driver', ARRAY_FILTER_USE_KEY))];
        foreach ($surfaces as $where => $payload) {
            $plain = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            foreach ([$plain, json_encode($payload, JSON_THROW_ON_ERROR)] as $haystack) {
                foreach ($needles as $value) {
                    self::assertStringNotContainsString((string) $value, $haystack, "leaked into $where: " . substr((string) $value, 0, 6));
                }
            }
        }
        self::assertStringContainsString('analysis failed', implode("\n", $h->sentTexts()), 'control: the failure notice was posted, and masked');
    }
}

/** Answers like a model that echoes personal data back (defence-in-depth case). */
final class LeakyAnalyzer implements Analyzer
{
    public function triage(TriageRequest $req): TriageResult
    {
        return new TriageResult(array_fill_keys(array_map(static fn ($g): string => $g->fingerprint, $req->groups), 'interesting'));
    }

    public function analyze(AnalysisRequest $req): AnalysisResult
    {
        $findings = array_map(static fn ($g): Finding => new Finding(
            [$g->fingerprint],
            FindingKind::Bug,
            Severity::Error,
            'Quota problem',
            'Seen for anna.kowalski@example.com with token eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl',
            'a full disk',
            "free space \xFF",
            0.9,
        ), $req->groups);

        return new AnalysisResult($findings);
    }
}
