<?php

declare(strict_types=1);

namespace Watcher\Source\Kubernetes;

/**
 * A credential (or the place one lives) held so that no debug or export path prints it. The object has NO properties: the value sits in a
 * private static map keyed by the object's id, so `(array)`, `get_object_vars`, `var_export`, `print_r`, `var_dump`, `serialize`,
 * `json_encode` and `clone` all see nothing (serialize throws, json shows a fixed word). Reflection and a heap dump still reach it, as
 * they would any value the process holds; the goal is that no log line or error page can.
 */
final class SecretValue implements \JsonSerializable
{
    /** @var array<int, string> */
    private static array $values = [];

    public function __construct(#[\SensitiveParameter] string $value)
    {
        self::$values[spl_object_id($this)] = $value;
    }

    public function __destruct()
    {
        unset(self::$values[spl_object_id($this)]);
    }

    public function __clone()
    {
        throw new \LogicException('a credential is not cloneable');
    }

    public function reveal(): string
    {
        return self::$values[spl_object_id($this)];
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['value' => '<redacted>'];
    }

    public function jsonSerialize(): string
    {
        return '<redacted>';
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('a credential is not serialisable');
    }
}
