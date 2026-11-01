<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Spec 006 guards (SC-005, Principles I, II, IV): hygiene measures in code, calls no model, writes only its own state. */
final class HygieneArchitectureTest extends TestCase
{
    private const DIR = __DIR__ . '/../../src/Hygiene';

    /** @return list<string> */
    private static function files(): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::DIR, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f instanceof \SplFileInfo && $f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    /** @return list<string> */
    private static function grep(string $pattern): array
    {
        $hits = [];
        foreach (self::files() as $file) {
            foreach (file($file) ?: [] as $i => $line) {
                if (preg_match($pattern, $line) === 1) {
                    $hits[] = basename($file) . ':' . ($i + 1) . ': ' . trim($line);
                }
            }
        }

        return $hits;
    }

    public function testThereAreHygieneFilesToCheck(): void
    {
        self::assertGreaterThan(15, count(self::files()));
    }

    public function testNoCodeReachesAModelProviderOrAnHttpClient(): void
    {
        self::assertSame([], self::grep('/^use (GuzzleHttp|Psr\\\\Http)/'));
        self::assertSame([], self::grep('/ProviderAnalyzer|->triage\(|->analyze\(/'));
    }

    public function testTheRunPathIsNotInvolvedExceptThroughItsStaticKeys(): void
    {
        $uses = array_map(static fn (string $h): string => explode(':', $h)[0], self::grep('/^use Watcher\\\\Watcher;/'));
        self::assertSame([], $uses);
        self::assertSame([], self::grep('/new Watcher\(|Watcher::fromConfig/'));
    }

    public function testNothingIsPrintedLoggedOrWrittenToDisk(): void
    {
        self::assertSame([], self::grep('/\b(echo|print|printf|var_dump|print_r|error_log|fwrite|fputs|file_put_contents|unlink|rename)\b\s*\(?/'));
        self::assertSame([], self::grep('/\$_(SERVER|ENV|GET|POST)|getenv\(|process\.env/'));
    }

    public function testNoLogLineOrFieldIsEverInterpolatedIntoReportText(): void
    {
        // Report text is built from report fields only; the scanner is the single place that sees events.
        $seeing = array_filter(self::files(), static fn (string $f): bool => (string) preg_match('/LogEvent/', (string) file_get_contents($f)) === '1');
        self::assertSame(['HygieneScanner.php', 'SiteId.php'], array_map('basename', array_values($seeing)));
    }

}
