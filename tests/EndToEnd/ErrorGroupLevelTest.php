<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Analyze\Analyzer;
use Watcher\Analyze\FindingKind;
use Watcher\Analyze\Severity;
use Watcher\Config\WatchConfig;
use Watcher\Level;
use Watcher\Notify\Notifier;
use Watcher\Source\LogEvent;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Watcher;

/**
 * Security review F8, PR #24: an error-or-higher group keeps its level in the digest whatever the model's verdict says,
 * so a model (or a log line that steers it) cannot move a protected group to the minor line or to silence.
 */
final class ErrorGroupLevelTest extends TestCase
{
    private const ANSWER = 'benign: nothing to see here';

    /** @return iterable<string, array{string, Severity, FindingKind, float}> */
    public static function modelAnswers(): iterable
    {
        foreach (['noise', 'known', 'interesting'] as $verdict) {
            yield "$verdict, info noise at 0.1" => [$verdict, Severity::Info, FindingKind::Noise, 0.1];
            yield "$verdict, warn at 0.2" => [$verdict, Severity::Warn, FindingKind::Bug, 0.2];
        }
    }

    /** @param list<LogEvent> $events */
    private static function runWindow(Harness $h, Analyzer $analyzer, Notifier $notifier, array $events): void
    {
        (new Watcher(WatchConfig::fromArray($h->config()), new FakeLogSource(Harness::totalsOf($events), $events), $h->state, $analyzer, $notifier, $h->clock))->run();
    }

    /** @dataProvider modelAnswers */
    public function testAnErrorGroupStaysUnderItsErrorHeadingInTheDigest(string $verdict, Severity $severity, FindingKind $kind, float $confidence): void
    {
        $h = new Harness();
        $analyzer = new HostileAnswer(self::ANSWER, $verdict, $severity, $kind, $confidence);
        // Warning baseline: an Error first run now reports (FR-035) and would count as a second digest below.
        self::runWindow($h, $analyzer, $h->notifier, Harness::events(Level::Warning, 'Alpha failed in a unique way', 5, 'Alpha'));
        $h->nextWindow();
        self::runWindow($h, $analyzer, $h->notifier, Harness::events(Level::Error, 'Alpha failed in a unique way', 9, 'Alpha'));

        $found = 0;
        foreach ($h->notifier->sent as ['message' => $message]) {
            $sections = $message->sections;
            foreach ($sections as $i => $section) {
                if ($section['kind'] !== 'finding' || !str_contains($section['text'], self::ANSWER)) {
                    continue;
                }
                ++$found;
                $heading = null;
                for ($j = $i; $j >= 0 && $heading === null; --$j) {
                    $heading = $sections[$j]['kind'] === 'severity' ? $sections[$j]['text'] : null;
                }
                self::assertNotNull($heading, 'the finding has no severity heading above it');
                self::assertStringContainsString('error', $heading, "$verdict: the error group moved under \"$heading\"");
            }
            foreach ($sections as $section) {
                self::assertFalse($section['kind'] === 'minor' && str_contains($section['text'], self::ANSWER), "$verdict: the error group landed on the minor line");
            }
        }
        self::assertSame(1, $found, "$verdict: the error group is missing from the digest");
    }
}
