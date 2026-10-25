<?php

declare(strict_types=1);

namespace Watcher\Observe;

use Watcher\Analyze\Finding;
use Watcher\Analyze\RouterResult;
use Watcher\Notify\DeliveryFailed;
use Watcher\Privacy\RedactedGroup;

/**
 * What a run hands to the event factory, collected next to RunStats. Typed objects and numbers only: no raw log
 * event, no exception text.
 */
final class RunObservation
{
    /** DeliveryFailed codes for a failure without an HTTP status (a notifier sets them; any other non-HTTP code is "other"). */
    public const NETWORK = -1;

    public const TIMEOUT = -2;

    /** @var list<Finding> redacted findings, in digest order */
    public array $findings = [];

    /** @var array<string, RedactedGroup> every kept group of the run, by fingerprint */
    public array $groups = [];

    /** @var array<string, int>|null groups of the run by trend (all kept groups, not only those in $groups); null = count $groups */
    public ?array $trends = null;

    public ?RouterResult $router = null;

    public ?float $spentTodayUsd = null;

    public ?float $dailyBudgetUsd = null;

    public ?int $tokensToday = null;

    public ?int $dailyTokenLimit = null;

    /** @var int highest configured budget alert percent reached today, 0 if none */
    public int $thresholdPct = 0;

    public bool $triageOnly = false;

    public int $muted = 0;

    public bool $truncated = false;

    /** @var list<string> fingerprints of groups that are new after the deployed release */
    public array $newAfterRelease = [];

    /** @var list<string> sinks that failed in an earlier run and were not seen clean since (shown by the heartbeat event) */
    public array $priorSinkFailures = [];

    /** @var list<array{code: string, key: ?string, count: ?int}> */
    public array $warnings = [];

    /** @var list<array{channel: string, kind: string, status_class: string, http_status: ?int}> */
    public array $deliveryFailures = [];

    public function warn(string $code, ?string $key = null, ?int $count = null): void
    {
        $this->warnings[] = ['code' => $code, 'key' => $key, 'count' => $count];
    }

    /** Records a failed chat delivery. Only the exception's code is read, never its message (it can name a URL). */
    public function deliveryFailed(string $channel, string $kind, DeliveryFailed $e): void
    {
        $code = $e->getCode();
        $http = $code >= 100 && $code <= 599 ? $code : null;
        $this->deliveryFailures[] = [
            'channel' => $channel,
            'kind' => $kind,
            'status_class' => match (true) {
                $http === 429 => 'http_429',
                $http !== null && $http < 500 => 'http_4xx',
                $http !== null => 'http_5xx',
                $code === self::NETWORK => 'network',
                $code === self::TIMEOUT => 'timeout',
                default => 'other',
            },
            'http_status' => $http,
        ];
    }
}
