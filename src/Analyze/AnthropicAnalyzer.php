<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use Anthropic\Client as SdkClient;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Messages\Message;
use Anthropic\Messages\TextBlock;
use JsonException;
use Psr\Http\Client\ClientInterface;
use Watcher\Privacy\RedactedGroup;

/** Thin wrapper over the official SDK: one structured-output call, validated in code, one retry on bad output. */
final class AnthropicAnalyzer implements Analyzer
{
    private readonly SdkClient $client;

    private readonly OutputValidator $validator;

    /** @param array<string, mixed> $requestOptions SDK request options; the SDK defaults (2 retries) apply when empty */
    public function __construct(string $apiKey, ClientInterface $http, ?string $baseUrl = null, array $requestOptions = [])
    {
        $this->client = new SdkClient(
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            requestOptions: ['transporter' => $http] + $requestOptions,
        );
        $this->validator = new OutputValidator();
    }

    public function triage(TriageRequest $req): TriageResult
    {
        $allowed = array_map(static fn (RedactedGroup $g): string => $g->fingerprint, $req->groups);

        [$out, $usage] = $this->attempt(
            'triage',
            $req->groups,
            $req->serviceContext,
            $req->model,
            $req->maxOutputTokens,
            fn (array $data): array => $this->validator->triage($data, $allowed),
        );

        return new TriageResult($out['verdicts'], $out['why'], $usage);
    }

    public function analyze(AnalysisRequest $req): AnalysisResult
    {
        $allowed = array_map(static fn (RedactedGroup $g): string => $g->fingerprint, $req->groups);

        [$findings, $usage] = $this->attempt(
            'analysis',
            $req->groups,
            $req->serviceContext,
            $req->model,
            $req->maxOutputTokens,
            fn (array $data): array => $this->validator->analysis($data, $allowed),
        );

        return new AnalysisResult($findings, $usage);
    }

    /**
     * One call, validated in code, one retry on bad output (usage of both attempts is summed).
     *
     * @param list<RedactedGroup>          $groups
     * @param callable(array<mixed>): array<mixed> $validate
     *
     * @return array{0: array<mixed>, 1: Usage}
     */
    private function attempt(string $kind, array $groups, string $context, string $model, int $maxTokens, callable $validate): array
    {
        $usage = null;

        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            $message = $this->call($kind, $groups, $context, $model, $maxTokens);
            $u = $this->usage($message);
            $usage = $usage === null ? $u : $usage->plus($u);

            try {
                return [$validate($this->decode($message)), $usage];
            } catch (InvalidModelOutput|JsonException) {
                // retried once, then fails below
            }
        }

        throw new AnalyzerFailed('invalid model output');
    }

    /** @param list<RedactedGroup> $groups */
    private function call(string $kind, array $groups, string $context, string $model, int $maxTokens): Message
    {
        $prompt = (string) file_get_contents(__DIR__ . '/prompts/' . ($kind === 'triage' ? 'triage' : 'analyze') . '.md');

        try {
            return $this->client->messages->create(
                maxTokens: $maxTokens,
                messages: [['role' => 'user', 'content' => CompactPayload::encode($groups)]],
                model: $model,
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => ProviderSchema::for($kind)]],
                system: [[
                    'type' => 'text',
                    'text' => $prompt . "\n## Service context\n\n" . $context,
                    'cacheControl' => ['type' => 'ephemeral'],
                ]],
            );
        } catch (AuthenticationException|PermissionDeniedException) {
            // The SDK exception is not chained as `previous`: it carries the request, whose headers hold the key.
            throw new AnalyzerFailed('authentication failed');
        } catch (AnthropicException) {
            throw new AnalyzerFailed('provider unavailable');
        }
    }

    /** @return array<mixed> */
    private function decode(Message $message): array
    {
        foreach ($message->content as $block) {
            if ($block instanceof TextBlock) {
                $data = json_decode($block->text, true, 512, JSON_THROW_ON_ERROR);

                return is_array($data) ? $data : throw new JsonException('not an object');
            }
        }

        throw new JsonException('no text block');
    }

    private function usage(Message $message): Usage
    {
        $u = $message->usage;

        return new Usage(
            $message->model,
            $u->inputTokens,
            $u->outputTokens,
            $u->cacheReadInputTokens ?? 0,
            $u->cacheCreationInputTokens ?? 0,
        );
    }
}
