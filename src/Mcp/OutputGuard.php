<?php

declare(strict_types=1);

namespace Watcher\Mcp;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use Watcher\Analyze\CommandFilter;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;

/**
 * The text pipeline of FR-022 and the typed validators of FR-023. A string that came from a log or a model leaves only through
 * {@see text()}: canonical form (format characters, look-alikes, encodings), the Redactor's second scan, the digest's command
 * and link filter, markdown links, then the cap. A scan failure drops the field (null). Typed fields that fail their pattern are null,
 * never passed on as text.
 */
final class OutputGuard
{
    public function __construct(private readonly Redactor $redactor)
    {
    }

    public function text(mixed $value, int $cap): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        try {
            if (preg_match('//u', $value) !== 1) {
                return null;
            }
            $v = mb_substr($value, 0, $cap * 4); // bound the work; the cap below is the real one
            $v = (string) preg_replace('/[\x00-\x1F\x7F\x{80}-\x{9F}]+/u', ' ', $v);
            $v = Normalizer::canonical($v);
            $v = $this->redactor->redactText($v);
            if ($v === null) {
                return null;
            }
            $v = (string) preg_replace('/!?\[[^\]]*\]\([^)]*\)/u', Redactor::WITHHELD, $v);
            $v = CommandFilter::withhold($v, true);
            $v = trim((string) preg_replace('/\s+/u', ' ', $v));
            // a cut inside "[withheld]" would leave a fragment: cut before the span instead
            if (mb_strlen($v) > $cap) {
                $v = mb_substr($v, 0, $cap - 1);
                $v = (string) preg_replace('/\[withheld?\]?$|\[w[a-z]*$/u', '', $v) . '…';
            }

            return $v;
        } catch (Throwable) {
            return null;
        }
    }

    public static function fingerprint(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^[0-9a-f]{12}$/D', $v) === 1 ? $v : null;
    }

    /** @param list<string> $allowed */
    public static function enum(mixed $v, array $allowed): ?string
    {
        return is_string($v) && in_array($v, $allowed, true) ? $v : null;
    }

    public static function severity(mixed $v): ?string
    {
        return self::enum($v, ['info', 'warn', 'error', 'critical']);
    }

    public static function kind(mixed $v): ?string
    {
        return self::enum($v, ['bug', 'regression', 'anomaly', 'noise']);
    }

    public static function outcome(mixed $v): ?string
    {
        return self::enum($v, ['reported', 'quiet', 'baselined', 'failed']);
    }

    public static function int(mixed $v, int $max = PHP_INT_MAX): ?int
    {
        return is_int($v) && $v >= 0 && $v <= $max ? $v : null;
    }

    public static function float(mixed $v, float $min = 0.0, float $max = 1.0e12): ?float
    {
        return (is_int($v) || is_float($v)) && is_finite((float) $v) && $v >= $min && $v <= $max ? (float) $v : null;
    }

    /** ISO-8601 in any offset (the stored DATE_ATOM form) re-formatted to UTC `...Z`. */
    public static function timestamp(mixed $v): ?string
    {
        if (!is_string($v) || strlen($v) > 40 || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $v) !== 1) {
            return null;
        }
        try {
            return (new DateTimeImmutable($v))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable) {
            return null;
        }
    }
}
