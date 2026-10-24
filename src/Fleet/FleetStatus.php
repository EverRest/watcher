<?php

declare(strict_types=1);

namespace Watcher\Fleet;

use DateTimeZone;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Watcher\Config\Factory;
use Watcher\Operate\Heartbeat;
use Watcher\Operate\HeartbeatStatus;
use Watcher\Schedule\SystemClock;
use Watcher\State\StateStore;

/** `watcher fleet:status`: the fleet heartbeat and each service's, as in `status` (spec 002). Nothing is written. */
final class FleetStatus
{
    /** @param array<string, HeartbeatStatus> $services service => its heartbeat, in fleet order */
    public function __construct(
        public readonly string $name,
        public readonly HeartbeatStatus $fleet,
        public readonly array $services,
    ) {
    }

    /** @param array<string, mixed>|string $config a fleet array, or the path of a fleet file or directory */
    public static function fromConfig(array|string $config, ?ClockInterface $clock = null, ?LoggerInterface $log = null): self
    {
        $fleet = is_array($config) ? FleetConfig::fromArray($config) : FleetConfig::fromPath($config);
        $clock ??= new SystemClock();

        return self::forState($fleet, Factory::state($fleet->services[0]->config, $clock), $clock, $log);
    }

    public static function forState(FleetConfig $fleet, StateStore $state, ClockInterface $clock, ?LoggerInterface $log = null): self
    {
        $first = $fleet->services[0]->config;
        $own = (new Heartbeat($state, $clock, 'fleet/' . $fleet->name, new DateTimeZone($fleet->timezone), $first->state['retention'], $log))->status($first->heartbeat['stale_after']);
        $services = [];
        foreach ($fleet->inOrder() as $e) {
            $services[$e->name()] = Factory::heartbeat($e->config, $state, $clock, $log)->status($e->config->heartbeat['stale_after']);
        }

        return new self($fleet->name, $own, $services);
    }

    /** 1 when the fleet or any service is stale or failed; else 3 when any is unknown; else 0. */
    public function exitCode(): int
    {
        $codes = [$this->fleet->exitCode(), ...array_map(static fn (HeartbeatStatus $s): int => $s->exitCode(), array_values($this->services))];

        return in_array(1, $codes, true) ? 1 : (in_array(3, $codes, true) ? 3 : 0);
    }

    public function text(): string
    {
        $own = $this->fleet->text($this->name);
        $blocks = ['fleet:          ' . $this->name . "\n" . substr($own, (int) strpos($own, "\n") + 1)];
        foreach ($this->services as $name => $s) {
            $blocks[] = $s->text($name);
        }

        return implode("\n", $blocks);
    }

    public function toJson(): string
    {
        $services = [];
        foreach ($this->services as $name => $s) {
            $services[] = $s->toArray($name);
        }

        return json_encode([
            'schema' => 1,
            'fleet' => $this->name,
            'exit' => $this->exitCode(),
            'fleet_status' => $this->fleet->toArray($this->name),
            'services' => $services,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
