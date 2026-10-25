<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Watcher\Hygiene\HygieneOptions;

/** Principle I for spec 006 (SC-001): no seeded personal value reaches any hygiene output or stored aggregate. */
final class HygienePrivacyTest extends TestCase
{
    /** @return list<string> */
    private static function seeded(): array
    {
        /** @var list<string> $values */
        $values = require __DIR__ . '/../Fixtures/privacy/seeded-values.php';

        return $values;
    }

    /** @param array<string, mixed> $payloads where => anything json-encodable */
    private static function assertNoSeeded(array $payloads): void
    {
        foreach ($payloads as $where => $payload) {
            // Readable and \u-escaped, so Cyrillic and '/' cannot hide a value.
            $haystack = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                . json_encode($payload, JSON_THROW_ON_ERROR);
            foreach (self::seeded() as $value) {
                self::assertStringNotContainsString($value, $haystack, "seeded value leaked into $where: $value");
            }
        }
    }

    public function testNoSeededValueReachesAnyOutputOrStoredRecord(): void
    {
        $events = [...HygieneHarness::fixture('hygiene.log'), ...HygieneHarness::fixture('corpus.log')];
        $h = new HygieneHarness();
        $texts = [];
        $jsons = [];
        for ($day = 0; $day < 16; ++$day) {
            $result = $h->hygiene(HygieneHarness::source($events))->run(new HygieneOptions(notify: true));
            $texts[] = $result->text();
            $jsons[] = $result->toJson();
            $h->clock->advance('+24 hours');
        }

        self::assertNotSame([], $h->notifier->sent, 'the run must produce something, or this test proves nothing');
        $last = json_decode($jsons[15], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(0, $last['reports']['proposals']['days_needed'], 'sixteen days of history: proposals are computed');
        self::assertNotSame([], $last['reports']['privacy']['sites']);
        self::assertStringContainsString('AuthService.php', $texts[0], 'the seeded call site is named by its identifier');
        self::assertNoSeeded([
            'text' => $texts,
            'json' => $jsons,
            'notices' => array_map(static fn (array $s): mixed => $s['message'], $h->notifier->sent),
            'state' => $h->state->all(),
        ]);
    }

    public function testTheCheckSeesALeak(): void
    {
        $this->expectException(AssertionFailedError::class);
        self::assertNoSeeded(['control' => ['evidence' => 'mailed ' . self::seeded()[0]]]);
    }

    public function testAFileNamedAfterAPersonIsNeverASiteId(): void
    {
        // Review B-2 proof of concept: a `file` context key that names a person must fall back to the fingerprint.
        $files = ['/var/www/html/app/public/files/john_smith.ts', '/var/www/html/app/data/john_smith_passport.pdf.php', '/var/www/html/app/Customers/anna_kowalski.pdf.php'];
        $events = [];
        foreach ($files as $file) {
            foreach (['a', 'b', 'c'] as $who) {
                $events[] = HygieneHarness::event(\Watcher\Level::Info, "Imported for $who.person@example.org", ['file' => $file, 'line' => 1]);
            }
        }
        $h = new HygieneHarness();

        $result = $h->hygiene(HygieneHarness::source($events))->run(new HygieneOptions(reports: ['privacy'], notify: true));

        $privacy = $result->reports['privacy']->toArray();
        self::assertNotSame([], $privacy['sites'], 'the sites are reported, or this proves nothing');
        foreach ($privacy['sites'] as $site) {
            self::assertSame('template', $site['kind']);
        }
        foreach (['john_smith', 'kowalski', 'passport'] as $needle) {
            self::assertStringNotContainsString($needle, $result->text() . $result->toJson() . json_encode($h->notifier->sent[0]['message']) . json_encode($h->state->all()), $needle);
        }
    }
}
