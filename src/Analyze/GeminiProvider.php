<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use GuzzleHttp\ClientInterface;

/**
 * Gemini generateContent over the package's HTTP client (no official PHP SDK exists, research R2). Structured output is the
 * stripped schema in `responseJsonSchema`; the analyzer still validates the reply in code. Implicit caching is reported as
 * `cachedContentTokenCount` (read tokens); no cache-write count exists, so writes are 0. Output tokens include the thinking
 * tokens (`thoughtsTokenCount`), which the official reference counts separately from `candidatesTokenCount`. No `thinkingConfig`
 * is sent: the reference says it errors on models without thinking, and it does not document 0 as "off" (research R3). So the
 * triage step may think and pay for it; this is a known gap until a per-model setting is verified.
 * Transient failures are retried by {@see GuzzlePost} (`maxRetries` 0 to 3, within the call timeout); a refusal or an invalid reply is not.
 */
final class GeminiProvider implements ModelProvider
{
    /** Finish reasons that mean the model did not answer on purpose: a refusal, never retried as bad output. */
    private const STOPS = ['STOP' => 'end', 'MAX_TOKENS' => 'max_tokens', 'SAFETY' => 'refusal', 'PROHIBITED_CONTENT' => 'refusal', 'BLOCKLIST' => 'refusal', 'SPII' => 'refusal', 'RECITATION' => 'refusal'];

    /** The key lives in a closure, not a property, so var_export() cannot print it. */
    private readonly \Closure $apiKey;

    public function __construct(
        private readonly ClientInterface $http,
        #[\SensitiveParameter] string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
        private readonly int $maxRetries = 0,
        private readonly ?\Closure $sleeper = null,
        private readonly ?\Closure $now = null,
    ) {
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
            $this->maxRetries,
            $this->sleeper,
            $this->now,
        );

        return self::reply($model, $data);
    }

    /** @param array<mixed> $data */
    private static function reply(string $requested, array $data): ModelReply
    {
        $candidate = $data['candidates'][0] ?? null;
        $usage = $data['usageMetadata'] ?? null;
        // A prompt the service blocked has no candidate: it is a refusal, and it still carries usage when billed.
        $blocked = is_array($data['promptFeedback'] ?? null) && is_string($data['promptFeedback']['blockReason'] ?? null);
        if ($blocked && $candidate === null && is_array($usage)) {
            $in = $usage['promptTokenCount'] ?? null;
            $cached = $usage['cachedContentTokenCount'] ?? 0;
            if (!is_int($in) || !is_int($cached) || $in < 0 || $cached < 0 || $cached > $in) {
                throw new AnalyzerFailed('provider unavailable (invalid reply)'); // same rules as the answered path
            }

            return new ModelReply(null, 'refusal', is_string($data['modelVersion'] ?? null) ? $data['modelVersion'] : $requested);
        }
        $in = is_array($usage) ? ($usage['promptTokenCount'] ?? null) : null;
        $out = is_array($usage) ? ($usage['candidatesTokenCount'] ?? null) : null;
        $cached = is_array($usage) ? ($usage['cachedContentTokenCount'] ?? 0) : 0;
        $thoughts = is_array($usage) ? ($usage['thoughtsTokenCount'] ?? 0) : 0;
        if (!is_int($in) || !is_int($out) || $in < 0 || $out < 0 || !is_int($cached) || $cached < 0 || $cached > $in || !is_int($thoughts) || $thoughts < 0 || !is_array($candidate)) {
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

        return new ModelReply($text, is_string($finish) ? (self::STOPS[$finish] ?? null) : null, $model);
    }
}
