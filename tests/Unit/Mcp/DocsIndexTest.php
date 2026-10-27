<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Mcp\DocsAllowlist;
use Watcher\Mcp\DocsIndex;
use Watcher\Mcp\InvalidParams;
use Watcher\Mcp\SecretGuard;
use Watcher\Mcp\Tools\ExplainConfigTool;
use Watcher\Mcp\Tools\ExplainOutcomeTool;
use Watcher\Mcp\Tools\SearchDocsTool;

final class DocsIndexTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    public function testAllowlistedFilesShipInTheComposerArchive(): void
    {
        $ignored = (string) file_get_contents(self::ROOT . '/.gitattributes');
        foreach (array_keys(DocsAllowlist::FILES) as $f) {
            self::assertStringNotContainsString('/' . $f . ' export-ignore', $ignored, $f);
            self::assertFalse(str_contains($ignored, '/' . dirname($f) . ' export-ignore') && dirname($f) !== '.', $f);
        }
    }

    public function testReadReturnsTheBytesUnchanged(): void
    {
        $r = (new DocsIndex(self::ROOT))->read('watcher-docs://docs/configuration.md');
        self::assertSame(file_get_contents(self::ROOT . '/docs/configuration.md'), $r['text']);
        self::assertSame('text/markdown', $r['mimeType']);
    }

    /** @return array<string, array{string}> */
    public static function badUris(): array
    {
        $u = static fn (string $s): array => [$s];

        return [
            'traversal' => $u('watcher-docs://../.env'), 'dotdot inside' => $u('watcher-docs://docs/../.env'), 'percent' => $u('watcher-docs://%2e%2e/.env'),
            'absolute' => $u('watcher-docs:///etc/passwd'), 'backslash' => $u('watcher-docs://docs\\privacy.md'), 'nul' => $u("watcher-docs://README.md\0"),
            'other scheme' => $u('file:///etc/passwd'), 'case' => $u('watcher-docs://README.MD'), 'prefix of allowed' => $u('watcher-docs://docs/privacy'),
            'longer than allowed' => $u('watcher-docs://docs/privacy.md.bak'), 'maintainer file' => $u('watcher-docs://docs/roadmap.md'), 'env' => $u('watcher-docs://.env'),
            'empty' => $u('watcher-docs://'), 'plain path' => $u('docs/privacy.md'),
        ];
    }

    /** @dataProvider badUris */
    public function testHostileUrisAreResourceNotFound(string $uri): void
    {
        try {
            (new DocsIndex(self::ROOT))->read($uri);
            self::fail('read');
        } catch (InvalidParams $e) {
            self::assertSame('resource_not_found', $e->getMessage());
        }
    }

    public function testAStrippedRootGivesAShorterListAndNoFailure(): void
    {
        $tmp = sys_get_temp_dir() . '/mcp-docs-' . bin2hex(random_bytes(3));
        mkdir($tmp . '/docs', 0755, true);
        file_put_contents($tmp . '/README.md', "# Hi\nloki proxy\n");
        file_put_contents($tmp . '/docs/privacy.md', str_repeat('a', DocsAllowlist::MAX_BYTES + 1));
        $i = new DocsIndex($tmp);
        self::assertSame(['README.md'], $i->files());
        self::assertCount(1, $i->resources());
        self::assertNull((new DocsIndex($tmp . '/none'))->search('loki', 5));
        self::assertSame(['available' => false, 'reason' => 'docs_not_installed'], (new SearchDocsTool(new DocsIndex($tmp . '/none')))->call(['query' => 'loki']));
        exec('rm -rf ' . escapeshellarg($tmp));
    }

    public function testSearchIsDeterministicBoundedAndAnchorsExist(): void
    {
        $i = new DocsIndex(self::ROOT);
        $t0 = microtime(true);
        $r = $i->search('loki proxy url', 5);
        self::assertLessThan(1.0, microtime(true) - $t0);
        self::assertNotNull($r);
        self::assertNotSame([], $r);
        self::assertLessThanOrEqual(5, count($r));
        self::assertSame($r, $i->search('loki proxy url', 5));
        self::assertSame($r, (new DocsIndex(self::ROOT))->search('loki proxy url', 5));
        $scores = array_column($r, 'score');
        $sorted = $scores;
        rsort($sorted);
        self::assertSame($sorted, $scores);
        foreach ($r as $hit) {
            self::assertLessThanOrEqual(240, mb_strlen($hit['snippet']));
            if ($hit['anchor'] !== null) {
                $anchors = [];
                foreach (explode("\n", (string) file_get_contents(self::ROOT . '/' . $hit['file'])) as $l) {
                    if (preg_match('/^#{1,4} +(.+?)\s*#*$/', $l, $m) === 1) {
                        $anchors[] = DocsIndex::slug($m[1]);
                    }
                }
                self::assertContains($hit['anchor'], $anchors);
            }
        }
        self::assertCount(10, (array) $i->search('the', 10));
    }

    public function testTooManyTermsAndEmptyQueriesAreInvalid(): void
    {
        foreach (['a b c d e f g h i', '!!', '?'] as $q) {
            try {
                (new DocsIndex(self::ROOT))->search($q, 5);
                self::fail($q);
            } catch (InvalidParams $e) {
                self::assertSame('invalid params: query', $e->getMessage());
            }
        }
    }

    public function testNoServedFileHoldsASecretShapedString(): void
    {
        $g = new SecretGuard();
        $this->addToAssertionCount(1);
        foreach ((new DocsIndex(self::ROOT))->files() as $f) {
            foreach (preg_split('/\s+/', (string) file_get_contents(self::ROOT . '/' . $f)) ?: [] as $word) {
                if (strlen($word) >= 20 && preg_match('/^[A-Za-z0-9_+\/=-]+$/', $word) === 1 && preg_match('/[A-Z]/', $word) === 1 && preg_match('/\d/', $word) === 1 && preg_match('/[a-z]/', $word) === 1) {
                    self::assertFalse($g->looksSecret($word), "$f: $word");
                }
            }
        }
    }

    public function testExplainToolsAnswerFromTheTables(): void
    {
        $c = (new ExplainConfigTool())->call(['key' => 'selection.growth_factor']);
        self::assertTrue($c['found']);
        self::assertSame('float', $c['type']);
        $u = (new ExplainConfigTool())->call(['key' => 'selection.min_levl']);
        self::assertSame(['found' => false, 'did_you_mean' => ['selection.min_level']], $u);
        self::assertTrue((new ExplainConfigTool())->call(['key' => 'analysis.api_keys.gemini'])['secret']);
        $o = (new ExplainOutcomeTool())->call(['code' => 'skipped_done']);
        self::assertSame(0, $o['exit_code']);
        self::assertSame(['found' => false], (new ExplainOutcomeTool())->call(['code' => 'made_up']));
        $all = (new ExplainOutcomeTool())->call([]);
        self::assertGreaterThan(30, count($all['codes']));
        self::assertSame(['code', 'kind', 'severity'], array_keys($all['codes'][0]));
    }
}
