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
use Throwable;

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
        if (!$http instanceof \Psr\Http\Client\ClientInterface) {
            throw new \LogicException('the Anthropic SDK needs a PSR-18 client');
        }
        $this->client = static fn (): Client => new Client(
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            requestOptions: ['transporter' => $http, 'timeout' => (float) $timeoutSeconds, 'maxRetries' => $maxRetries],
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
        if (!isset($message->usage)) {
            throw new AnalyzerFailed('provider unavailable (invalid reply)'); // the SDK leaves the property unset when usage is absent
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
