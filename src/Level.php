<?php

declare(strict_types=1);

namespace Watcher;

/** PSR-3 severity, ordered debug < emergency. */
enum Level: int
{
    case Debug = 0;
    case Info = 1;
    case Notice = 2;
    case Warning = 3;
    case Error = 4;
    case Critical = 5;
    case Alert = 6;
    case Emergency = 7;

    /** Unknown or empty input falls back to Info (a line we cannot classify is not a failure); see tryParse to detect that. */
    public static function parse(string $raw): self
    {
        return self::tryParse($raw) ?? self::Info;
    }

    /**
     * Null when the token is not a known level. Numbers are pino's (10 trace .. 60 fatal) only: small ints are
     * not guessed here because `level: 3` may be syslog or a custom scale (use fromSyslog for a `severity` key).
     * There is no Trace case: trace maps to Debug.
     */
    public static function tryParse(string $raw): ?self
    {
        return match (strtolower(trim($raw))) {
            'trace', 'debug', '10', '20' => self::Debug,
            'info', 'ok', '30' => self::Info,
            'notice' => self::Notice,
            'warn', 'warning', '40' => self::Warning,
            'err', 'error', '50' => self::Error,
            'crit', 'critical' => self::Critical,
            'alert' => self::Alert,
            'emerg', 'emergency', 'fatal', 'panic', '60' => self::Emergency,
            default => null,
        };
    }

    /** RFC 5424 severity 0 (emergency) .. 7 (debug). */
    public static function fromSyslog(int $severity): ?self
    {
        return $severity >= 0 && $severity <= 7 ? self::from(7 - $severity) : null;
    }

    public function atLeast(self $other): bool
    {
        return $this->value >= $other->value;
    }
}
