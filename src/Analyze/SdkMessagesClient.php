<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use Anthropic\Client as SdkClient;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Messages\TextBlock;
use Psr\Http\Client\ClientInterface;

/** Rollback path (`analysis.client = sdk`): the official SDK, for one release. */
final class SdkMessagesClient implements MessagesClient
{
    /** Test seam: null detects the package, a bool overrides the detection. */
    public static ?bool $installed = null;

    private readonly SdkClient $client;

    /** anthropic-ai/sdk is optional (`suggest`); `analysis.client = sdk` needs it. */
    public static function available(): bool
    {
        return self::$installed ?? class_exists(SdkClient::class);
    }

    /** @param array<string, mixed> $requestOptions SDK request options; the SDK defaults (2 retries) apply when empty */
    public function __construct(#[\SensitiveParameter] string $apiKey, ClientInterface $http, ?string $baseUrl = null, array $requestOptions = [])
    {
        $this->client = new SdkClient(
            apiKey: $apiKey,
            baseUrl: $baseUrl ?? 'https://api.anthropic.com', // never the SDK's ANTHROPIC_BASE_URL env fallback: key and data stay on Anthropic
            requestOptions: ['transporter' => $http] + $requestOptions,
        );
    }

    public function create(string $model, int $maxTokens, string $system, string $user, array $schema, bool $thinking): ModelReply
    {
        try {
            $message = $this->client->messages->create(
                maxTokens: $maxTokens,
                messages: [['role' => 'user', 'content' => $user]],
                model: $model,
                // Adaptive thinking tokens count against max_tokens and triage needs none.
                thinking: $thinking ? null : ['type' => 'disabled'],
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $schema]],
                system: [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
            );
        } catch (AuthenticationException|PermissionDeniedException) {
            // The SDK exception is not chained as `previous`: it carries the request, whose headers hold the key.
            throw new AnalyzerFailed('authentication failed');
        } catch (AnthropicException) {
            throw new AnalyzerFailed('provider unavailable');
        }

        $text = null;
        foreach ($message->content as $block) {
            if ($block instanceof TextBlock) {
                $text = $block->text;
                break;
            }
        }
        $u = $message->usage;

        return new ModelReply($text, $message->stopReason, $message->model, $u->inputTokens, $u->outputTokens, $u->cacheReadInputTokens ?? 0, $u->cacheCreationInputTokens ?? 0);
    }
}
