<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Source\LineParser;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\InMemoryStateStore;

/** Lines past the parser's caps: the part the cut removes, and the part it cannot read, never leave. */
final class LongLinePrivacyTest extends TestCase
{
    private const SEEDED = ['early.leak@example.org', 'late.leak@example.org', 'Zk8Qw3Rt5Yu7', 'hunterxyz99', 'cut.leak@example.org'];

    /** @return list<string> */
    private static function lines(): array
    {
        $out = [];
        for ($i = 0; $i < 3; ++$i) {
            $ts = sprintf('[2026-10-08T10:%02d:01+00:00]', $i);
            $out[] = $ts . ' production.ERROR: [LONG]: boom now {"email":"early.leak@example.org","pad":"' . str_repeat('p', 9000) . '","t":"late.leak@example.org","secret":"Zk8Qw3Rt5Yu7"} []';
            $out[] = $ts . ' production.ERROR: [LONG]: boom now ["early.leak@example.org","' . str_repeat('p', 9000) . '","late.leak@example.org"] []';
            $out[] = '{"level":"error","message":"early.leak@example.org","pad":"' . str_repeat('p', 300000) . '","t":"late.leak@example.org"}';
            // The cut falls inside `password=hunterxyz99` (a plain line, no timestamp): the partial key must not be sent either.
            $out[] = 'ERROR plain overflow ' . str_repeat('x ', intdiv(LineParser::MAX_LINE - 30, 2)) . 'cut.leak@example.org password=hunterxyz99 end';
        }

        return $out;
    }

    public function testNothingPastTheCapLeaves(): void
    {
        $parser = new LineParser();
        $events = array_map(static fn (string $l) => $parser->parse($l), self::lines());
        $h = new Harness();
        $h->watcher(new FakeLogSource(Harness::totalsOf($events), $events))->run();
        $h->nextWindow();
        $h->watcher(new FakeLogSource(Harness::totalsOf($events), $events))->run();

        self::assertNotSame([], $h->analyzer->requests);
        $payload = json_encode([$h->analyzer->allRequests(), array_map(static fn (array $s) => $s['message'], $h->notifier->sent), $h->state instanceof InMemoryStateStore ? $h->state->all() : []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        foreach (self::SEEDED as $value) {
            self::assertStringNotContainsString($value, $payload, $value);
        }
        self::assertStringContainsString('boom now', $payload); // control: the message itself is sent
    }
}
