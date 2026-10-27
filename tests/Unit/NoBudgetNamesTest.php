<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Repository guard (plan 017): no budget, price or cost name in the shipped tree. Red until T070. */
final class NoBudgetNamesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private const NEEDLES = ['usd', 'Usd', 'USD', 'CostCalculator', 'UnknownModelPrice', 'cost_usd', 'daily_budget', 'run_budget', 'daily_token_limit', 'tokens_today', 'WATCHER_PRICE'];
    private const SKIP = ['CHANGELOG.md', 'audit-2026-10-23.md', 'review-2026-10-23.md', 'review-fixes-2026-10-25.md', 'brief.md', 'roadmap.md'];

    public function testNoBudgetOrCostNameInSourceDocsOrExamples(): void
    {
        $hits = [];
        foreach (['src', 'docs'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file->isFile() || in_array($file->getFilename(), self::SKIP, true)) {
                    continue;
                }
                $text = (string) file_get_contents($file->getPathname());
                foreach (self::NEEDLES as $needle) {
                    if (str_contains($text, $needle)) {
                        $hits[] = substr($file->getPathname(), strlen(self::ROOT) + 1) . ' contains ' . $needle;
                    }
                }
            }
        }

        self::assertSame([], $hits);
    }
}
