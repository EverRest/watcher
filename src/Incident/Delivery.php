<?php

declare(strict_types=1);

namespace Watcher\Incident;

use Throwable;
use Watcher\Analyze\Finding;
use Watcher\Config\WatchConfig;
use Watcher\Context\CodeLinkBuilder;
use Watcher\Context\LogLinkBuilder;
use Watcher\Notify\DeliveryFailed;
use Watcher\Notify\Message;
use Watcher\Notify\Notifier;
use Watcher\Operate\RunOptions;
use Watcher\WindowKeys;
use Watcher\WindowRead;
use Watcher\State\StateStore;

/** Routes, digest delivery and ticket drafts (spec 005). */
final class Delivery
{
    /**
     * @param callable(Message): Message $buildDigest
     * @param callable(string): void     $out
     */
    /**
     * @param list<Finding>                              $findings
     * @param array<string, \Watcher\Privacy\RedactedGroup> $groups
     * @param callable(list<Finding>, ?string, int): Message $buildDigest
     * @param callable(string): void                     $out
     * @param callable(DeliveryFailed): void             $onDigestFailure
     * @param callable(string): void|null              $observerKind
     */
    public static function run(
        WatchConfig $c,
        WindowRead $read,
        array $findings,
        WindowKeys $keys,
        StateStore $state,
        Notifier $notifier,
        RunOptions $options,
        callable $buildDigest,
        callable $out,
        callable $onDigestFailure,
        ?callable $observerKind,
        array $groups = [],
        int $deliveredTtl = 21600,
        ?\DateTimeImmutable $now = null,
    ): int {
        $now = $now ?? new \DateTimeImmutable();
        $at = $now->format(DATE_ATOM);
        $defaultFindings = $findings;
        $routedNotice = null;
        $digestDest = $c->destination('digest');

        if ($c->on('routes') && $c->on('digest') && $c->incident->routes !== []) {
            $assigned = (new RouteTable($c->incident->routes))->assign($findings);
            $defaultFindings = $assigned['default'] ?? [];
            $routedAway = 0;
            $routeNames = [];
            foreach ($assigned as $idx => $batch) {
                if ($idx === 'default' || $batch === [] || !is_int($idx)) {
                    continue;
                }
                $route = $c->incident->routes[$idx];
                if ($route->severities === null && $route->destination === null) {
                    $defaultFindings = [...$defaultFindings, ...$batch];
                    continue;
                }
                $dest = $route->destination ?? $digestDest;
                if ($dest->sameAs($digestDest)) {
                    $defaultFindings = [...$defaultFindings, ...$batch];
                    continue;
                }
                if (!$options->dryRun && $state->get($keys->routeDelivered($idx)) !== null) {
                    continue;
                }
                try {
                    if ($observerKind !== null) {
                        $observerKind('digest');
                    }
                    $notifier->send($buildDigest($batch, null, 0), $dest);
                    if (!$options->dryRun) {
                        $state->put($keys->routeDelivered($idx), ['at' => $at], $deliveredTtl);
                    }
                    if ($observerKind !== null) {
                        $observerKind('other');
                    }
                } catch (DeliveryFailed) {
                    if ($observerKind !== null) {
                        $observerKind('other');
                    }
                }
                $routedAway += count($batch);
                $routeNames[$route->name] = true;
            }
            if ($routedAway > 0) {
                $routedNotice = $routedAway . ' finding(s) routed to ' . implode(', ', array_keys($routeNames));
            }
        }

        $draftsSkipped = 0;
        if ($c->on('drafts') && $c->incident->draftsEnabled) {
            $draftsSkipped = self::draftsSkipped($c, $defaultFindings, $keys, $state, $groups);
        }
        $message = $buildDigest($defaultFindings, $routedNotice, $draftsSkipped);
        try {
            if ($observerKind !== null) {
                $observerKind('digest');
            }
            $notifier->send($message, $digestDest);
            if ($options->dryRun && $c->on('drafts') && $c->incident->draftsEnabled) {
                $out('== DRY RUN drafts ==');
            }
            if ($observerKind !== null) {
                $observerKind('other');
            }
        } catch (DeliveryFailed $e) {
            if ($observerKind !== null) {
                $observerKind('other');
            }
            $onDigestFailure($e);

            throw $e;
        }

        if (!$options->dryRun) {
            try {
                $state->put($keys->delivered(), ['at' => $at], $deliveredTtl);
            } catch (Throwable) {
            }
        }

        if ($c->on('drafts') && $c->on('digest') && $c->incident->draftsEnabled) {
            self::sendDrafts($c, $read, $defaultFindings, $keys, $state, $notifier, $options, $out, $groups, $deliveredTtl, $now);
        }

        return $draftsSkipped;
    }

    /**
     * @param list<Finding>                              $findingsForDrafts
     * @param array<string, \Watcher\Privacy\RedactedGroup> $groups
     */
    private static function draftsSkipped(WatchConfig $c, array $findingsForDrafts, WindowKeys $keys, StateStore $state, array $groups): int
    {
        $sent = [];
        foreach ($findingsForDrafts as $f) {
            if ($state->get($keys->draft($f->fingerprints[0])) !== null) {
                $sent[$f->fingerprints[0]] = true;
            }
        }
        $counts = [];
        foreach ($groups as $fp => $g) {
            $counts[$fp] = $g->count;
        }

        return DraftPlan::select($findingsForDrafts, $c->incident->draftsMinSeverity, $c->incident->draftsMaxPerRun, $counts, $sent)['skipped'];
    }

    /**
     * @param list<Finding>                              $findingsForDrafts
     * @param array<string, \Watcher\Privacy\RedactedGroup> $groups
     */
    public static function sendDrafts(
        WatchConfig $c,
        WindowRead $read,
        array $findingsForDrafts,
        WindowKeys $keys,
        StateStore $state,
        Notifier $notifier,
        RunOptions $options,
        callable $out,
        array $groups = [],
        int $deliveredTtl = 21600,
        ?\DateTimeImmutable $now = null,
    ): void {
        $now = $now ?? new \DateTimeImmutable();
        $at = $now->format(DATE_ATOM);
        $sent = [];
        foreach ($findingsForDrafts as $f) {
            if ($state->get($keys->draft($f->fingerprints[0])) !== null) {
                $sent[$f->fingerprints[0]] = true;
            }
        }
        $counts = [];
        foreach ($groups as $fp => $g) {
            $counts[$fp] = $g->count;
        }
        $plan = DraftPlan::select($findingsForDrafts, $c->incident->draftsMinSeverity, $c->incident->draftsMaxPerRun, $counts, $sent);
        $labels = $read->knownResult !== null ? $read->knownResult->labels : [];
        $dest = $c->incident->draftsDestination($c);
        $window = $read->window;
        $logLinks = $c->on('links.logs') ? new LogLinkBuilder($c->links, $c->source['stream_tags']) : null;
        $codeLinks = $c->on('links.code') ? new CodeLinkBuilder($c->repo ?? [], $read->deploy->commit) : null;
        foreach ($plan['selected'] as $finding) {
            $fp = $finding->fingerprints[0];
            $g = $groups[$fp] ?? null;
            $links = ['log' => $g !== null ? $logLinks?->forGroup($g, $window) : null, 'code' => $g !== null ? ($codeLinks?->forGroup($g) ?? []) : []];
            $msg = TicketDraft::message($finding, $c->service, $c->environment, $window->start, $window->end, $read->deploy->release, $groups, $labels, $links);
            try {
                if ($options->dryRun) {
                    $out($msg->sections[0]['text'] ?? $msg->text);
                    continue;
                }
                $notifier->send($msg, $dest);
                $state->put($keys->draft($fp), ['at' => $at], $deliveredTtl);
            } catch (DeliveryFailed) {
            }
        }
    }
}
