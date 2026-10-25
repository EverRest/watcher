<?php

declare(strict_types=1);

namespace Watcher\Hygiene;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Throwable;
use Watcher\Analyze\CostCalculator;
use Watcher\Config\WatchConfig;
use Watcher\Hygiene\Cost\CostLedger;
use Watcher\Hygiene\Cost\CostReader;
use Watcher\Hygiene\Cost\CostReport;
use Watcher\Notify\DeliveryFailed;
use Watcher\Operate\DryRunStateStore;
use Watcher\Notify\Message;
use Watcher\Notify\SlackText;
use Watcher\Notify\Notifier;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;
use Watcher\Reduce\Reducer;
use Watcher\Schedule\RunGuard;
use Watcher\Schedule\Window;
use Watcher\Source\LogSource;
use Watcher\Source\ReadDiagnostics;
use Watcher\Source\ReadFilter;
use Watcher\Source\SourceUnavailable;
use Watcher\Source\StreamSelector;
use Watcher\State\Keys;
use Watcher\State\StateStore;

/**
 * `watcher hygiene` (spec 006): reads the window once, measures it in code, keeps aggregates, and builds the four reports.
 * No model is called and nothing but its own state and an optional notice is written (Principles II, IV). The run path
 * (`Watcher`) is not involved.
 */
final class Hygiene
{
    private const SITES_PER_DAY = 500;

    private const FAMILIES_PER_DAY = 300;

    /** Severe families are kept beyond FAMILIES_PER_DAY, up to this many. */
    private const SEVERE_PER_DAY = 1000;

    /** Days of day records read for proposals: the default state retention. */
    private const HISTORY_DAYS = 30;

    private readonly GuardedState $state;

    private readonly StateStore $realState;

    private ?string $lockKey = null;

    private ?string $lockOwner = null;

    private int $lockRenewedAt = 0;

    private bool $lockLost = false;

    private ?RunGuard $activeGuard = null;

    /** @param RunGuard|null $guard the pass's deadline (hygiene.max_seconds) and memory guard; default: built when the read starts */
    public function __construct(
        private readonly WatchConfig $config,
        private readonly LogSource $source,
        StateStore $state,
        private readonly Notifier $notifier,
        private readonly ClockInterface $clock,
        private readonly ?RunGuard $guard = null,
    ) {
        $this->realState = $state;
        $this->state = new GuardedState($state);
    }

    /** @param array<string, mixed> $config @throws \Watcher\Config\InvalidConfig */
    public static function fromConfig(array $config, ?ClockInterface $clock = null): self
    {
        $c = WatchConfig::fromArray($config);
        $clock ??= new \Watcher\Schedule\SystemClock();
        $http = \Watcher\Config\Factory::http();

        return new self($c, \Watcher\Config\Factory::source($c, $http, $clock), \Watcher\Config\Factory::state($c, $clock), \Watcher\Config\Factory::notifier($http, $c->schedule['lock_ttl']), $clock);
    }

    public function run(HygieneOptions $o): HygieneResult
    {
        $c = $this->config;
        if (!$c->enabled) {
            return new HygieneResult(disabled: true);
        }
        if (!$c->hygiene->enabled && ($o->notify || $o->decline !== null)) {
            return new HygieneResult(notes: ['hygiene is disabled (set hygiene.enabled=true)'], exit: 2);
        }
        $now = $o->now ?? $this->clock->now();
        if ($o->now !== null && !$o->dryRun && $o->now->getTimestamp() > $this->clock->now()->getTimestamp() + 86400) {
            // A far-future --now would stamp the registry with a date that never expires and prune everything older.
            return new HygieneResult(notes: ['--now is more than a day ahead of the clock; it would write state dated in the future (use --dry-run to look without writing)'], exit: 2);
        }
        if ($o->dryRun) {
            // Read-only: every write is shadowed in memory, the lock is a no-op (a real pass keeps its lock and is not blocked), nothing is sent.
            $dry = new self($c, $this->source, new DryRunStateStore($this->realState), $this->notifier, $this->clock, $this->guard);

            return $dry->runDry($o, $now);
        }
        try {
            return $this->execute($o, $now);
        } catch (SourceUnavailable) {
            return new HygieneResult(exit: 1, failure: 'source unavailable', envelope: $this->envelope($now, Window::endingAt($now, $c->schedule['window']), 0, false));
        } catch (StateFailure $e) {
            return new HygieneResult(notes: ['state error class: ' . $e->errorClass], exit: 1, failure: $e->getMessage(), envelope: $this->envelope($now, Window::endingAt($now, $c->schedule['window']), 0, false));
        } catch (DeliveryFailed) {
            return new HygieneResult(exit: 1, failure: 'notice delivery failed', envelope: $this->envelope($now, Window::endingAt($now, $c->schedule['window']), 0, false));
        } catch (Throwable $e) {
            // The class only: a driver message can carry a DSN, a URL or a password.
            return new HygieneResult(exit: 1, failure: 'internal error (' . ReportText::className($e::class) . ')', envelope: $this->envelope($now, Window::endingAt($now, $c->schedule['window']), 0, false));
        }
    }

    private function runDry(HygieneOptions $o, DateTimeImmutable $now): HygieneResult
    {
        $options = new HygieneOptions($o->reports, $o->days, false, $o->decline, $o->now, true);
        try {
            return $this->execute($options, $now)->withNote('dry run: nothing was written (day record, registry, counters, lock) and nothing was sent');
        } catch (SourceUnavailable) {
            return new HygieneResult(exit: 1, failure: 'source unavailable', envelope: $this->envelope($now, Window::endingAt($now, $this->config->schedule['window']), 0, false));
        } catch (StateFailure $e) {
            return new HygieneResult(notes: ['state error class: ' . $e->errorClass], exit: 1, failure: $e->getMessage(), envelope: $this->envelope($now, Window::endingAt($now, $this->config->schedule['window']), 0, false));
        } catch (Throwable $e) {
            return new HygieneResult(exit: 1, failure: 'internal error (' . ReportText::className($e::class) . ')', envelope: $this->envelope($now, Window::endingAt($now, $this->config->schedule['window']), 0, false));
        }
    }

    private function execute(HygieneOptions $o, DateTimeImmutable $now): HygieneResult
    {
        $c = $this->config;
        $writes = $c->hygiene->enabled && (array_intersect($o->reports, ['privacy', 'shape']) !== [] || $o->notify || $o->decline !== null);
        if (!$writes) {
            return $this->perform($o, $now);
        }
        // One pass at a time: the registry and the day record are read-modify-write. A store that can extend its lock gets lock_ttl and
        // is renewed while the pass is alive (the same RenewableLock the run path uses); one that cannot gets a lock that outlives
        // the whole pass (max_seconds, the notice phase and a margin).
        $owner = bin2hex(random_bytes(8));
        $lock = Keys::hygieneLock($c->stateKey);
        $ttl = $this->state->renewable() ? $c->schedule['lock_ttl'] : $c->hygiene->maxSeconds + $c->schedule['lock_ttl'] + 60;
        if (!$this->state->acquire($lock, $owner, $ttl)) {
            return new HygieneResult(exit: 1, failure: 'another hygiene pass is running', envelope: $this->envelope($now, Window::endingAt($now, $c->schedule['window']), 0, false));
        }
        $this->lockKey = $lock;
        $this->lockOwner = $owner;
        $this->lockRenewedAt = $this->clock->now()->getTimestamp();
        $this->lockLost = false;
        try {
            $result = $this->perform($o, $now);
        } catch (Throwable $e) {
            $this->release($lock, $owner);

            throw $e;
        }
        $failed = $this->release($lock, $owner);

        return $failed === null ? $result : $result->withNote('lock release failed (' . $failed . ')');
    }

    /** Extends the lock when less than half of its lifetime is left; a lost lock is noted in the result, never silently ignored. */
    private function renewLock(): void
    {
        if ($this->lockKey === null || $this->lockOwner === null || !$this->state->renewable()) {
            return;
        }
        $ttl = $this->config->schedule['lock_ttl'];
        $now = $this->clock->now()->getTimestamp();
        if ($now - $this->lockRenewedAt < intdiv($ttl, 2)) {
            return;
        }
        try {
            if ($this->state->renew($this->lockKey, $this->lockOwner, $ttl)) {
                $this->lockRenewedAt = $now;
            } else {
                $this->lockLost = true;
            }
        } catch (StateFailure) {
            $this->lockLost = true;
        }
    }

    /** A release failure never changes the outcome of the pass; returns the failing class, nothing else. */
    private function release(string $lock, string $owner): ?string
    {
        try {
            $this->state->release($lock, $owner);
        } catch (StateFailure $e) {
            return $e->errorClass;
        }

        return null;
    }

    private function perform(HygieneOptions $o, DateTimeImmutable $now): HygieneResult
    {
        $c = $this->config;
        $h = $c->hygiene;
        $window = Window::endingAt($now, $c->schedule['window']);
        $history = new HygieneHistory($this->state, $c->stateKey, $c->state['retention'], new DateTimeZone($c->schedule['timezone']));
        $proposals = new Proposals($h, $c->selection['min_level'], $c->mute, $c->watchRules, $history);

        if ($o->decline !== null) {
            return $this->decline($o->decline, $proposals, $history, $now, $window);
        }

        $wanted = array_values(array_intersect(HygieneOptions::REPORTS, $o->reports));
        $skipped = false;
        if (!$h->enabled) {
            $skipped = array_diff($wanted, ['cost']) !== [];
            $wanted = array_values(array_intersect($wanted, ['cost']));
        }

        $reports = [];
        $timedOut = false;
        $events = 0;
        $sampled = false;
        $privacy = null;
        $registry = null;
        if (array_intersect($wanted, ['privacy', 'shape']) !== []) {
            [$scan, $groups, $redactor, $variable, $sampled, $timedOut, $wide, $floor] = $this->scan($window);
            $events = $scan->events;
            $labels = [];
            $hashes = [];
            foreach ($groups as $g) {
                // The privacy floor of a shown template (review M1, M-2, M-3): it must generalise at least `variable_distinct` messages, or the
                // operator opted in to constant labels; and its literal words must be common log vocabulary or seen at several sites.
                // The hash of the full masked template is always kept, so the guard against muting a template that was severe under another
                // fingerprint still works for a message whose text is withheld.
                $full = $redactor->redact($g)->template;
                $hashes[$g->fingerprint] = $full === null ? null : substr(hash('sha256', $full), 0, 12);
                $shown = $full !== null && (isset($wide[(string) $g->template]) || $h->allowConstantLabels);
                $labels[$g->fingerprint] = $shown ? $floor->mask((string) $full) : null;
            }
            $this->storeDay($history, $now, $scan, $groups, $labels, $hashes, $variable, $sampled);
            if (in_array('privacy', $wanted, true)) {
                $registry = new SiteRegistry($history->registry(), $now, $h, $c->state['retention']);
                $privacy = PrivacyReport::build($scan, $labels, $variable, $registry, !$sampled && $events > 0);
                $history->putRegistry($registry->toArray());
                $reports['privacy'] = $privacy;
            }
            if (in_array('shape', $wanted, true)) {
                $reports['shape'] = ShapeReport::build($scan, $h);
            }
        }
        if (in_array('proposals', $wanted, true)) {
            $reports['proposals'] = new ProposalReport($proposals->find($history->days($now, self::HISTORY_DAYS), $now));
        }
        if (in_array('cost', $wanted, true)) {
            $ledger = new CostLedger($this->state, $c->stateKey, $c->state['retention'], new DateTimeZone($c->schedule['timezone']), $this->clock, new CostCalculator($c->analysis['prices']));
            $reports['cost'] = new CostReport((new CostReader($c, $this->state, $ledger))->read($now, $o->days));
        }

        $notes = $timedOut ? [$this->activeGuard?->reason() === 'memory' ? 'stopped at the memory guard; the read is sampled' : 'stopped at the time budget (hygiene.max_seconds); the read is sampled'] : [];
        $this->renewLock();
        if ($events === 0 && array_intersect($wanted, ['privacy', 'shape']) !== []) {
            // A source that answers with nothing (wrong stream_tags, a rotated file, an empty reply) cannot tell a quiet day from a broken read.
            $notes[] = 'the source returned no events: this is not counted as a complete day (no site is treated as gone); check source.stream_tags';
        }
        if ($this->source instanceof ReadDiagnostics && ($quality = self::readQuality($this->source->readDiagnostics())) !== null) {
            $notes[] = $quality;
        }
        $notes = [...$notes, ...($o->notify ? $this->notify($reports, $privacy, $registry, $history, $now) : [])];

        if ($this->lockLost) {
            $notes[] = 'the pass lock expired before the pass ended; another pass may have written at the same time';
        }

        return new HygieneResult($reports, $notes, skipped: $skipped, envelope: $this->envelope($now, $window, $events, $sampled));
    }

    /** Counts the source's parser guessed or dropped (develop's ReadDiagnostics), as one note; null when all are zero. */
    /** @param array<string, int> $d */
    private static function readQuality(array $d): ?string
    {
        $parts = [];
        foreach ([
            'unknown_levels' => '%s events with an unrecognised level',
            'orphan_trace_lines' => '%s stack-frame lines dropped',
            'message_tails_dropped' => '%s multi-line messages cut to their first line',
            'truncated_lines' => '%s over-long lines cut',
            'truncated_structured' => '%s oversized structured lines replaced',
            'oversized_lines' => '%s lines over 64 KB dropped',
            'pages_shrunk' => '%s pages re-read at a smaller size',
            'regex_failures' => '%s lines the parser could not read',
        ] as $key => $format) {
            if (($d[$key] ?? 0) > 0) {
                $parts[] = sprintf($format, number_format($d[$key]));
            }
        }

        return $parts === [] ? null : 'read quality: ' . implode(', ', $parts);
    }

    /** @return array{ScanResult, list<\Watcher\Reduce\ProblemGroup>, Redactor, list<array{tpl: string, positions: int, distinct_messages: int}>, bool, bool, array<string, int>, LiteralFloor} the last three are: stopped by the time budget, the templates that fold at least variable_distinct messages, and the word floor */
    private function scan(Window $window): array
    {
        $c = $this->config;
        $declared = $c->on('dependencies') ? array_map('strval', array_keys($c->dependencies)) : [];
        $normalizer = new Normalizer($declared, $c->dependencyDomains, extraPatterns: $c->redactionPatterns);
        $pathMap = is_array($c->repo['path_map'] ?? null) ? array_map('strval', $c->repo['path_map']) : [];
        $redactor = new Redactor($normalizer, $c->redactionPatterns, $pathMap);
        $scanner = new HygieneScanner($normalizer, $redactor, $c->hygiene, array_map('strval', array_keys($c->redactionPatterns)), pathRoots: array_map('strval', array_keys($pathMap)));
        $reducer = new Reducer(
            $normalizer,
            $c->minGroupEventsForText,
            $c->source['group_cap'],
            similarity: $c->grouping['similarity'],
            maxDistinctMessages: $c->grouping['message_cap'],
            groupBySimilarity: $c->on('grouping'),
            memoryBudgetBytes: $c->grouping['memory_budget_mb'] * 1048576,
        );

        $filter = new ReadFilter($c->hygiene->readMinLevel, [], $c->hygiene->eventCap);
        $timedOut = false;
        $guard = $this->activeGuard = $this->guard ?? new RunGuard($this->clock, $c->hygiene->maxSeconds);
        // The guard and the lock renewal run from INSIDE the source's paging (the run path does the same), not only every 500 events:
        // a slow source that yields few events per page still stops at the budget and keeps its lock.
        $filter->stopWhen(function () use ($guard): ?string {
            $this->renewLock();

            return $guard->reason();
        });
        $events = (function () use ($window, $filter, $scanner, &$timedOut, $guard): iterable {
            $n = 0;
            foreach ($this->source->lines($window, new StreamSelector($this->config->source['stream_tags']), $filter) as $e) {
                if (++$n % 500 === 0) {
                    $this->renewLock();
                    if ($guard->reason() !== null) {
                        $timedOut = true;

                        return;
                    }
                }
                $e = $scanner->observe($e); // decoded and cleaned before any mask runs: an invisible character inside an address must not defeat it

                yield $e;
            }
        })();
        $groups = $reducer->reduce($events);
        $timedOut = $timedOut || $filter->truncationReason !== null; // the guard stopped the source inside its paging
        $floor = new LiteralFloor($scanner->commonWords());
        $variable = array_map(
            static fn (array $row): array => ['tpl' => $floor->mask($row['tpl']), 'th' => substr(hash('sha256', 'v|' . $row['tpl']), 0, 15)] + $row,
            (new VariablePositions($redactor))->find($reducer->variability(), $c->hygiene->variableDistinct),
        );
        /** @var array<string, int> $folded */
        $folded = [];
        foreach ($reducer->variability() as $v) {
            $folded[$v['template']] = ($folded[$v['template']] ?? 0) + $v['distinct'];
        }
        $wide = array_filter($folded, static fn (int $n): bool => $n >= $c->hygiene->variableDistinct);

        return [$scanner->result(), $groups, $redactor, $variable, $timedOut || $this->source->lastReadTruncated(), $timedOut, $wide, $floor];
    }

    /**
     * @param list<\Watcher\Reduce\ProblemGroup> $groups
     * @param array<string, ?string> $labels
     * @param array<string, ?string> $hashes
     * @param list<array{tpl: string, positions: int, distinct_messages: int}> $variable
     */
    private function storeDay(HygieneHistory $history, DateTimeImmutable $now, ScanResult $scan, array $groups, array $labels, array $hashes, array $variable, bool $sampled): void
    {
        $c = $this->config;
        $date = $history->dateOf($now);
        $ledger = (new CostLedger($this->state, $c->stateKey, $c->state['retention'], new DateTimeZone($c->schedule['timezone']), $this->clock, new CostCalculator($c->analysis['prices'])))->day($date);

        // Every family at or above the candidate level is kept, however quiet: proposals must never miss a severe family hidden by the cut (review M2).
        $severe = array_values(array_filter($groups, fn (\Watcher\Reduce\ProblemGroup $g): bool => $g->level->atLeast($c->selection['min_level'])));
        $rest = array_values(array_filter($groups, fn (\Watcher\Reduce\ProblemGroup $g): bool => !$g->level->atLeast($c->selection['min_level'])));
        $families = [];
        foreach ([...array_slice($severe, 0, self::SEVERE_PER_DAY), ...array_slice($rest, 0, max(0, self::FAMILIES_PER_DAY - min(count($severe), self::SEVERE_PER_DAY)))] as $g) {
            $row = [
                'fp' => $g->fingerprint, 'tpl' => $labels[$g->fingerprint] ?? null, 'level' => strtolower($g->level->name), 'n' => $g->count,
                'first' => $g->firstSeen->format(DATE_ATOM), 'last' => $g->lastSeen->format(DATE_ATOM), 'tokens' => $ledger['families'][$g->fingerprint]['tokens'] ?? 0,
            ];
            if (($hashes[$g->fingerprint] ?? null) !== null) {
                $row['th'] = $hashes[$g->fingerprint];
            }
            $families[] = $row;
        }
        $sites = [];
        foreach ($scan->sites as $s) {
            if ($s->anyHit > 0 || $s->long > 0 || $s->trace > 0 || $s->mismatch > 0) {
                $sites[] = [...$s->toArray(), 'label' => $labels[$s->fingerprint] ?? null];
            }
        }

        // An empty read (a re-run with a broken stream_tags, a --now older than the source keeps) never replaces the good record of its
        // date: it would count as a complete day, and erase the severity memory the FR-011 guard relies on.
        $existing = $history->day($date);
        if ($scan->events === 0 && $existing !== null && $existing->events > 0) {
            return;
        }
        // The Redis store refuses a value past its codec budgets, which count the punctuation inside strings too: a day full of bracketed
        // templates must be cut down (and marked sampled) here, not fail the pass every day.
        $history->putDay((new DayRecord($date, $scan->events, $sampled, $scan->otherEvents, array_slice($sites, 0, self::SITES_PER_DAY), $families, $scan->formats, $variable))
            ->withinBudget(intdiv(\Watcher\State\StateCodec::MAX_BYTES, 2), intdiv(\Watcher\State\StateCodec::MAX_CONTAINERS, 2), intdiv(\Watcher\State\StateCodec::MAX_ELEMENTS, 2)));
    }

    /**
     * @param array<string, Report> $reports
     * @return list<string> notes
     */
    private function notify(array $reports, ?PrivacyReport $privacy, ?SiteRegistry $registry, HygieneHistory $history, DateTimeImmutable $now): array
    {
        if (!$this->config->on('notice')) {
            return ['notices are disabled (notify.notice.enabled=false); nothing was sent'];
        }
        $to = $this->config->destination('notice');
        $due = $this->config->hygiene->repeatDays * 86400;
        foreach ($reports as $name => $report) {
            $send = $name === 'privacy' && $privacy !== null ? $privacy->notice() : $report;
            if ($send->isEmpty()) {
                continue;
            }
            // Shape, proposals and cost have no per-site trend: at most once per repeat period, not on every --notify.
            $last = $name === 'privacy' ? null : $history->sentAt($name);
            if ($last !== null && $now->getTimestamp() - $last->getTimestamp() < $due) {
                continue;
            }
            $this->notifier->send($this->message($send->text(true)), $to);
            if ($send instanceof PrivacyReport && $registry !== null) {
                $registry->markReported($send->sentKeys());
                $registry->markGoneReported($send->goneKeys());
                $history->putRegistry($registry->toArray());
            } else {
                $history->markSent($name, $now);
            }
        }

        return [];
    }

    /** Sections as the digest's notices use them (a line each); the fallback is SlackText::plain(). */
    private function message(string $escaped): Message
    {
        $text = 'watcher hygiene [' . SlackText::escape($this->config->service) . "]\n" . $escaped;

        // One section per line, like the digest's notices: each channel splits and limits sections itself, so no cap or chunking here.
        return new Message(SlackText::plain($text), array_map(static fn (string $line): array => ['kind' => 'notice', 'text' => $line], explode("\n", $text)));
    }

    private function decline(string $id, Proposals $proposals, HygieneHistory $history, DateTimeImmutable $now, Window $window): HygieneResult
    {
        $open = array_column($proposals->find($history->days($now, self::HISTORY_DAYS), $now)['proposals'], 'id');
        if (!in_array($id, $open, true)) {
            return new HygieneResult(notes: ['unknown proposal id (list them with: watcher hygiene --report=proposals)'], exit: 2, envelope: $this->envelope($now, $window, 0, false));
        }
        $history->decline($id, $now, $this->config->hygiene->declineDays);

        return new HygieneResult(notes: ["declined $id for {$this->config->hygiene->declineDays} days"], envelope: $this->envelope($now, $window, 0, false));
    }

    /** @return array<string, mixed> */
    private function envelope(DateTimeImmutable $now, Window $window, int $events, bool $sampled): array
    {
        return [
            'service' => $this->config->service,
            'environment' => $this->config->environment,
            'generated_at' => $now->format(DATE_ATOM),
            'window' => ['start' => $window->start->format(DATE_ATOM), 'end' => $window->end->format(DATE_ATOM)],
            'events_read' => $events,
            'sampled' => $sampled,
            'header' => sprintf(
                'hygiene: %s  %s .. %s  %d events read%s',
                $this->config->service,
                $window->start->format(DATE_ATOM),
                $window->end->format(DATE_ATOM),
                $events,
                $sampled ? ' (sampled: the source stopped at hygiene.event_cap)' : '',
            ),
        ];
    }
}
