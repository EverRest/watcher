<?php

declare(strict_types=1);

/**
 * Regenerates the README badge block and the local SVGs under docs/badges/. No network, no dependencies.
 *
 *   php scripts/badges.php
 *
 * Coverage comes from build/coverage/cobertura.xml (or clover.xml), produced by `make coverage` / CI; without a
 * report the existing coverage badge is kept. A file is rewritten only when its content changes, so the
 * pre-commit hook can `git add` exactly what moved.
 */

const BADGE_START = '<!-- badges:start -->';
const BADGE_END = '<!-- badges:end -->';

function badges_svg(string $label, string $message, string $color): string
{
    $width = static fn (string $t): int => (int) ceil(strlen($t) * 6.5) + 10;
    $esc = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_XML1);
    $l = $width($label);
    $r = $width($message);
    $total = $l + $r;
    $lx = $l / 2;
    $rx = $l + $r / 2;
    $label = $esc($label);
    $message = $esc($message);

    return <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="{$total}" height="20" role="img" aria-label="{$label}: {$message}">
  <title>{$label}: {$message}</title>
  <clipPath id="r"><rect width="{$total}" height="20" rx="3" fill="#fff"/></clipPath>
  <g clip-path="url(#r)">
    <rect width="{$l}" height="20" fill="#555"/>
    <rect x="{$l}" width="{$r}" height="20" fill="{$color}"/>
  </g>
  <g fill="#fff" text-anchor="middle" font-family="Verdana,Geneva,DejaVu Sans,sans-serif" font-size="11">
    <text x="{$lx}" y="14">{$label}</text>
    <text x="{$rx}" y="14">{$message}</text>
  </g>
</svg>

SVG;
}

/** @return string e.g. "^8.1" from composer.json require.php, or "unknown" */
function badges_php(string $composerJson): string
{
    $data = json_decode($composerJson, true);

    return is_array($data) && is_string($data['require']['php'] ?? null) ? $data['require']['php'] : 'unknown';
}

function badges_license(string $composerJson): string
{
    $data = json_decode($composerJson, true);

    return is_array($data) && is_string($data['license'] ?? null) ? $data['license'] : 'unknown';
}

/** Line coverage in percent from a Cobertura (line-rate) or Clover (statements) report; null when unreadable. */
function badges_coverage(string $xml): ?float
{
    if (preg_match('/<coverage\b[^>]*\bline-rate="([\d.]+)"/', $xml, $m) === 1) {
        return round((float) $m[1] * 100, 1);
    }
    if (preg_match('/<project\b.*?<metrics\b([^>]*)>/s', $xml, $m) === 1
        && preg_match('/\bstatements="(\d+)"/', $m[1], $s) === 1
        && preg_match('/\bcoveredstatements="(\d+)"/', $m[1], $c) === 1) {
        return (int) $s[1] === 0 ? 0.0 : round((int) $c[1] / (int) $s[1] * 100, 1);
    }

    return null;
}

function badges_coverage_color(float $pct): string
{
    return $pct >= 80 ? '#4c1' : ($pct >= 60 ? '#97ca00' : ($pct >= 40 ? '#dfb317' : '#e05d44'));
}

/** Replaces the text between the markers; returns the input untouched when the markers are missing. */
function badges_inject(string $readme, string $block): string
{
    $s = strpos($readme, BADGE_START);
    $e = strpos($readme, BADGE_END);
    if ($s === false || $e === false || $e < $s) {
        return $readme;
    }

    return substr($readme, 0, $s + strlen(BADGE_START)) . "\n" . $block . "\n" . substr($readme, $e);
}

function badges_block(): string
{
    return implode("\n", [
        '[![CI](https://github.com/EverRest/watcher/actions/workflows/ci.yml/badge.svg)](https://github.com/EverRest/watcher/actions/workflows/ci.yml)',
        '[![Last released version](https://img.shields.io/github/v/release/EverRest/watcher?label=last%20successful%20release)](https://github.com/EverRest/watcher/releases)',
        '![PHP](docs/badges/php.svg)',
        '![Coverage](docs/badges/coverage.svg)',
        '![License](docs/badges/license.svg)',
    ]);
}

function badges_write(string $path, string $content): void
{
    if (is_file($path) && file_get_contents($path) === $content) {
        return;
    }
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $content);
    echo "wrote {$path}\n";
}

function badges_run(string $root): void
{
    $composer = (string) @file_get_contents("{$root}/composer.json");

    $dir = "{$root}/docs/badges";
    badges_write("{$dir}/php.svg", badges_svg('php', badges_php($composer), '#777bb4'));
    badges_write("{$dir}/license.svg", badges_svg('license', badges_license($composer), '#9f9f9f'));

    foreach (['cobertura', 'clover'] as $report) {
        $xml = @file_get_contents("{$root}/build/coverage/{$report}.xml");
        $pct = $xml === false ? null : badges_coverage($xml);
        if ($pct !== null) {
            badges_write("{$dir}/coverage.svg", badges_svg('coverage', $pct . '%', badges_coverage_color($pct)));
            break;
        }
    }
    if (! is_file("{$dir}/coverage.svg")) {
        badges_write("{$dir}/coverage.svg", badges_svg('coverage', 'n/a', '#9f9f9f'));
    }

    $readmePath = "{$root}/README.md";
    $readme = (string) @file_get_contents($readmePath);
    badges_write($readmePath, badges_inject($readme, badges_block()));
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    badges_run(dirname(__DIR__));
}
