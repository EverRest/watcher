<?php

declare(strict_types=1);

namespace Watcher\Source\Kubernetes;

/**
 * A credential (or the place one lives) held so that no debug or export path prints it: var_dump and print_r use __debugInfo, serialize throws,
 * json_encode sees nothing, and var_export / (array) casts reach only a closure (`Closure::__set_state(array())`), never the value.
 */
final class SecretValue implements \JsonSerializable
{
    private readonly \Closure $read;

    public function __construct(#[\SensitiveParameter] string $value)
    {
        $this->read = static fn (): string => $value;
    }

    public function reveal(): string
    {
        return ($this->read)();
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
