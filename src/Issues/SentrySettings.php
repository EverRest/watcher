<?php

declare(strict_types=1);

namespace Watcher\Issues;

use Closure;
use Watcher\Config\InvalidConfig;
use Watcher\Config\SecureUrl;
use Watcher\Source\Slug;

/**
 * The validated `sentry` section (spec 009, contracts/config.md). Errors name the key path only. The auth token leaves the
 * array first and lives in a closure, so no dump of this object (print_r, var_dump, var_export, serialize, json_encode) holds it.
 */
final class SentrySettings implements \JsonSerializable
{
    private const KEYS = ['enabled', 'base_url', 'organization', 'project', 'auth_token', 'max_issues', 'allow_insecure', 'allow_private_network'];

    /** @var Closure(): string */
    private readonly Closure $token;

    private function __construct(
        public readonly bool $enabled,
        public readonly string $baseUrl,
        public readonly string $organization,
        public readonly string $project,
        public readonly int $maxIssues,
        public readonly bool $allowInsecure,
        public readonly bool $allowPrivateNetwork,
        string $token,
    ) {
        $this->token = static fn (): string => $token;
    }

    /** @param array<array-key, mixed> $raw */
    public static function fromArray(#[\SensitiveParameter] array $raw): self
    {
        $token = $raw['auth_token'] ?? null; // out of the array first: no helper below receives it
        unset($raw['auth_token']);
        foreach (array_keys($raw) as $key) {
            if (!in_array((string) $key, self::KEYS, true)) {
                throw new InvalidConfig('Unknown configuration key: sentry.' . $key);
            }
        }
        $enabled = self::bool($raw, 'enabled', true);
        $allowInsecure = self::bool($raw, 'allow_insecure', false);
        $allowPrivate = self::bool($raw, 'allow_private_network', false);
        $url = $raw['base_url'] ?? 'https://sentry.io';
        if (!is_string($url) || $url === '') {
            throw new InvalidConfig('sentry.base_url must be a URL');
        }
        if (($problem = SecureUrl::problem($url, $allowInsecure, true, $allowPrivate)) !== null) {
            throw new InvalidConfig('sentry.base_url ' . $problem);
        }
        foreach (['organization', 'project'] as $k) {
            if (!is_string($raw[$k] ?? null) || $raw[$k] === '') {
                throw new InvalidConfig("sentry.{$k} is required");
            }
            if (!Slug::valid($raw[$k])) {
                throw new InvalidConfig("sentry.{$k} must be letters, digits, _ . - and not only dots");
            }
        }
        if (!is_string($token) || $token === '') {
            throw new InvalidConfig('sentry.auth_token is required');
        }
        $max = $raw['max_issues'] ?? 50;
        if (!is_int($max) || $max < 1 || $max > 100) {
            throw new InvalidConfig('sentry.max_issues must be an integer between 1 and 100');
        }

        return new self($enabled, $url, $raw['organization'], $raw['project'], $max, $allowInsecure, $allowPrivate, $token);
    }

    public function token(): string
    {
        return ($this->token)();
    }

    /** @return list<string> the credential, masked everywhere by WatchConfig::secrets() */
    public function secrets(): array
    {
        return [$this->token()];
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['secrets' => '[redacted]'];
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('SentrySettings is not serialisable.');
    }

    /** @param array<array-key, mixed> $from */
    private static function bool(array $from, string $key, bool $default): bool
    {
        $v = $from[$key] ?? $default;
        if (!is_bool($v)) {
            throw new InvalidConfig("sentry.{$key} must be true or false");
        }

        return $v;
    }
}
