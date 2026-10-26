<?php

declare(strict_types=1);

namespace Watcher\Incident;

use Watcher\Analyze\Finding;
use Watcher\Analyze\Severity;

final class RouteTable
{
    /** @param list<Route> $routes */
    public function __construct(private readonly array $routes)
    {
    }

    /**
     * @param list<Finding> $findings
     *
     * @return array<int|string, list<Finding>>
     */
    public function assign(array $findings): array
    {
        $out = [];
        foreach ($findings as $f) {
            $out[$this->indexFor($f->severity)][] = $f;
        }

        return $out;
    }

    private function indexFor(Severity $severity): int|string
    {
        foreach ($this->routes as $i => $route) {
            if ($route->severities !== null && $route->matches($severity)) {
                return $i;
            }
        }
        $last = $this->routes[count($this->routes) - 1] ?? null;
        if ($last !== null && $last->severities === null) {
            return count($this->routes) - 1;
        }

        return 'default';
    }
}
