<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Messages\Message;
use ArrayObject;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Watcher\Source\ResponseLimit;

/**
 * Anthropic through the official SDK. The base URL, timeout and retries are explicit, so the SDK's environment fallbacks
 * (ANTHROPIC_BASE_URL, retry defaults) never apply. Telemetry is off and the SDK's custom-header environment is dropped, so
 * nothing leaves the host that the privacy design did not approve. Failures name only the exception class or the HTTP status,
 * never the URL, the key or the body. Retries are off by default: the analyzer's second attempt is the retry.
 */
final class AnthropicSdkProvider implements ModelProvider
{
    private const STOPS = ['end_turn' => 'end', 'max_tokens' => 'max_tokens', 'refusal' => 'refusal', 'pause_turn' => 'pause'];

    /** Builds the SDK client per call; the closure keeps the key out of var_export() (the SDK client stores it). */
    private readonly \Closure $client;

    public function __construct(ClientInterface $http, #[\SensitiveParameter] string $apiKey, string $baseUrl, int $timeoutSeconds, int $maxRetries)
    {
        $stack = $http->getConfig('handler') instanceof HandlerStack ? clone $http->getConfig('handler') : HandlerStack::create();
        // Redirects off, TLS verified, timeouts and the reply size bounded on every call, whatever the caller's client was built with.
        $bounds = ['allow_redirects' => false, 'verify' => true, 'timeout' => (float) $timeoutSeconds, 'connect_timeout' => 10];
        $stack->push(static fn (callable $next): \Closure => static function (RequestInterface $r, array $o) use ($next, $bounds): PromiseInterface {
            // The declared length aborts the download; the size check after it catches a chunked body.
            $limit = ResponseLimit::options(new ArrayObject());

            return $next(self::onlyOwnHeaders($r), array_replace($o, $bounds, ['on_headers' => $limit['on_headers']]))->then(
                static function (ResponseInterface $response): ResponseInterface {
                    if (($response->getBody()->getSize() ?? 0) > ResponseLimit::MAX_BYTES) {
                        throw new AnalyzerFailed('provider unavailable (invalid reply)'); // oversized: never handed to the SDK's parser
                    }

                    return $response;
                },
            );
        }, 'watcher-guard');
        $transporter = new \GuzzleHttp\Client(['handler' => $stack]);

        // openTelemetry false: the SDK would otherwise read ANTHROPIC_OPEN_TELEMETRY* and copy payloads into the host's traces.
        $this->client = static fn (): Client => new Client(
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            openTelemetry: false,
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

            return self::reply($message);
        } catch (AnalyzerFailed $e) {
            throw $e;
        } catch (AuthenticationException) {
            throw new AnalyzerFailed('authentication failed');
        } catch (APIStatusException $e) {
            throw new AnalyzerFailed('provider unavailable (HTTP ' . $e->status . ')');
        } catch (APIConnectionException | APITimeoutException $e) {
            throw new AnalyzerFailed('provider unavailable (' . (new \ReflectionClass($e))->getShortName() . ')');
        } catch (Throwable) {
            // Anything else is a reply that could not be read: not JSON, or a field the SDK leaves unset.
            throw new AnalyzerFailed('provider unavailable (invalid reply)');
        }
    }

    /**
     * The SDK merges ANTHROPIC_CUSTOM_HEADERS from the environment into every request. Only the headers this adapter and the SDK
     * need are kept; anything else is dropped before it leaves the host.
     */
    private static function onlyOwnHeaders(RequestInterface $request): RequestInterface
    {
        foreach (array_keys($request->getHeaders()) as $name) {
            $lower = strtolower((string) $name);
            $owned = in_array($lower, ['accept', 'content-type', 'content-length', 'user-agent', 'host', 'anthropic-version', 'authorization', 'x-api-key'], true)
                || str_starts_with($lower, 'x-stainless-');
            if (!$owned) {
                $request = $request->withoutHeader((string) $name);
            }
        }

        return $request;
    }

    private static function reply(Message $message): ModelReply
    {
        // The SDK leaves a typed property unset when the reply omits it, so isset() is the presence check.
        if (!isset($message->usage, $message->model) || !isset($message->usage->inputTokens, $message->usage->outputTokens)) {
            throw new AnalyzerFailed('provider unavailable (invalid reply)');
        }
        $counts = [$message->usage->inputTokens, $message->usage->outputTokens, $message->usage->cacheReadInputTokens ?? 0, $message->usage->cacheCreationInputTokens ?? 0];
        if (array_filter($counts, static fn (int $n): bool => $n < 0) !== []) {
            throw new AnalyzerFailed('provider unavailable (invalid reply)'); // a negative count would undercount the spend
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
