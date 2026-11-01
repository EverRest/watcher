<?php

declare(strict_types=1);

namespace Watcher\Analyze;

/** The provider of each step (triage, analyze, escalate), resolved to the adapter that serves it. */
final class ProviderRegistry
{
    /**
     * @param array<string, ModelProvider> $providers adapter per provider name
     * @param array<string, string>        $steps     provider name per step
     */
    public function __construct(private readonly array $providers, private readonly array $steps)
    {
    }

    /** One provider for every step (tests and single-provider wiring). */
    public static function single(ModelProvider $provider, string $name = 'gemini'): self
    {
        return new self([$name => $provider], ['triage' => $name, 'analyze' => $name, 'escalate' => $name]);
    }

    public function for(string $step): ModelProvider
    {
        return $this->providers[$this->steps[$step] ?? throw new \LogicException("no provider for step $step")];
    }
}
