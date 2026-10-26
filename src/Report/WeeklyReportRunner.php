<?php

declare(strict_types=1);

namespace Watcher\Report;

use Psr\Clock\ClockInterface;
use Throwable;
use Watcher\Config\Factory;
use Watcher\Config\WatchConfig;
use Watcher\Notify\Notifier;
use Watcher\State\Keys;
use Watcher\State\StateStore;

final class WeeklyReportRunner
{
    public function __construct(
        private readonly WatchConfig $config,
        private readonly StateStore $state,
        private readonly ClockInterface $clock,
        private readonly Notifier $notifier,
        private readonly EventLog $log,
        private readonly WeeklyReport $report,
        private readonly ReportRenderer $renderer,
    ) {
    }

    public static function fromConfig(WatchConfig $config, ClockInterface $clock, ?Notifier $notifier = null): self
    {
        $http = Factory::http();
        $notifier ??= Factory::notifier($http, $config->schedule['lock_ttl']);
        $store = Factory::state($config, $clock);
        $file = $config->observe->file;
        $env = $config->environment ?? 'unset';

        return new self(
            $config,
            $store,
            $clock,
            $notifier,
            new EventLog($file, $config->service, $env),
            new WeeklyReport(),
            new ReportRenderer(),
        );
    }

    public function run(?Week $week = null, bool $dryRun = false): ReportRunResult
    {
        if (!$this->config->incident->reportWeeklyEnabled) {
            return new ReportRunResult(ReportRunResult::OUTCOME_DISABLED);
        }
        $week ??= Week::lastComplete($this->clock->now());
        $marker = Keys::reportSent($this->config->stateKey, $week->iso);
        if (!$dryRun && $this->state->get($marker) !== null) {
            return new ReportRunResult(ReportRunResult::OUTCOME_ALREADY, text: 'report ' . $week->iso . ' already sent');
        }
        try {
            $snapshot = $this->log->read($week);
        } catch (EventLogException $e) {
            throw $e;
        }
        $data = $this->report->build(
            $snapshot,
            $week,
            $this->config->service,
            $this->config->environment ?? 'unset',
            $this->config->incident->knownIssues,
            $this->config->incident->reportWeeklyTop,
            $this->config->observe->topGroups,
        );
        if ($data === null) {
            return new ReportRunResult(ReportRunResult::OUTCOME_NO_RUNS);
        }
        $message = $this->renderer->render($data);
        if ($dryRun) {
            return new ReportRunResult(ReportRunResult::OUTCOME_SENT, $data, $message->text);
        }
        $dest = $this->config->incident->reportWeeklyDestination !== null
            ? WatchConfig::destinationFrom($this->config->incident->reportWeeklyDestination)
            : $this->config->destination('notice');
        $owner = bin2hex(random_bytes(8));
        if (!$this->state->acquire($marker, $owner, Keys::ttl(Keys::REPORTSENT, $this->config->state['retention'], 0))) {
            return new ReportRunResult(ReportRunResult::OUTCOME_ALREADY, text: 'report ' . $week->iso . ' already sent');
        }
        try {
            $this->notifier->send($message, $dest);
        } catch (Throwable $e) {
            $this->state->release($marker, $owner);

            throw $e;
        }

        return new ReportRunResult(ReportRunResult::OUTCOME_SENT, $data, $message->text);
    }
}
