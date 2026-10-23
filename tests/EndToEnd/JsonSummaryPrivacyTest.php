<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Operate\JsonSummary;
use Watcher\Operate\RunStats;
use Watcher\Operate\RunSummaryData;
use Watcher\RunOutcome;
use Watcher\Source\LineParser;
use Watcher\Tests\Fakes\FakeLogSource;

/** Principle I: the one-line JSON summary of a run over the privacy corpus carries none of the seeded values. */
final class JsonSummaryPrivacyTest extends TestCase
{
    public function testSummaryOfACorpusRunContainsNoSeededValue(): void
    {
        $dir = __DIR__ . '/../Fixtures/privacy';
        /** @var list<string> $seeded */
        $seeded = require $dir . '/seeded-values.php';
        $parser = new LineParser();
        $events = [];
        foreach (file($dir . '/corpus.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $events[] = $parser->parse($line);
        }
        $h = new Harness();
        $h->watcher(new FakeLogSource(Harness::totalsOf($events), $events))->run();
        $h->nextWindow();
        $result = $h->watcher(new FakeLogSource(Harness::totalsOf($events), $events))->run();
        self::assertSame(RunOutcome::Reported, $result->outcome);
        self::assertNotSame([], $h->analyzer->requests);

        $stats = new RunStats();
        $stats->eventsRead = count($events);
        if ($result->usage !== null) {
            $stats->addUsage('analyze', $result->usage);
        }
        // Worst case: the failure reason is a raw message holding every seeded value.
        foreach ([null, implode(' ', $seeded)] as $reason) {
            $line = (new JsonSummary())->render(new RunSummaryData($result->outcome, 'svc', $reason, null, null, $stats, 0.1, 'v1'));
            foreach ($seeded as $value) {
                self::assertStringNotContainsString($value, $line);
                self::assertStringNotContainsString(substr(json_encode($value, JSON_THROW_ON_ERROR), 1, -1), $line);
            }
        }
    }
}
