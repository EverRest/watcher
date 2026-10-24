<?php

declare(strict_types=1);

namespace Watcher\Analyze;

interface MessagesClient
{
    /**
     * One structured-output call, never retried here.
     *
     * @param array<string, mixed> $schema
     *
     * @throws AnalyzerFailed 'authentication failed' or 'provider unavailable'; nothing from the request or the reply is carried
     */
    public function create(string $model, int $maxTokens, string $system, string $user, array $schema, bool $thinking): ModelReply;
}
