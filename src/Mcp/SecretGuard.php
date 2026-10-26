<?php

declare(strict_types=1);

namespace Watcher\Mcp;

use Throwable;
use Watcher\Reduce\Normalizer;

/**
 * FR-064: refuses a secret-shaped string before anything else runs. The shapes are the Normalizer's own rules (a
 * `<TOKEN>` placeholder after a scan: known prefixes, JWT, PEM, credential headers and keys, long random runs), run
 * on the canonical form (percent-encoding, zero-width characters, look-alikes folded), plus the shapes below that
 * the Normalizer does not mark: webhooks, bot tokens, userinfo in a URL, a DSN password. Best effort (A6): the guarantee
 * is structural (no credential property, no value field); this stops a secret from being handled further.
 */
final class SecretGuard
{
    private const SHAPES = [
        '~hooks\.slack\.com/(?:services|workflows|triggers)/\S{6,}~i',
        '~(?:discord(?:app)?\.com)/api(?:/v\d+)?/webhooks/\d+/[\w-]{8,}~i',
        '~(?<![\w:])\d{6,}:[A-Za-z0-9_-]{30,}(?![\w-])~',
        '~\b[a-z][a-z0-9+.-]*://[^\s/?#@]*@~i',          // userinfo in a URL
        '~\b(?:redis|rediss|mysql|pgsql|postgres(?:ql)?|sqlsrv|mongodb)(?:\+\w+)?:[^\s]*(?:password|pwd|pass)=[^\s;&]+~i', // PDO/DSN password
        '~\b(?:bearer|basic)\s+[A-Za-z0-9._\~+/=-]{8,}~i',
        '~-----BEGIN [A-Z ]{0,40}(?:PRIVATE KEY|CERTIFICATE)-----~',
        '~\beyJ[\w-]{5,}\.[\w-]{5,}\.[\w-]{5,}~',
    ];

    private readonly Normalizer $normalizer;

    public function __construct(?Normalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new Normalizer();
    }

    public function looksSecret(#[\SensitiveParameter] string $text): bool
    {
        if ($text === '' || strlen($text) > 20000) {
            return strlen($text) > 20000; // nothing in the contract is that long: refuse, do not scan
        }
        try {
            $canon = Normalizer::canonical($text);
            foreach ([$text, $canon] as $form) {
                foreach (self::SHAPES as $shape) {
                    if (preg_match($shape, $form) === 1) {
                        return true;
                    }
                }
            }

            return str_contains($this->normalizer->scan($canon)['text'], '<TOKEN>');
        } catch (Throwable) {
            return true; // fail closed: a value that cannot be scanned is refused
        }
    }

    /**
     * Every string of $value, keys included.
     *
     * @throws SecretRefused
     */
    public function assertClean(#[\SensitiveParameter] mixed $value): void
    {
        if (is_string($value)) {
            if ($this->looksSecret($value)) {
                throw new SecretRefused();
            }

            return;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $this->assertClean(is_string($k) ? $k : (string) $k);
                $this->assertClean($v);
            }
        }
    }
}
