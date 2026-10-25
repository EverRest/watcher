<?php

declare(strict_types=1);

namespace Watcher\ApiContext;

/** Finds the first HTTP route in a masked template text (research R5). Reads that string and nothing else. */
final class RouteExtractor
{
    private const PATTERN = '#(?:(?<![\w/])(?<m>GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\s+)?(?<![\w<>}])(?<p>/[A-Za-z0-9._~%:<>\-/…]*)#u';
    private const PARAM = '/^<(?:ID|N|TOKEN|EMAIL|NAME|STR|USER)>$/';

    public function extract(?string $template): ?Route
    {
        if ($template === null || preg_match_all(self::PATTERN, $template, $all, PREG_SET_ORDER) < 1) {
            return null;
        }
        foreach ($all as $m) {
            $method = $m['m'] !== '' ? $m['m'] : null;
            $route = $this->route($method, rtrim($m['p'], '.,;:)"\''));
            // "3 / 5" and "a // b" are prose: without a method a route needs a real segment ("GET /" is the only bare root)
            if ($route !== null && ($method !== null || $route->segments !== [] || $route->unmatchable)) {
                return $route;
            }
        }

        return null;
    }

    private function route(?string $method, string $path): Route
    {
        $segments = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match(self::PARAM, $part) === 1) {
                $segments[] = new Segment(Segment::PARAM, $part);
            } elseif (strpbrk($part, '<>') !== false || str_contains($part, '…')) {
                return new Route($method, [], true);
            } else {
                $segments[] = new Segment(Segment::LITERAL, $part);
            }
        }

        return new Route($method, $segments);
    }
}
