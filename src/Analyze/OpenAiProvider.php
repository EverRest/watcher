<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use GuzzleHttp\ClientInterface;

/**
 * OpenAI Chat Completions over the package's HTTP client (no official PHP SDK exists, research R2). Structured output is the
 * stripped schema in `response_format`; the analyzer still validates the reply in code. Caching is reported as read tokens
 * only; OpenAI reports no cache-write count on this endpoint, so writes are 0. The client never retries itself: `maxRetries`
 * is accepted for the shared constructor shape and must be 0 (config refuses more).
 */
final class OpenAiProvider implements ModelProvider
{
    private const STOPS = ['stop' => 'end', 'length' => 'max_tokens', 'content_filter' => 'refusal'];

    /** The key lives in a closure, not a property, so var_export() cannot print it. */
    private readonly \Closure $apiKey;

    public function __construct(
        private readonly ClientInterface $http,
        #[\SensitiveParameter] string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
        int $maxRetries = 0,
    ) {
        if ($maxRetries !== 0) {
            throw new \InvalidArgumentException('OpenAI adapter does not retry; set analysis.providers_options.openai.retries to 0');
        }
        $this->apiKey = static fn (): string => $apiKey;
    }

    public function __debugInfo(): array
    {
        return ['apiKey' => '[redacted]'];
    }

    public function create(string $model, int $maxTokens, string $system, string $user, array $schema, bool $thinking): ModelReply
    {
        [, $data] = GuzzlePost::json(
            $this->http,
            rtrim($this->baseUrl, '/') . '/v1/chat/completions',
            ['Authorization' => 'Bearer ' . ($this->apiKey)()],
            [
                'model' => $model,
                'max_completion_tokens' => $maxTokens,
                // The official reference lists `none` for reasoning_effort; not every model accepts the parameter (a model
                // that rejects it fails the call loudly, as a 400).
                ...($thinking ? [] : ['reasoning_effort' => 'none']),
                'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
                // Not strict: the stripped schema does not set additionalProperties:false, which strict mode requires.
                'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'analysis', 'schema' => $schema, 'strict' => false]],
            ],
            (float) $this->timeoutSeconds,
            'openai',
        );

        return self::reply($data);
    }

    /** @param array<mixed> $data */
    private static function reply(array $data): ModelReply
    {
        $choice = $data['choices'][0] ?? null;
        $usage = $data['usage'] ?? null;
        $in = is_array($usage) ? ($usage['prompt_tokens'] ?? null) : null;
        $out = is_array($usage) ? ($usage['completion_tokens'] ?? null) : null;
        $cached = is_array($usage) ? ($usage['prompt_tokens_details']['cached_tokens'] ?? 0) : 0;
        if (!is_int($in) || !is_int($out) || $in < 0 || $out < 0 || !is_int($cached) || $cached < 0 || $cached > $in || !is_string($data['model'] ?? null) || !is_array($choice)) {
            throw new AnalyzerFailed('provider unavailable (invalid reply)');
        }

        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $text = is_string($message['content'] ?? null) ? $message['content'] : null;
        $finish = $choice['finish_reason'] ?? null;
        // A structured-output refusal arrives as message.refusal with finish_reason stop: a refusal, not an empty answer.
        $refused = is_string($message['refusal'] ?? null);
        $stop = $refused ? 'refusal' : (is_string($finish) ? (self::STOPS[$finish] ?? null) : null);

        return new ModelReply($text, $stop, $data['model']);
    }
}
