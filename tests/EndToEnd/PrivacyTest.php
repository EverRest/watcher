<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\RunOutcome;
use Watcher\Source\LineParser;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\InMemoryStateStore;

/** Principle I, layer 1, end to end: no seeded personal value reaches any outbound payload or stored item. */
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

        $h = new Harness(); // serviceContext stays empty: its redaction scan arrives with US6
        $first = $h->watcher(new FakeLogSource(Harness::totalsOf($events), $events))->run();
        $h->nextWindow();
        $second = $h->watcher(new FakeLogSource(Harness::totalsOf($events), $events))->run();

        self::assertSame(RunOutcome::Baselined, $first->outcome);
        self::assertSame(RunOutcome::Reported, $second->outcome);
        self::assertNotSame([], $h->analyzer->requests);
        self::assertNotSame([], $h->notifier->sent);

        foreach ($h->analyzer->requests as $req) {
            foreach ($req->groups as $g) {
                self::assertSame([], array_diff(array_keys($g->toArray()), self::ALLOWED_KEYS));
            }
        }

        self::assertInstanceOf(InMemoryStateStore::class, $h->state);
        $payloads = [
            'analyzer request' => $h->analyzer->requests,
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
}
