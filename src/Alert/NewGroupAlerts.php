<?php

declare(strict_types=1);

namespace Watcher\Alert;

use Closure;
use DateTimeImmutable;
use Watcher\Level;
use Watcher\Notify\DayGate;
use Watcher\Notify\DeliveryFailed;
use Watcher\Notify\NoticeRenderer;
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
        private readonly string $stateKey,
        private readonly Level $minLevel,
        private readonly int $retention,
        private readonly bool $enabled,
        private readonly ?DayGate $days,
        private readonly Closure $send,
        private readonly Closure $failed,
        private readonly NoticeRenderer $notices,
    ) {
    }

    /**
     * @param list<Candidate>                 $candidates
     * @param Closure(string): ?string        $linkFor log link of a group by fingerprint, or null
     */
    public function deliver(array $candidates, Closure $linkFor, string $service, string $environment, DateTimeImmutable $now): void
    {
        if (!$this->enabled) {
            return;
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
                ($this->send)($this->notices->newGroup($service, $environment, strtolower($group->level->name), $group->fingerprint, $group->count, $linkFor($group->fingerprint)));
                $this->state->put($key, ['at' => $now->format(DATE_ATOM)], $this->retention);
            } catch (DeliveryFailed $e) {
                ($this->failed)($e);
            }
        }
    }
}
