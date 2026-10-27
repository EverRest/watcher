<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use JsonException;
use Watcher\Context\DependencyContext;
use Watcher\Privacy\RedactedGroup;

/** One structured-output call through a ModelProvider, validated in code, one retry on bad output. */
final class AnthropicAnalyzer implements Analyzer
{
    private readonly OutputValidator $validator;

    public function __construct(private readonly ProviderRegistry $providers, private readonly bool $advice = true)
    {
        $this->validator = new OutputValidator($advice);
    }

    public function triage(TriageRequest $req): TriageResult
    {
        $allowed = array_map(static fn (RedactedGroup $g): string => $g->fingerprint, $req->groups);

        [$out, $usage] = $this->attempt(
            'triage',
            $req->step,
            $req->groups,
            $req->serviceContext,
            $req->model,
            self::triageOutputCap($req->maxOutputTokens, count($req->groups)),
            fn (array $data): array => $this->validator->triage($data, $allowed),
            [],
            $req->extras,
        );

        return new TriageResult($out['verdicts'], $usage);
    }

    /** The verdict list grows with the groups: never below the configured cap, ~30 tokens per verdict (id plus verdict) plus slack. */
    public static function triageOutputCap(int $configured, int $groups): int
    {
        return max($configured, 30 * $groups + 500);
    }

    /**
     * A finding is about 280 output tokens at its text limits (title 80 + evidence 240 + cause 240 + action 200 chars, JSON keys)
     * and about 450 with how_to_check (200) and three related_checks (80 each). With advice on, the cap never falls below that
     * worst case for one finding per group, so a full top_m of long findings is not cut off and retried; off, it is untouched.
     */
    public static function analysisOutputCap(int $configured, int $groups, bool $advice): int
    {
        return $advice ? max($configured, 450 * $groups + 300) : $configured;
    }

    /** The cap of the second attempt: a reply cut off by max_tokens is retried with the cap doubled, at most 8192. */
    public static function retryOutputCap(int $cap): int
    {
        return max($cap, min($cap * 2, 8192));
    }

    public function analyze(AnalysisRequest $req): AnalysisResult
    {
        $allowed = array_map(static fn (RedactedGroup $g): string => $g->fingerprint, $req->groups);

        [$findings, $usage] = $this->attempt(
            'analysis',
            $req->step,
            $req->groups,
            $req->serviceContext,
            $req->model,
            $req->maxOutputTokens,
            fn (array $data): array => $this->validator->analysis($data, $allowed, array_map(static fn (DependencyContext $d): string => $d->name, $req->dependencyContext)),
            $req->dependencyContext,
            $req->extras,
            $req->endpoints,
        );

        return new AnalysisResult($findings, $usage);
    }

    /**
     * One call, validated in code, one retry on bad output (usage of both attempts is summed and
     * carried by the AnalyzerFailed when both are bad, because both were billed).
     * A reply cut off by max_tokens is retried with the cap doubled (at most 8192), not with the same cap.
     *
     * @template T
     *
     * @param list<RedactedGroup>          $groups
     * @param callable(array<mixed>): T    $validate
     * @param list<DependencyContext>      $dependencyContext
     * @param array<string, string>        $endpoints
     *
     * @return array{0: T, 1: Usage}
     */
    private function attempt(string $kind, string $step, array $groups, string $context, string $model, int $maxTokens, callable $validate, array $dependencyContext = [], ?PayloadExtras $extras = null, array $endpoints = []): array
    {
        $usage = null;

        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            try {
                $message = $this->call($kind, $step, $groups, $context, $model, $maxTokens, $dependencyContext, $extras, $endpoints);
            } catch (AnalyzerFailed $e) {
                throw $usage === null ? $e : new AnalyzerFailed($e->getMessage(), $usage); // attempt 1 was billed even though attempt 2 failed
            }
            $u = $this->usage($message, $model);
            $usage = $usage === null ? $u : $usage->plus($u);
            if ($message->stopReason === 'refusal') {
                // Billed, not retried (a second identical request is refused again and billed again), and loud: the window is not re-run.
                throw new AnalyzerFailed($kind . ': the model refused the request (not retried)', $usage, true);
            }

            try {
                return [$validate($this->decode($message)), $usage];
            } catch (InvalidModelOutput|JsonException) {
                // retried once, then fails below
            }
            if ($message->stopReason === 'max_tokens') {
                $maxTokens = self::retryOutputCap($maxTokens);
            }
        }

        throw new AnalyzerFailed('invalid model output', $usage);
    }

    /**
     * @param list<RedactedGroup>     $groups
     * @param list<DependencyContext> $dependencyContext
     * @param array<string, string>   $endpoints
     */
    private function call(string $kind, string $step, array $groups, string $context, string $model, int $maxTokens, array $dependencyContext = [], ?PayloadExtras $extras = null, array $endpoints = []): ModelReply
    {
        return $this->providers->for($step)->create(
            $model,
            $maxTokens,
            Budget::systemPrompt($kind === 'triage' ? 'triage' : 'analyze', $context, $this->advice, $extras !== null, $endpoints !== []),
            CompactPayload::userMessage(CompactPayload::encode($groups, $dependencyContext, $extras, $endpoints), bin2hex(random_bytes(16))),
            ProviderSchema::for($kind, $this->advice),
            $kind !== 'triage',
        );
    }

    /** @return array<mixed> */
    private function decode(ModelReply $reply): array
    {
        $data = json_decode($reply->text ?? throw new JsonException('no text block'), true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : throw new JsonException('not an object');
    }

    /** Priced by the id that was requested (the key of `analysis.prices`); the echoed id may be a dated form of an alias. */
    private function usage(ModelReply $reply, string $requestedModel): Usage
    {
        return new Usage($requestedModel, $reply->inputTokens, $reply->outputTokens, $reply->cacheReadTokens, $reply->cacheWriteTokens, returnedModel: $reply->model);
    }
}
