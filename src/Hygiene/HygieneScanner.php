<?php

declare(strict_types=1);

namespace Watcher\Hygiene;

use Watcher\Level;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Fingerprinter;
use Watcher\Reduce\Normalizer;
use Watcher\Source\LogEvent;

/**
 * One streaming pass over a window (FR-001, FR-003, FR-004): per call site, how many lines hold a personal-data pattern
 * and of which category. Memory is per site (bounded) and a few counters, never per event; a matched value is not
 * kept, only its category. No model is involved.
 */
final class HygieneScanner
{
    /** Context fields read per event; the rest are ignored. */
    public const MAX_FIELDS = 40;

    private const MEMO = 4096;

    private readonly PiiScan $pii;

    private readonly SiteId $siteIds;

    private readonly Fingerprinter $fingerprinter;

    /** @var array<string, SiteStats> sites with a personal-data hit or a shape habit */
    private array $sites = [];

    /** @var array<string, SiteStats> every other site, in a table of its own */
    private array $clean = [];

    /** @var array<string, Site> */
    private array $memo = [];

    private int $events = 0;

    /** @var array<string, array<string, true>> lower-case word => the site keys it was seen at (kept only up to literal_min_sites) */
    private array $wordSites = [];

    private int $otherEvents = 0;

    /** @var array<string, int> */
    private array $formats = [];

    private int $maxLength = 0;

    /** A request line of an access log (nginx, apache, php-fpm): `"GET /path HTTP/1.1"`. */
    private const ACCESS = '/"(?:GET|POST|PUT|DELETE|PATCH|HEAD|OPTIONS) \S+ HTTP\/[\d.]+"/';

    /** Stack-trace text pasted into a message: a newline, `Stack trace:`, `#0 file(12): call`, `at pkg.Class.method(File:12)`. */
    private const TRACE = '/\n|Stack trace:|#\d+ \S+\(\d+\)|\bat [\w$.\\]+\([^()\s]+:\d+\)/';

    /**
     * @param list<string> $extraPatternNames names of `redaction.extra_patterns`
     * @param list<string> $pathRoots roots of `repo.path_map`: a call site is a file below one of them
     */
    public function __construct(
        private readonly Normalizer $normalizer,
        Redactor $redactor,
        private readonly HygieneSettings $settings,
        array $extraPatternNames = [],
        private readonly int $maxSites = 5000,
        array $pathRoots = [],
    ) {
        $this->pii = new PiiScan($normalizer, $extraPatternNames);
        $this->siteIds = new SiteId($redactor, $pathRoots, $settings->siteAllowDirs);
        $this->fingerprinter = new Fingerprinter($normalizer);
    }

    /** The event with every string the scan reads (message, error type, frames, field values, labels) decoded and then cleaned ({@see ReportText::forScan()}). */
    public static function cleaned(LogEvent $e): LogEvent
    {
        $changed = false;
        $clean = static function (string $v) use (&$changed): string {
            $c = ReportText::forScan($v);
            $changed = $changed || $c !== $v;

            return $c;
        };
        $message = $clean($e->message);
        $type = $e->errorType === null ? null : $clean($e->errorType);
        $frames = array_map($clean, $e->frames);
        $fields = array_map(static fn (mixed $v): mixed => is_string($v) ? $clean($v) : $v, $e->fields);
        $labels = array_map($clean, $e->labels);

        return $changed ? new LogEvent($e->time, $e->level, $message, $type, $frames, $fields, $labels) : $e;
    }

    /**
     * Counts one event and returns it as the rest of the pass must see it (decoded, cleaned). The SHAPE habits (line breaks of a pasted
     * trace, the length of the line) are judged on the line as it was written, before the cleaning turns breaks into spaces.
     */
    public function observe(LogEvent $raw): LogEvent
    {
        $e = self::cleaned($raw);
        $this->record($e, $raw);

        return $e;
    }

    private function record(LogEvent $e, LogEvent $raw): void
    {
        ++$this->events;
        $text = $this->normalizer->normalizeOrUnscannable($e->message);
        if ($text === '<UNSCANNABLE>') {
            $text = null; // nothing could be scanned: no tokens, no categories, never the raw text
        }
        $tokens = $text === null || $text === '' ? [] : explode(' ', $text);
        $fp = $this->fingerprinter->fingerprint($e, $tokens);
        $site = $this->site($e, $fp, SiteId::shapeKey($e, $tokens));
        [$long, $trace, $mismatch] = $this->shape($raw);
        $cats = $this->categories($e, $text ?? null);
        $flagged = $cats !== [] || $long > 0 || $trace > 0 || $mismatch > 0;

        // A "site" for the word floor is a real code location (the file), never a message-derived key: one family that varies its second
        // word is still ONE source. Message families without a code location count together as one source.
        $this->noteWords($tokens, $site->kind === 'frame' ? substr($site->id, 0, (int) strrpos($site->id, ':')) : '~template');
        $stats = $this->sites[$site->key] ?? $this->clean[$site->key] ?? null;
        if ($stats === null) {
            // A site with a hit or a shape habit always gets a place in the main table; clean ones share a separate, equally bounded
            // one, so a writer of unique file names cannot push a real site out of the report (review L4).
            $table = $flagged ? 'sites' : 'clean';
            if (count($this->{$table}) >= $this->maxSites) {
                ++$this->otherEvents;

                return;
            }
            $stats = $this->{$table}[$site->key] = new SiteStats($site->key, $site->id, $site->kind, $fp, strtolower($e->level->name), $e->time, $e->time);
        } elseif ($flagged && isset($this->clean[$site->key])) {
            if (count($this->sites) >= $this->maxSites) {
                ++$this->otherEvents;

                return;
            }
            $this->sites[$site->key] = $stats;
            unset($this->clean[$site->key]);
        }
        ++$stats->lines;
        $stats->first = min($stats->first, $e->time);
        $stats->last = max($stats->last, $e->time);
        $stats->long += $long;
        $stats->trace += $trace;
        $stats->mismatch += $mismatch;
        if ($cats !== []) {
            ++$stats->anyHit;
            foreach (array_keys($cats) as $c) {
                $stats->cats[$c] = ($stats->cats[$c] ?? 0) + 1;
            }
        }
    }

    public function result(): ScanResult
    {
        $sites = array_values([...$this->sites, ...$this->clean]);
        usort($sites, static fn (SiteStats $a, SiteStats $b): int => [$b->lines, $a->key] <=> [$a->lines, $b->key]);

        return new ScanResult($this->events, $this->otherEvents, $sites, $this->formats, $this->maxLength, $this->pii->unscannable);
    }

    /**
     * Length, trace and format of the message, as counters (FR-003). The text itself is not kept.
     *
     * @return array{int, int, int} long, trace, mismatch (0 or 1 each)
     */
    private function shape(LogEvent $e): array
    {
        $length = strlen($e->message);
        $this->maxLength = max($this->maxLength, $length);
        $access = preg_match(self::ACCESS, $e->message) === 1;
        $class = $access ? 'access' : (in_array(ltrim($e->message)[0] ?? '', ['{', '['], true) ? 'json' : 'text');
        $this->formats[$class] = ($this->formats[$class] ?? 0) + 1;

        return [
            $length > $this->settings->maxLineLength ? 1 : 0,
            preg_match(self::TRACE, $e->message) === 1 ? 1 : 0,
            $access && $e->level->atLeast(Level::Warning) ? 1 : 0,
        ];
    }

    /** @param list<string> $tokens */
    private function noteWords(array $tokens, string $siteKey): void
    {
        foreach ($tokens as $token) {
            if (preg_match_all('/\p{L}[\p{L}\p{N}_\']*/u', $token, $m) === 0) {
                continue;
            }
            foreach ($m[0] as $word) {
                $w = mb_strtolower($word);
                if (!isset($this->wordSites[$w]) && count($this->wordSites) >= 50000) {
                    continue; // bounded: a log of unique words cannot grow this without limit
                }
                if (count($this->wordSites[$w] ?? []) < $this->settings->literalMinSites) {
                    $this->wordSites[$w][$siteKey] = true;
                }
            }
        }
    }

    /** @return array<string, true> words seen at `literal_min_sites` or more different sites of this pass */
    public function commonWords(): array
    {
        $min = $this->settings->literalMinSites;

        return array_fill_keys(array_keys(array_filter($this->wordSites, static fn (array $sites): bool => count($sites) >= $min)), true);
    }

    /** @return array<string, true> category names present anywhere in the message or the first context fields */
    private function categories(LogEvent $e, ?string $maskedMessage): array
    {
        $found = [];
        foreach ($this->pii->categories($e->message, $maskedMessage) as $c) {
            $found[$c->value] = true;
        }
        $n = 0;
        foreach ($e->fields as $value) {
            if (++$n > self::MAX_FIELDS) {
                break;
            }
            // A card or a phone is as often a JSON number as a string.
            if ((is_string($value) && $value !== '') || is_int($value) || is_float($value)) {
                foreach ($this->pii->categories((string) $value) as $c) {
                    $found[$c->value] = true;
                }
            }
        }
        // Enum order, so the stored shape does not depend on where a pattern was seen first.
        return array_intersect_key(array_fill_keys(array_map(static fn (Category $c): string => $c->value, Category::cases()), true), $found);
    }

    private function site(LogEvent $e, string $fp, string $shape): Site
    {
        $memoKey = ($e->fields['file'] ?? '') . ':' . ($e->fields['line'] ?? '') . '|' . ($e->frames[0] ?? '') . '|' . $fp;
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }
        if (count($this->memo) >= self::MEMO) {
            $this->memo = [];
        }

        return $this->memo[$memoKey] = $this->siteIds->of($e, $fp, $shape);
    }
}
