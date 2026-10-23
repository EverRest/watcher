<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Select;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Reduce\ProblemGroup;
use Watcher\Select\CandidateReason;
use Watcher\Select\Selector;
use Watcher\Select\WatchRules;
use Watcher\Source\LogEvent;

final class WatchRulesTest extends TestCase
{
    private function g(string $fp, ?string $tpl, ?string $type = null, Level $l = Level::Warning): ProblemGroup
    {
        $t = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return (new ProblemGroup($fp, $tpl, $l, $type, [], 1, $t, $t))->withPrevious(1, 3.0);
    }

    /** @param array<string, scalar|null> $fields */
    private function ev(array $fields): LogEvent
    {
        return new LogEvent(new DateTimeImmutable('2026-01-01'), Level::Info, 'm', null, [], $fields, []);
    }

    public function testTemplateRuleMatchesAndRecordsOnlyTheName(): void
    {
        $rules = new WatchRules(['payments' => ['template' => '/payment <\w+> failed/']]);
        $out = $rules->apply([$this->g('a', 'payment <ID> failed'), $this->g('b', 'other')], []);
        self::assertSame(['payments'], $out[0]->watchMatches);
        self::assertSame([], $out[1]->watchMatches);
        self::assertCount(2, $out);
        self::assertSame('a', $out[0]->fingerprint);
    }

    public function testWithheldTemplateNeverMatches(): void
    {
        $rules = new WatchRules(['any' => ['template' => '/.*/']]);
        self::assertSame([], $rules->namesMatchingGroup($this->g('a', null)));
    }

    public function testErrorTypeRule(): void
    {
        $rules = new WatchRules(['db' => ['error_type' => '/PDOException$/']]);
        self::assertSame(['db'], $rules->namesMatchingGroup($this->g('a', 't', 'PDOException')));
        self::assertSame([], $rules->namesMatchingGroup($this->g('a', 't', null)));
    }

    public function testFieldRulesThroughTheEventPath(): void
    {
        $rules = new WatchRules([
            'vip' => ['field' => 'tenant', 'equals' => 'acme'],
            'eu' => ['field' => 'region', 'matches' => '/^eu-/'],
            'tpl' => ['template' => '/x/'],
        ]);
        self::assertSame(['vip', 'eu'], $rules->namesMatchingEvent($this->ev(['tenant' => 'acme', 'region' => 'eu-1'])));
        self::assertSame([], $rules->namesMatchingEvent($this->ev(['tenant' => 'other'])));
        // field rules never match a group directly
        self::assertSame(['tpl'], $rules->namesMatchingGroup($this->g('a', 'x y')));

        $out = $rules->apply([$this->g('a', 'zzz'), $this->g('b', 'zzz')], ['a' => ['vip']]);
        self::assertSame(['vip'], $out[0]->watchMatches);
        self::assertSame([], $out[1]->watchMatches);
    }

    public function testInvalidRegexIsNoMatchNotException(): void
    {
        $rules = new WatchRules(['bad' => ['template' => '/(/']]);
        self::assertSame([], $rules->namesMatchingGroup($this->g('a', 'x')));
    }

    public function testSelectorMakesWatchedWarningACandidate(): void
    {
        $rules = new WatchRules(['w' => ['template' => '/boom/']]);
        $groups = $rules->apply([$this->g('a', 'boom', null, Level::Warning), $this->g('b', 'calm', null, Level::Info)], []);
        $res = (new Selector())->select([$groups[0], $groups[1]->withPrevious(1, 3.0)], Level::Error, 0);
        $reasons = [];
        foreach ($res->candidates as $c) {
            $reasons[$c->group->fingerprint] = $c->reasons;
        }
        self::assertContains(CandidateReason::WatchRule, $reasons['a']);
        self::assertArrayNotHasKey('b', $reasons);
        self::assertSame(CandidateReason::WatchRule->weight() * 10 + Level::Warning->value, $res->candidates[0]->priority);
    }

    /**
     * @param array<string, array<string, mixed>> $rules
     * @return list<string>
     */
    private static function patterns(array $rules): array
    {
        return (new WatchRules($rules))->backendPatterns();
    }

    public function testBackendPatternsPushesALiteralTemplateRule(): void
    {
        self::assertSame(['QuotaTracking'], self::patterns(['q' => ['template' => '/QuotaTracking/']]));
    }

    public function testBackendPatternsMakesACaseInsensitiveLiteralCarryTheInlineFlag(): void
    {
        self::assertSame(['(?i)QuotaTracking'], self::patterns(['q' => ['template' => '/QuotaTracking/i']]));
    }

    public function testBackendPatternsEscapesLiteralPunctuationAndHonoursAnyDelimiter(): void
    {
        self::assertSame(['payment failed\\-hard\\#x'], self::patterns(['p' => ['template' => '~payment failed-hard#x~']]));
    }

    public function testBackendPatternsSkipsATemplateRuleThatIsNotAPlainLiteral(): void
    {
        // the regex runs on the MASKED template; the backend sees raw lines, so only literals are safe
        self::assertSame([], self::patterns(['p' => ['template' => '/payment <\w+> failed/'], 'q' => ['template' => '/^Quota/'], 'r' => ['template' => '/a|b/']]));
    }

    public function testBackendPatternsSkipsTemplateLiteralsThatHoldPlaceholdersOrMaskedDigits(): void
    {
        // raw lines never contain `<N>`/`<ID>`, and digits are masked to `<N>` in the template: never pushed
        self::assertSame([], self::patterns([
            'a' => ['template' => '/retry <N> times/'],
            'b' => ['template' => '/user <ID> gone/'],
            'c' => ['template' => '/timeout 30s/'],
        ]));
    }

    public function testBackendPatternsSkipsATemplateLiteralWithOtherFlags(): void
    {
        self::assertSame([], self::patterns(['p' => ['template' => '/Quota/s']]));
    }

    public function testBackendPatternsPushesAnRe2SafeErrorTypeRule(): void
    {
        self::assertSame(['(?i)\\w+TimeoutException'], self::patterns(['e' => ['error_type' => '/\\w+TimeoutException/i']]));
        self::assertSame(['Db(Timeout|Refused)Exception'], self::patterns(['e' => ['error_type' => '/Db(Timeout|Refused)Exception/']]));
    }

    public function testBackendPatternsSkipsAnErrorTypeRuleWithLookahead(): void
    {
        self::assertSame([], self::patterns(['e' => ['error_type' => '/Timeout(?!Soft)Exception/']]));
    }

    public function testBackendPatternsNeverPushesFieldRules(): void
    {
        self::assertSame([], self::patterns(['a' => ['field' => 'tenant', 'equals' => 'x'], 'b' => ['field' => 'tenant', 'matches' => '/x/']]));
    }

    public function testBackendPatternsIsEmptyWithoutRules(): void
    {
        self::assertSame([], self::patterns([]));
    }

    public function testFailedRegexIsCountedAndDoesNotHideOtherRules(): void
    {
        $limit = ini_set('pcre.backtrack_limit', '100');
        $jit = ini_set('pcre.jit', '0');
        try {
            $rules = new WatchRules(['bad' => ['template' => '/(a+)+$/'], 'good' => ['template' => '/aaa/']]);
            $out = $rules->apply([$this->g('x', str_repeat('a', 60) . 'b')], []);
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
            ini_set('pcre.jit', (string) $jit);
        }

        self::assertSame(['good'], $out[0]->watchMatches);
        self::assertSame(1, $rules->failures());
    }

    public function testNoFailuresOnPlainNonMatch(): void
    {
        $rules = new WatchRules(['r' => ['template' => '/zzz/']]);
        $rules->apply([$this->g('x', 'nothing')], []);

        self::assertSame(0, $rules->failures());
    }
}
