<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Messages\Message;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Watcher\Source\ResponseLimit;

/**
 * Anthropic through the official SDK. The base URL, timeout and retries are explicit, so the SDK's environment fallbacks
 * (ANTHROPIC_BASE_URL, retry defaults) never apply. Failures name only the exception class or the HTTP status, never the
 * URL, the key or the body. Retries are off by default: the analyzer's second attempt is the retry.
 */
final class AnthropicSdkProvider implements ModelProvider
{
    private const STOPS = ['end_turn' => 'end', 'max_tokens' => 'max_tokens', 'refusal' => 'refusal', 'pause_turn' => 'pause'];

    /** Builds the SDK client per call; the closure keeps the key out of var_export() (the SDK client stores it). */
    private readonly \Closure $client;

    public function __construct(ClientInterface $http, #[\SensitiveParameter] string $apiKey, string $baseUrl, int $timeoutSeconds, int $maxRetries)
    {
        $stack = $http->getConfig('handler') instanceof HandlerStack ? clone $http->getConfig('handler') : HandlerStack::create();
        // Redirects off, TLS verified and the reply size-capped on every call, whatever the caller's client was built with.
        $bounds = ['allow_redirects' => false, 'verify' => true, 'timeout' => (float) $timeoutSeconds, 'connect_timeout' => 10];
        $stack->push(static fn (callable $next): \Closure => static fn (RequestInterface $r, array $o): PromiseInterface => $next($r, array_replace($o, $bounds))->then(
            static function (ResponseInterface $response): ResponseInterface {
                if (($response->getBody()->getSize() ?? 0) > ResponseLimit::MAX_BYTES) {
                    throw new AnalyzerFailed('provider unavailable (invalid reply)'); // oversized: never handed to the SDK's parser
                }

                return $response;
            },
        ), 'watcher-guard');
        $transporter = new \GuzzleHttp\Client(['handler' => $stack]);

        $this->client = static fn (): Client => new Client(
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            requestOptions: ['transporter' => $transporter, 'timeout' => (float) $timeoutSeconds, 'maxRetries' => $maxRetries],
        );
    }

    public function __debugInfo(): array
    {
        return ['client' => '[sdk client]'];
    }

    public function create(string $model, int $maxTokens, string $system, string $user, array $schema, bool $thinking): ModelReply
    {
        try {
            $message = ($this->client)()->messages->create(
                maxTokens: $maxTokens,
                messages: [['role' => 'user', 'content' => $user]],
                model: $model,
                system: [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $schema]],
                thinking: $thinking ? null : ['type' => 'disabled'], // adaptive thinking tokens count against max_tokens
            );
        } catch (AuthenticationException) {
            throw new AnalyzerFailed('authentication failed');
        } catch (APIStatusException $e) {
            throw new AnalyzerFailed('provider unavailable (HTTP ' . $e->status . ')');
        } catch (APIConnectionException | APITimeoutException $e) {
            throw new AnalyzerFailed('provider unavailable (' . (new \ReflectionClass($e))->getShortName() . ')');
        } catch (Throwable) {
            // Any other failure from the SDK is a reply it could not read (not JSON, or a usage count missing).
            throw new AnalyzerFailed('provider unavailable (invalid reply)');
        }

        return self::reply($message);
    }

    private static function reply(Message $message): ModelReply
    {
        // The SDK leaves the usage property unset when it is absent; every count must be a non-negative integer, or the spend
        // would be undercounted (the same rule the built-in client applied).
        $counts = isset($message->usage) ? [$message->usage->inputTokens, $message->usage->outputTokens, $message->usage->cacheReadInputTokens ?? 0, $message->usage->cacheCreationInputTokens ?? 0] : null;
        if ($counts === null || array_filter($counts, static fn (int $n): bool => $n < 0) !== []) {
            throw new AnalyzerFailed('provider unavailable (invalid reply)');
        }
        $text = null;
        foreach ($message->content as $block) {
            if (isset($block->type) && $block->type === 'text' && is_string($block->text ?? null)) {
                $text = $block->text;
                break;
            }
        }
        $stop = $message->stopReason ?? null;

        return new ModelReply(
            $text,
            is_string($stop) ? (self::STOPS[$stop] ?? null) : null,
            $message->model,
            $message->usage->inputTokens,
            $message->usage->outputTokens,
            $message->usage->cacheReadInputTokens ?? 0,
            $message->usage->cacheCreationInputTokens ?? 0,
        );
    }
}
