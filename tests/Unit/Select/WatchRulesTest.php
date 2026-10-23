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
}
