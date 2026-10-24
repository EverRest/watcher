<?php

declare(strict_types=1);

namespace Watcher\Fleet;

use Closure;
use Psr\Clock\ClockInterface;
use Watcher\Config\Factory;
use Watcher\Operate\CheckReport;
use Watcher\Operate\CheckResult;
use Watcher\Operate\Doctor;
use Watcher\Schedule\SystemClock;

/**
 * `watcher fleet:check`: the single-service check for every service (no spend), one shared state check, the resolved
 * order, and a warning for a declared dependency that no fleet service provides.
 */
final class FleetCheck
{
    /** @param Closure(ServiceEntry, bool): CheckReport $reportFor the single-service check of one entry */
    public function __construct(
        private readonly FleetConfig $fleet,
        private readonly Closure $reportFor,
    ) {
    }

    /** @param array<string, mixed>|string $config a fleet array, or the path of a fleet file or directory */
    public static function fromConfig(array|string $config, ?ClockInterface $clock = null): self
    {
        $fleet = is_array($config) ? FleetConfig::fromArray($config) : FleetConfig::fromPath($config);
        $clock ??= new SystemClock();
        $http = Factory::http();
        $state = Factory::state($fleet->services[0]->config, $clock);
        $notifier = Factory::notifier($http, $fleet->services[0]->config->schedule['lock_ttl']);

        return new self($fleet, static fn (ServiceEntry $e, bool $postTest): CheckReport => (new Doctor(
            $e->config,
            Factory::source($e->config, $http, $clock),
            $state,
            Factory::modelCatalog($e->config, $http),
            $notifier,
            $clock,
            getenv('KUBERNETES_SERVICE_HOST') !== false && getenv('KUBERNETES_SERVICE_HOST') !== '',
        ))->run($postTest));
    }

    /** The report for a fleet that did not validate: nothing else can be built. */
    public static function invalid(string $reason): CheckReport
    {
        return Doctor::configFailure('invalid: ' . $reason);
    }

    public function run(bool $postTest): CheckReport
    {
        $ordered = $this->fleet->inOrder();
        $results = [CheckResult::pass('order', implode(', ', array_map(static fn (ServiceEntry $e): string => $e->name(), $ordered)))];
        $provided = [];
        foreach ($ordered as $e) {
            foreach ($e->provides as $p) {
                $provided[$p] = true;
            }
        }
        foreach ($ordered as $e) {
            foreach ($e->dependencies() as $d) {
                if (!isset($provided[$d])) {
                    $results[] = CheckResult::pass('dependencies', sprintf("warning: %s depends on '%s', provided by no fleet service", $e->name(), $d));
                }
            }
        }

        $reports = [];
        $state = null;
        foreach ($ordered as $e) {
            $reports[$e->name()] = ($this->reportFor)($e, $postTest);
            foreach ($reports[$e->name()]->results as $r) {
                if ($r->name === 'state' && ($state === null || ($state->status !== 'fail' && $r->status === 'fail'))) {
                    $state = $r; // one shared store: report it once, and a failure wins
                }
            }
        }
        if ($state !== null) {
            $results[] = $state;
        }
        foreach ($reports as $service => $report) {
            foreach ($report->results as $r) {
                if ($r->name !== 'state') {
                    $results[] = new CheckResult($service . '/' . $r->name, $r->status, $r->reason);
                }
            }
        }

        return new CheckReport($results);
    }
}
