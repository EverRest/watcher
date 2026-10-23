<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Notify;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\Finding;
use Watcher\Analyze\FindingKind;
use Watcher\Analyze\Severity;
use Watcher\Analyze\Usage;
use Watcher\Level;
use Watcher\Notify\DigestData;
use Watcher\Notify\DigestRenderer;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;

final class DigestRendererTest extends TestCase
{
    private function fp(int $i): string
    {
        return substr(hash('sha256', (string) $i), 0, 16);
    }

    private function group(string $fp, int $count, GroupStatusLabel $st = GroupStatusLabel::Unchanged, ?int $prev = null): RedactedGroup
    {
        $t = new DateTimeImmutable('2026-10-23T01:00:00Z');

        return new RedactedGroup($fp, 'tpl', Level::Error, null, [], $count, $prev, $st, [], $t, $t);
    }

    private function finding(int $i, Severity $s = Severity::Error, float $conf = 0.9, FindingKind $k = FindingKind::Bug, string $title = ''): Finding
    {
        return new Finding([$this->fp($i)], $k, $s, $title !== '' ? $title : "title $i", "evidence $i", "cause $i", "action $i", $conf);
    }

    /**
     * @param list<Finding>          $findings
     * @param array<int, int>        $counts   finding index => count
     * @param array<string, mixed>   $extra
     */
    private function data(array $findings, array $counts = [], array $extra = []): DigestData
    {
        $groups = [];
        foreach ($findings as $f) {
            $groups[$f->fingerprints[0]] = $this->group($f->fingerprints[0], 5);
        }
        foreach ($counts as $i => $n) {
            $groups[$this->fp($i)] = $this->group($this->fp($i), $n);
        }

        return new DigestData(...array_merge([
            'service' => 'svc',
            'environment' => 'stg',
            'windowStart' => new DateTimeImmutable('2026-10-22T03:00:00Z'),
            'windowEnd' => new DateTimeImmutable('2026-10-23T03:00:00Z'),
            'timezone' => 'Europe/Kyiv',
            'findings' => $findings,
            'groups' => $groups,
        ], $extra));
    }

    private function text(\Watcher\Notify\Message $m, ?string $kind = null): string
    {
        return implode("\n", array_map(
            static fn (array $s): string => $s['text'],
            array_filter($m->sections, static fn (array $s): bool => $kind === null || $s['kind'] === $kind)
        ));
    }

    public function testHeaderLineHasServiceEnvWindowInDisplayTimezoneAndRelease(): void
    {
        $m = (new DigestRenderer())->render($this->data([], [], ['release' => 'v1.2']), 0.5);

        self::assertSame('🔎 svc · stg · 2026-10-22 06:00 → 2026-10-23 06:00 (Europe/Kyiv) · v1.2', $m->sections[0]['text']);
        self::assertSame('header', $m->sections[0]['kind']);
    }

    public function testOrdersBySeverityThenCountDescending(): void
    {
        $f = [
            $this->finding(1, Severity::Info, 0.9, FindingKind::Anomaly),
            $this->finding(2, Severity::Error),
            $this->finding(3, Severity::Critical),
            $this->finding(4, Severity::Error),
        ];
        $m = (new DigestRenderer())->render($this->data($f, [1 => 9, 2 => 10, 3 => 1, 4 => 50]), 0.5);

        $titles = array_values(array_map(
            static fn (array $s): string => explode(' — ', mb_substr($s['text'], 2))[0],
            array_filter($m->sections, static fn (array $s): bool => $s['kind'] === 'finding')
        ));
        self::assertSame(['title 3', 'title 4', 'title 2', 'title 1'], $titles);
        self::assertStringContainsString('🔴 *critical* (1)', $this->text($m, 'severity'));
        self::assertStringContainsString('🟠 *error* (2)', $this->text($m, 'severity'));
    }

    public function testFindingShowsCountTrendFingerprintAndPrefixes(): void
    {
        $new = $this->finding(1);
        $grown = $this->finding(2);
        $d = $this->data([$new, $grown]);
        $groups = $d->groups;
        $groups[$this->fp(1)] = $this->group($this->fp(1), 7, GroupStatusLabel::New);
        $groups[$this->fp(2)] = $this->group($this->fp(2), 1412, GroupStatusLabel::Grown, 3);
        $d = $this->data([$new, $grown], [], ['groups' => $groups]);

        $t = $this->text((new DigestRenderer())->render($d, 0.5), 'finding');

        self::assertStringContainsString('• title 1 — 7× (new)  `' . substr($this->fp(1), 0, 12) . '`', $t);
        self::assertStringContainsString('• title 2 — 1,412× (↑ from 3)', $t);
        self::assertStringContainsString("  Evidence: evidence 1\n  Hypothesis: cause 1  Next: action 1", $t);
    }

    public function testMinorRule(): void
    {
        $f = [
            $this->finding(1, Severity::Error, 0.2),
            $this->finding(2, Severity::Critical, 0.0),
            $this->finding(3, Severity::Warn, 0.3),
            $this->finding(4, Severity::Info, 0.9, FindingKind::Noise),
            $this->finding(5, Severity::Warn, 0.8),
            $this->finding(6, Severity::Error, 0.9, FindingKind::Noise),
        ];
        $m = (new DigestRenderer())->render($this->data($f), 0.5);
        $findings = $this->text($m, 'finding');

        self::assertStringContainsString('title 1', $findings);   // error 0.2 stays
        self::assertStringContainsString('title 2', $findings);   // critical stays
        self::assertStringNotContainsString('title 3', $findings); // warn 0.3 collapses
        self::assertStringNotContainsString('title 4', $findings); // noise collapses
        self::assertStringContainsString('title 5', $findings);
        self::assertStringContainsString('title 6', $findings);   // error noise stays
        self::assertStringContainsString('➕ 2 minor (low confidence / noise)', $this->text($m, 'minor'));
    }

    public function testMinorLineCarriesOtherGroupsAndMuted(): void
    {
        $m = (new DigestRenderer())->render($this->data([], [], ['otherGroups' => 23, 'otherEvents' => 1840, 'muted' => 2]), 0.5);

        self::assertSame('23 more groups, 1,840 events · 2 muted', $this->text($m, 'minor'));
    }

    public function testNoticesAndFooter(): void
    {
        $d = $this->data([], [], [
            'levelAnomalyLine' => 'info ×6 vs previous window',
            'eventsReadTruncated' => 5000,
            'droppedGroups' => 5,
            'usage' => new Usage('mixed', 18200, 1900, 6100, 50, 0.04),
            'models' => ['triage' => 'haiku-x', 'analyze' => 'sonnet-x'],
            'costUsd' => 0.04,
        ]);
        $m = (new DigestRenderer())->render($d, 0.5);

        $n = $this->text($m, 'notice');
        self::assertStringContainsString('info ×6 vs previous window', $n);
        self::assertStringContainsString('truncated: 5,000 events read', $n);
        self::assertStringContainsString('truncated: 5 groups dropped (budget)', $n);
        $last = $m->sections[array_key_last($m->sections)];
        self::assertSame('footer', $last['kind']);
        self::assertSame('— tokens in 18.2k / out 1.9k / cached 6.1k · triage haiku-x · analyze sonnet-x · ≈ $0.04', $last['text']);
    }

    public function testClusterCapNoticeNamesCountsOnly(): void
    {
        $m = (new DigestRenderer())->render($this->data([], [], ['overflowedEvents' => 1234, 'groupCap' => 5000]), 0.5);

        self::assertStringContainsString('cluster cap hit: 1,234 events from distinct messages beyond the first 5,000 were grouped as other', $this->text($m, 'notice'));
        self::assertStringNotContainsString('cluster cap', $this->text((new DigestRenderer())->render($this->data([]), 0.5)));
    }

    public function testModelTextCannotPingOrInjectLinks(): void
    {
        $f = new Finding([$this->fp(1)], FindingKind::Bug, Severity::Error, 'a <!channel> & @here', 'see <https://evil.example|click> <@U123>', 'x `y`', '@channel now', 0.9);
        $m = (new DigestRenderer())->render($this->data([$f]), 0.5);
        $t = $this->text($m, 'finding');

        foreach (['<!channel>', '<https', '<@U123>', '@here', '@channel', '<!'] as $bad) {
            self::assertStringNotContainsString($bad, $t);
        }
        self::assertStringContainsString('&lt;!channel&gt; &amp;', $t);
    }

    public function testModelTextCannotProduceAutoLinkedUrlsOrDomains(): void
    {
        // Log content can steer the model (prompt injection): whatever it writes must not become a clickable link.
        $f = new Finding(
            [$this->fp(1)],
            FindingKind::Bug,
            Severity::Error,
            'Login at https://evil.example/login',
            'visit evil.example/reset or www.evil.com or HTTP://EVIL.ORG now; mail x@evil.org',
            'cause: see sub.domain.co.uk/path',
            'open ftp://files.evil.net/a and QuotaRepository.php:88 in app/Services/X.php',
            0.9,
        );
        $t = $this->text((new DigestRenderer())->render($this->data([$f]), 0.5), 'finding');

        self::assertStringNotContainsString('://', $t);
        self::assertStringNotContainsString('www.', $t);
        self::assertSame(0, preg_match('/[a-z0-9-]+\.(?!php\b|json\b|log\b)[a-z]{2,24}/i', $t), 'a domain-like token survived: ' . $t);
        // File paths stay readable and copyable.
        self::assertStringContainsString('QuotaRepository.php:88', $t);
        self::assertStringContainsString('app/Services/X.php', $t);
    }

    public function testRealTldsThatLookLikeFileExtensionsAreDefanged(): void
    {
        $f = new Finding([$this->fp(1)], FindingKind::Bug, Severity::Error, 'get evil.zip', 'run evil.sh then evil.py and evil.md', 'x', 'y', 0.9);
        $t = $this->text((new DigestRenderer())->render($this->data([$f]), 0.5), 'finding');

        foreach (['evil.zip', 'evil.sh', 'evil.py', 'evil.md'] as $d) {
            self::assertStringNotContainsString($d, $t);
        }
    }

    public function testOverflowKeepsOneMessageWithinBlockLimitAndMovesLowestSeverityToMinor(): void
    {
        $f = [];
        for ($i = 1; $i <= 60; ++$i) {
            $f[] = $this->finding($i, $i <= 5 ? Severity::Critical : ($i <= 30 ? Severity::Error : Severity::Warn));
        }
        $m = (new DigestRenderer())->render($this->data($f), 0.5);

        self::assertLessThanOrEqual(40, count($m->sections));
        $findings = $this->text($m, 'finding');
        self::assertStringContainsString('title 1 ', $findings);
        self::assertStringNotContainsString('title 60 ', $findings);
        self::assertMatchesRegularExpression('/➕ \d+ minor/', $this->text($m, 'minor'));
        $shown = substr_count($findings, '• ');
        self::assertSame(60, $shown + (int) preg_replace('/\D.*/s', '', substr($this->text($m, 'minor'), strlen('➕ '))));
        self::assertSame(5, substr_count($this->text($m, 'finding'), 'Hypothesis: cause') >= 5 ? 5 : 0);
        self::assertLessThanOrEqual(4000, mb_strlen($m->text));
    }

    public function testSectionTextIsCapped(): void
    {
        $f = new Finding([$this->fp(1)], FindingKind::Bug, Severity::Error, 't', str_repeat('e', 5000), 'c', 'a', 0.9);
        $m = (new DigestRenderer())->render($this->data([$f]), 0.5);

        foreach ($m->sections as $s) {
            self::assertLessThanOrEqual(2800, mb_strlen($s['text']));
        }
    }

    public function testFallbackTextIsTitleLinePlusCounts(): void
    {
        $m = (new DigestRenderer())->render($this->data([$this->finding(1), $this->finding(2, Severity::Warn, 0.1)]), 0.5);

        self::assertSame("🔎 svc · stg · 2026-10-22 06:00 → 2026-10-23 06:00 (Europe/Kyiv)\n1 findings, 1 minor", $m->text);
    }

    public function testFooterShowsTodaysSpendAgainstTheCostLimit(): void
    {
        $m = (new DigestRenderer())->render($this->data([], [], ['costUsd' => 0.04, 'todayUsd' => 0.21, 'dailyBudgetUsd' => 0.5]), 0.5);

        self::assertStringContainsString('≈ $0.04 · today $0.21 of $0.50 (42%)', $this->text($m, 'footer'));
        self::assertStringNotContainsString('tokens today', $this->text($m, 'footer'));
    }

    public function testFooterAddsTheTokenCounterOnlyWhenALimitIsConfigured(): void
    {
        $m = (new DigestRenderer())->render($this->data([], [], ['todayUsd' => 0.1, 'dailyBudgetUsd' => 0.5, 'todayTokens' => 12500, 'dailyTokenLimit' => 50000]), 0.5);

        self::assertStringContainsString('today $0.10 of $0.50 (20%) · tokens today 12.5k of 50k (25%)', $this->text($m, 'footer'));
    }

    public function testFooterWithoutTodaysSpendIsUnchanged(): void
    {
        $m = (new DigestRenderer())->render($this->data([]), 0.5);

        self::assertStringNotContainsString('today', $this->text($m, 'footer'));
    }

    public function testBaselineNotice(): void
    {
        $m = (new DigestRenderer())->renderBaseline('svc', 'stg', 214, 96310);

        self::assertSame('✅ watcher · svc · stg — baseline recorded: 214 groups, 96,310 events. Analysis starts next run.', $m->sections[0]['text']);
        self::assertSame($m->sections[0]['text'], $m->text);
    }

    /**
     * @param list<string> $code
     *
     * @return array<string, array{log: ?string, code: list<string>}>
     */
    private function links(string $fp, ?string $log, array $code = []): array
    {
        return [$fp => ['log' => $log, 'code' => $code]];
    }

    public function testFirstSeenAfterReleaseMarkLogLinkAndUpToThreeCodeLinks(): void
    {
        $f = $this->finding(1);
        $fp = $f->fingerprints[0];
        $m = (new DigestRenderer())->render($this->data([$f], [], [
            'release' => 'v2.0',
            'afterReleaseFingerprints' => [$fp => 'v2.0'],
            'links' => $this->links($fp, 'https://logs.test/q?x=1', ['https://git.test/a#L1', 'https://git.test/b#L2', 'https://git.test/c#L3', 'https://git.test/d#L4']),
        ]), 0.5);
        $text = $this->text($m, 'finding');

        self::assertStringContainsString('first seen after v2.0', $text);
        self::assertStringContainsString('<https://logs.test/q?x=1|log>', $text);
        self::assertStringContainsString('<https://git.test/c#L3|code 3>', $text);
        self::assertStringNotContainsString('git.test/d', $text);
    }

    public function testNoMarkWithoutTheMapAndNoLinksWithoutLinks(): void
    {
        $text = $this->text((new DigestRenderer())->render($this->data([$this->finding(1)], [], ['release' => 'v2.0']), 0.5));

        self::assertStringNotContainsString('first seen after', $text);
        self::assertStringNotContainsString('<http', $text);
    }

    public function testUndeclaredHostsAreACountAndUnavailableDependenciesAreEscaped(): void
    {
        $m = (new DigestRenderer())->render($this->data([], [], [
            'undeclaredHostCount' => 3,
            'failedDependencies' => ['core-api', 'a<!channel>'],
        ]), 0.5);
        $text = $this->text($m, 'notice');

        self::assertStringContainsString('🔗 3 call target(s) not declared as dependencies', $text);
        self::assertStringContainsString('dependency unavailable: core-api', $text);
        self::assertStringNotContainsString('<!channel>', $text);
    }

    public function testALinkCannotInjectMarkupOrAScheme(): void
    {
        $f = $this->finding(1);
        $fp = $f->fingerprints[0];
        $m = (new DigestRenderer())->render($this->data([$f], [], [
            'links' => $this->links($fp, 'https://logs.test/a|<!here>', ['javascript:alert(1)', 'https://git.test/ok#L1']),
        ]), 0.5);
        $text = $this->text($m, 'finding');

        self::assertStringNotContainsString('<!here>', $text);
        self::assertStringNotContainsString('javascript:', $text);
        self::assertStringNotContainsString('|log>', $text);
        self::assertStringContainsString('<https://git.test/ok#L1|code 1>', $text);
    }
}
