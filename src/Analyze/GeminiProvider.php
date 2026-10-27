<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use GuzzleHttp\ClientInterface;

/**
 * Gemini generateContent over the package's HTTP client (no official PHP SDK exists, research R2). Structured output is the
 * stripped schema in `responseJsonSchema`; the analyzer still validates the reply in code. Implicit caching is reported as
 * `cachedContentTokenCount` (read tokens); no cache-write count exists, so writes are 0. Thinking is turned off with
 * `thinkingBudget` 0 for the non-thinking step; the exact field and its per-model support are still to be verified (research R3).
 * The client never retries itself: `maxRetries` must be 0 (config refuses more).
 */
final class GeminiProvider implements ModelProvider
{
    private const STOPS = ['STOP' => 'end', 'MAX_TOKENS' => 'max_tokens', 'SAFETY' => 'refusal'];

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
            throw new \InvalidArgumentException('Gemini adapter does not retry; set analysis.providers_options.gemini.retries to 0');
        }
        $this->apiKey = static fn (): string => $apiKey;
    }

    public function __debugInfo(): array
    {
        return ['apiKey' => '[redacted]'];
    }

    public function create(string $model, int $maxTokens, string $system, string $user, array $schema, bool $thinking): ModelReply
    {
        $generation = [
            'maxOutputTokens' => $maxTokens,
            'responseMimeType' => 'application/json',
            'responseJsonSchema' => $schema,
        ];
        if (!$thinking) {
            $generation['thinkingConfig'] = ['thinkingBudget' => 0];
        }

        [, $data] = GuzzlePost::json(
            $this->http,
            rtrim($this->baseUrl, '/') . '/v1beta/models/' . rawurlencode($model) . ':generateContent',
            ['x-goog-api-key' => ($this->apiKey)()],
            [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
                'generationConfig' => $generation,
            ],
            (float) $this->timeoutSeconds,
            'gemini',
        );

        return self::reply($model, $data);
    }

    /** @param array<mixed> $data */
    private static function reply(string $requested, array $data): ModelReply
    {
        $candidate = $data['candidates'][0] ?? null;
        $usage = $data['usageMetadata'] ?? null;
        $in = is_array($usage) ? ($usage['promptTokenCount'] ?? null) : null;
        $out = is_array($usage) ? ($usage['candidatesTokenCount'] ?? null) : null;
        $cached = is_array($usage) ? ($usage['cachedContentTokenCount'] ?? 0) : 0;
        if (!is_int($in) || !is_int($out) || $in < 0 || $out < 0 || !is_int($cached) || $cached < 0 || !is_array($candidate)) {
            throw new AnalyzerFailed('provider unavailable (invalid reply)');
        }

        $text = null;
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $text = $part['text'];
                break;
            }
        }
        $finish = $candidate['finishReason'] ?? null;
        $model = is_string($data['modelVersion'] ?? null) ? $data['modelVersion'] : $requested;

        return new ModelReply($text, is_string($finish) ? (self::STOPS[$finish] ?? null) : null, $model, $in, $out, $cached, 0);
    }
}
