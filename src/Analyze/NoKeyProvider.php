<?php

declare(strict_types=1);

namespace Watcher\Analyze;

/** Stands in for a provider in use that has no key: every call fails at once, with no request. Spec 019 FR-001. */
final class NoKeyProvider implements ModelProvider
{
    public function create(string $model, int $maxTokens, string $system, string $user, array $schema, bool $thinking): ModelReply
    {
        throw new AnalyzerFailed('no key', false, 'no key');
    }
}
