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
        throw new \LogicException('triage() arrives with the cost-routing work (US3)');
    }

    public function analyze(AnalysisRequest $req): AnalysisResult
    {
        $allowed = array_map(static fn (RedactedGroup $g): string => $g->fingerprint, $req->groups);
        $usage = null;

        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            $message = $this->call($req);
            $u = $this->usage($message);
            $usage = $usage === null ? $u : $usage->plus($u);

            try {
                $findings = $this->validator->analysis($this->decode($message), $allowed);

                return new AnalysisResult($findings, $usage);
            } catch (InvalidModelOutput|JsonException) {
                // retried once, then fails below
            }
        }

        throw new AnalyzerFailed('invalid model output');
    }

    private function call(AnalysisRequest $req): Message
    {
        $prompt = (string) file_get_contents(__DIR__ . '/prompts/analyze.md');

        try {
            return $this->client->messages->create(
                maxTokens: $req->maxOutputTokens,
                messages: [['role' => 'user', 'content' => json_encode($req->groups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]],
                model: $req->model,
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => ProviderSchema::for('analysis')]],
                system: [[
                    'type' => 'text',
                    'text' => $prompt . "\n## Service context\n\n" . $req->serviceContext,
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
