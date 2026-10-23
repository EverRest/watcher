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

    /** Unknown or empty input falls back to Info (a line we cannot classify is not a failure). */
    public static function parse(string $raw): self
    {
        return match (strtolower(trim($raw))) {
            'debug' => self::Debug,
            'notice' => self::Notice,
            'warn', 'warning' => self::Warning,
            'err', 'error' => self::Error,
            'crit', 'critical' => self::Critical,
            'alert' => self::Alert,
            'emerg', 'emergency', 'fatal' => self::Emergency,
            default => self::Info,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->value >= $other->value;
    }
}
