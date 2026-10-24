<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use ArrayObject;
use GuzzleHttp\ClientInterface;
use Throwable;
use Watcher\Source\ResponseLimit;
use Watcher\Source\SourceUnavailable;

/**
 * The Anthropic Messages API over Guzzle, with no SDK. The URL is fixed (no env, no config), redirects are off, TLS is verified,
 * the reply is size-bounded, and a call is never retried here (the next scheduled run is the retry). A failure names the
 * exception class or HTTP status and nothing else: never the URL, the key or the body.
 */
final class AnthropicHttpClient implements MessagesClient
{
    private const URL = 'https://api.anthropic.com/v1/messages';
    private const VERSION = '2023-06-01';
    private const STOPS = ['end_turn', 'max_tokens', 'refusal', 'pause_turn'];

    /** The key lives in a closure, not a property, so var_export() (which ignores __debugInfo) cannot print it. */
    private readonly \Closure $apiKey;

    public function __construct(
        #[\SensitiveParameter] string $apiKey,
        private readonly ClientInterface $http,
        private readonly int $timeout = 60,
    ) {
        $this->apiKey = static fn (): string => $apiKey;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['apiKey' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('AnthropicHttpClient is not serialisable.');
    }

    public function create(string $model, int $maxTokens, string $system, string $user, array $schema, bool $thinking): ModelReply
    {
        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'system' => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
            'messages' => [['role' => 'user', 'content' => $user]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $schema]],
        ];
        if (!$thinking) {
            $body['thinking'] = ['type' => 'disabled']; // adaptive thinking tokens count against max_tokens and triage needs none
        }

        try {
            $response = $this->http->request('POST', self::URL, [
                'headers' => ['x-api-key' => ($this->apiKey)(), 'anthropic-version' => self::VERSION, 'Accept' => 'application/json'],
                'json' => $body,
                'http_errors' => false,
                'allow_redirects' => false,
                'verify' => true,
                'timeout' => $this->timeout,
                'connect_timeout' => 10,
            ] + ResponseLimit::options(new ArrayObject()));
            $status = $response->getStatusCode();
            $data = $status === 200 ? ResponseLimit::decode($response, 'anthropic') : [];
        } catch (SourceUnavailable) {
            throw new AnalyzerFailed('provider unavailable (invalid reply)'); // oversized or not JSON
        } catch (Throwable $e) {
            throw new AnalyzerFailed('provider unavailable (' . (new \ReflectionClass($e))->getShortName() . ')'); // not chained: the message carries URL and headers
        }

        if ($status === 401 || $status === 403) {
            throw new AnalyzerFailed('authentication failed');
        }
        if ($status !== 200) {
            throw new AnalyzerFailed("provider unavailable (HTTP $status)");
        }

        return self::reply($data);
    }

    /** @param array<mixed> $data */
    private static function reply(array $data): ModelReply
    {
        $usage = $data['usage'] ?? null;
        $in = is_array($usage) ? ($usage['input_tokens'] ?? null) : null;
        $out = is_array($usage) ? ($usage['output_tokens'] ?? null) : null;
        if (!is_int($in) || !is_int($out) || $in < 0 || $out < 0 || !is_string($data['model'] ?? null) || !is_array($data['content'] ?? null)) {
            throw new AnalyzerFailed('provider unavailable (invalid reply)');
        }

        $text = null;
        foreach ($data['content'] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text = $block['text'];
                break;
            }
        }
        $cache = [];
        foreach (['cache_read_input_tokens', 'cache_creation_input_tokens'] as $field) {
            $v = $usage[$field] ?? 0; // absent means none; present must be a count, or the spend would be undercounted
            if (!is_int($v) || $v < 0) {
                throw new AnalyzerFailed('provider unavailable (invalid reply)');
            }
            $cache[] = $v;
        }
        $stop = $data['stop_reason'] ?? null;

        return new ModelReply(
            $text,
            is_string($stop) && in_array($stop, self::STOPS, true) ? $stop : null,
            $data['model'],
            $in,
            $out,
            $cache[0],
            $cache[1],
        );
    }
}
