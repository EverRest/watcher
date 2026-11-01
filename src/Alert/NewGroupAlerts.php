<?php

declare(strict_types=1);

namespace Watcher\Alert;

use Closure;
use DateTimeImmutable;
use Watcher\Config\WatchConfig;
use Watcher\Context\LogLinkBuilder;
use Watcher\Level;
use Watcher\Notify\DayGate;
use Watcher\Notify\DeliveryFailed;
use Watcher\Notify\NoticeRenderer;
use Watcher\Privacy\RedactedGroup;
use Watcher\Schedule\Window;
use Watcher\Select\Candidate;
use Watcher\Select\CandidateReason;
use Watcher\State\Keys;
use Watcher\State\StateStore;

/**
 * Spec 018 US2: a group that first appears at selection.min_level or higher is announced once, out of turn, in the run that
 * sees it, before the digest. The record is written only after a successful send. On a day outside notify.days nothing is sent
 * and nothing is stored.
 */
final class NewGroupAlerts
{
    public function __construct(
        private readonly StateStore $state,
        private readonly string $service,
        private readonly string $stateKey,
        private readonly Level $minLevel,
        private readonly int $retention,
        private readonly bool $enabled,
        private readonly ?DayGate $days,
        private readonly Closure $send,
        private readonly Closure $failed,
        private readonly NoticeRenderer $notices,
        private readonly ?LogLinkBuilder $links = null,
    ) {
    }

    /** The alerts of one run, built from its config. Disabled by notify switch, by dry run, or by the switch alerts.new_groups. */
    public static function fromRun(WatchConfig $c, StateStore $state, ?DayGate $days, Closure $send, Closure $failed, NoticeRenderer $notices, bool $dryRun): self
    {
        return new self(
            state: $state,
            service: $c->service,
            stateKey: $c->stateKey,
            minLevel: $c->selection['min_level'],
            retention: $c->state['retention'],
            enabled: $c->alerts['new_groups'] && !$dryRun,
            days: $days,
            send: $send,
            failed: $failed,
            notices: $notices,
            links: $c->on('links.logs') ? new LogLinkBuilder($c->links, $c->source['stream_tags']) : null,
        );
    }

    /**
     * @param list<Candidate>    $candidates
     * @param list<RedactedGroup> $redacted the redacted groups of this run, for their log links
     */
    public function deliver(array $candidates, array $redacted, ?Window $window, string $environment, DateTimeImmutable $now): void
    {
        if (!$this->enabled) {
            return;
        }
        $byFingerprint = [];
        foreach ($redacted as $g) {
            $byFingerprint[$g->fingerprint] = $g;
        }
        foreach ($candidates as $candidate) {
            $group = $candidate->group;
            if (!in_array(CandidateReason::New, $candidate->reasons, true) || !$group->level->atLeast($this->minLevel)) {
                continue;
            }
            if (!($this->days?->allowsNow() ?? true)) {
                return;
            }
            $key = Keys::newGroupAlert($this->stateKey, $group->fingerprint);
            try {
                if ($this->state->get($key) !== null) {
                    continue;
                }
                $link = $this->links !== null && $window !== null && isset($byFingerprint[$group->fingerprint])
                    ? $this->links->forGroup($byFingerprint[$group->fingerprint], $window)
                    : null;
                ($this->send)($this->notices->newGroup($this->service, $environment, strtolower($group->level->name), $group->fingerprint, $group->count, $link));
                $this->state->put($key, ['at' => $now->format(DATE_ATOM)], $this->retention);
            } catch (DeliveryFailed $e) {
                ($this->failed)($e);
            }
        }
    }
}
