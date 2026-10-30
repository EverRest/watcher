<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Hygiene\HygieneSettings;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;
use Watcher\Level;

final class HygieneConfigTest extends TestCase
{
    /** @param mixed $hygiene null leaves the section out */
    private static function config(mixed $hygiene): WatchConfig
    {
        return WatchConfig::fromArray([
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.example']],
            'analysis' => [
                'api_keys' => ['gemini' => 'k'],
                'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],

            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/x']],
            ...($hygiene === null ? [] : ['hygiene' => $hygiene]),
        ]);
    }

    public function testTheLabelPrivacyKnobsHaveSafeDefaultsAndValidate(): void
    {
        $h = HygieneSettings::fromArray([]);
        self::assertFalse($h->allowConstantLabels);
        self::assertSame(5, $h->literalMinSites);
        $h = HygieneSettings::fromArray(['allow_constant_labels' => true, 'literal_min_sites' => 8]);
        self::assertTrue($h->allowConstantLabels);
        self::assertSame(8, $h->literalMinSites);
        foreach ([['allow_constant_labels' => 'yes'], ['literal_min_sites' => 1], ['literal_min_sites' => 'x']] as $bad) {
            try {
                HygieneSettings::fromArray($bad);
                self::fail('expected InvalidConfig');
            } catch (\Watcher\Config\InvalidConfig $e) {
                self::assertStringContainsString('hygiene.' . array_key_first($bad), $e->getMessage());
            }
        }
    }

    public function testItIsOffWithDocumentedDefaultsWhenOmitted(): void
    {
        $h = self::config(null)->hygiene;

        self::assertFalse($h->enabled);
        self::assertSame(Level::Info, $h->readMinLevel);
        self::assertSame(200000, $h->eventCap);
        self::assertSame(2000, $h->maxLineLength);
        self::assertSame(0.20, $h->noisyShare);
        self::assertSame(20, $h->variableDistinct);
        self::assertSame(7, $h->repeatDays);
        self::assertSame(2.0, $h->growthFactor);
        self::assertSame(14, $h->minHistoryDays);
        self::assertSame(100, $h->muteMinDaily);
        self::assertSame(1800, $h->watchLead);
        self::assertSame(90, $h->declineDays);
        self::assertSame(2.0, $h->spikeFactor);
        self::assertSame(900, $h->maxSeconds);
    }

    public function testEveryKeyCanBeSet(): void
    {
        $h = self::config([
            'enabled' => true, 'read_min_level' => 'debug', 'event_cap' => 10, 'max_line_length' => 80, 'noisy_share' => 0.5,
            'variable_distinct' => 5, 'repeat_days' => 1, 'growth_factor' => 1.5, 'min_history_days' => 5, 'mute_min_daily' => 7,
            'watch_lead' => 60, 'decline_days' => 30, 'spike_factor' => 3, 'max_seconds' => 60,
        ])->hygiene;

        self::assertTrue($h->enabled);
        self::assertSame(Level::Debug, $h->readMinLevel);
        self::assertSame(10, $h->eventCap);
        self::assertSame(0.5, $h->noisyShare);
        self::assertSame(1.5, $h->growthFactor);
        self::assertSame(3.0, $h->spikeFactor);
        self::assertSame(30, $h->declineDays);
    }

    /** @return iterable<string, array{array<array-key, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'unknown key' => [['nope' => 1], 'hygiene.nope'];
        yield 'enabled not bool' => [['enabled' => 'yes'], 'hygiene.enabled'];
        yield 'level typo' => [['read_min_level' => 'inf'], 'hygiene.read_min_level'];
        yield 'k-anonymity floor' => [['variable_distinct' => 4], 'hygiene.variable_distinct'];
        yield 'budget zero' => [['max_seconds' => 0], 'hygiene.max_seconds'];
        yield 'cap zero' => [['event_cap' => 0], 'hygiene.event_cap'];
        yield 'cap string' => [['event_cap' => '10'], 'hygiene.event_cap'];
        yield 'share zero' => [['noisy_share' => 0], 'hygiene.noisy_share'];
        yield 'share above one' => [['noisy_share' => 1.5], 'hygiene.noisy_share'];
        yield 'growth one' => [['growth_factor' => 1], 'hygiene.growth_factor'];
        yield 'spike one' => [['spike_factor' => 1.0], 'hygiene.spike_factor'];
        yield 'history zero' => [['min_history_days' => 0], 'hygiene.min_history_days'];
        yield 'allow dirs not a list' => [['site_allow_dirs' => 'data'], 'hygiene.site_allow_dirs'];
        yield 'allow dir with a slash' => [['site_allow_dirs' => ['app/data']], 'hygiene.site_allow_dirs'];
        yield 'allow dir not a string' => [['site_allow_dirs' => [1]], 'hygiene.site_allow_dirs'];
        yield 'not a map' => [[1, 2], 'hygiene.0'];
    }

    /** @param array<array-key, mixed> $section */
    #[DataProvider('invalid')]
    public function testInvalidValuesFailAtStartNamingTheSetting(array $section, string $path): void
    {
        try {
            self::config($section);
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            self::assertStringContainsString($path, $e->getMessage());
        }
    }

    public function testSiteAllowDirsDefaultToNoneAndAreLowerCased(): void
    {
        self::assertSame([], self::config(null)->hygiene->siteAllowDirs);
        self::assertSame(['data', 'files'], self::config(['site_allow_dirs' => ['Data', 'files']])->hygiene->siteAllowDirs);
    }

    public function testASectionThatIsNotAMapIsRejected(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('hygiene must be a map');
        self::config(true);
    }

    public function testThePublishableConfigCarriesTheHygieneSwitch(): void
    {
        $env = [
            'WATCHER_SERVICE' => 'svc', 'WATCHER_RUN_AT' => '06:00', 'WATCHER_LOKI_URL' => 'https://loki.example', 'WATCHER_GEMINI_KEY' => 'k',
            'WATCHER_MODEL_TRIAGE' => 'm1', 'WATCHER_MODEL_ANALYZE' => 'm2', 'WATCHER_MODEL_ESCALATE' => 'm3', 'WATCHER_SLACK_WEBHOOK' => 'https://hooks.example/x',
            'WATCHER_STATE' => 'file', 'WATCHER_LOKI_TOKEN' => 't', 'WATCHER_REPO_URL' => 'https://git.example.com/g/p',
        ];
        $load = static function (): WatchConfig {
            $c = require __DIR__ . '/../../../config/watcher.php';
            self::assertArrayHasKey('hygiene', $c);

            return WatchConfig::fromArray($c);
        };
        foreach ([[null, false], ['1', true]] as [$value, $expected]) {
            $before = [];
            foreach ($env + ['WATCHER_HYGIENE_ENABLED' => (string) $value] as $k => $v) {
                $before[$k] = getenv($k);
                putenv($v === '' ? $k : "$k=$v");
            }
            try {
                self::assertSame($expected, $load()->hygiene->enabled);
            } finally {
                foreach ($before as $k => $v) {
                    putenv($v === false ? $k : "$k=$v");
                }
            }
        }
    }
}
