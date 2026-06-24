<?php

namespace Laravel\Ai\Gateway;

use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Contracts\TransformsApprovalArguments;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolApprovalRequest;
use Laravel\Ai\Responses\Data\ToolApprovalResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest as ToolApprovalRequestEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Laravel\Ai\Tools\RequiresApprovalResolver;

class TextGenerationLoop
{
    use InvokesTools;

    public function __construct(protected StepTextGateway $gateway)
    {
        $this->initializeToolCallbacks();
    }

    /**
     * @param  Tool[]  $tools
     * @param  array<string, mixed>|null  $schema
     */
    public function generate(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
    ): TextResponse {
        $steps = new Collection;
        $allMessages = $messages;
        $maxSteps = $this->resolveMaxSteps($options, $tools);
        $continuationToken = null;
        $lastResult = null;
        $approvalRequests = [];

        if ($options?->isResuming) {
            [$pending, $resolved, $remaining] = $this->resolvePendingApprovals($allMessages, $tools, $options->approvalResponses ?? []);

            if (filled($resolved)) {
                $allMessages[] = new ToolResultMessage(collect($resolved));
            }

            if (filled($resolved) || filled($remaining)) {
                $steps->push(new Step(
                    '', $pending, $resolved, FinishReason::ToolCalls, new Usage, new Meta($provider->name(), $model),
                ));
            }

            // Still awaiting one or more approvals — surface the remaining requests and stop here...
            if (filled($remaining)) {
                $approvalRequests = $this->buildApprovalRequests($remaining, $tools);

                return $this->buildFinalResponse($steps, $allMessages, count($messages), null, $approvalRequests);
            }
        }

        for ($step = 0; $step < $maxSteps; $step++) {
            $stepContext = new StepContext(
                stepNumber: $step,
                isFinalStep: $step + 1 >= $maxSteps,
                continuationToken: $continuationToken,
            );

            $lastResult = $this->gateway->generateTextStep(
                $provider,
                $model,
                $instructions,
                $allMessages,
                $tools,
                $schema,
                $options,
                $timeout,
                $stepContext,
            );

            if ($lastResult->finishReason === FinishReason::Continue) {
                $steps->push($this->buildStep($lastResult));

                $allMessages[] = new AssistantMessage(
                    $lastResult->text,
                    collect($lastResult->toolCalls),
                    $lastResult->providerContentBlocks,
                );

                $continuationToken = $lastResult->continuationToken;

                continue;
            }

            $pendingApproval = $this->pendingApprovalCalls($lastResult->finishReason, $lastResult->toolCalls, $tools);

            if (filled($pendingApproval)) {
                $approvalRequests = $this->buildApprovalRequests($pendingApproval, $tools);

                $steps->push($this->buildStep($lastResult));

                $allMessages[] = new AssistantMessage(
                    $lastResult->text,
                    collect($lastResult->toolCalls),
                    $lastResult->providerContentBlocks,
                );

                break;
            }

            $toolResults = $this->continuationToolResults(
                $lastResult->finishReason,
                $lastResult->toolCalls,
                $stepContext->isFinalStep,
                $tools
            );

            $shouldContinue = filled($toolResults);

            $steps->push($this->buildStep($lastResult, $toolResults));

            $allMessages[] = new AssistantMessage(
                $lastResult->text,
                collect($lastResult->toolCalls),
                $lastResult->providerContentBlocks,
            );

            if (! $shouldContinue) {
                break;
            }

            $allMessages[] = new ToolResultMessage(collect($toolResults));

            $continuationToken = $lastResult->continuationToken;
        }

        return $this->buildFinalResponse($steps, $allMessages, count($messages), $lastResult, $approvalRequests);
    }

    /**
     * @param  Tool[]  $tools
     * @param  array<string, mixed>|null  $schema
     */
    public function stream(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
    ): Generator {
        $allMessages = $messages;
        $maxSteps = $this->resolveMaxSteps($options, $tools);
        $continuationToken = null;
        $accumulatedUsage = new Usage;
        $finalReason = null;
        $sawError = false;

        if ($options?->isResuming) {
            [$pending, $resolved, $remaining] = $this->resolvePendingApprovals($allMessages, $tools, $options->approvalResponses ?? []);

            if (filled($resolved)) {
                $allMessages[] = new ToolResultMessage(collect($resolved));

                foreach ($resolved as $toolResult) {
                    yield (new ToolResultEvent(
                        strtolower((string) Str::uuid7()),
                        $toolResult,
                        true,
                        null,
                        time(),
                    ))->withInvocationId($invocationId);
                }
            }

            // Still awaiting one or more approvals — surface the remaining requests and end the stream...
            if (filled($remaining)) {
                foreach ($this->buildApprovalRequests($remaining, $tools) as $approvalRequest) {
                    yield (new ToolApprovalRequestEvent(
                        strtolower((string) Str::uuid7()),
                        $approvalRequest,
                        time(),
                    ))->withInvocationId($invocationId);
                }

                yield (new StreamEnd(
                    strtolower((string) Str::uuid7()),
                    FinishReason::ToolCalls->value,
                    $accumulatedUsage,
                    time(),
                ))->withInvocationId($invocationId);

                return;
            }
        }

        for ($step = 0; $step < $maxSteps; $step++) {
            $stepContext = new StepContext(
                stepNumber: $step,
                isFinalStep: $step + 1 >= $maxSteps,
                continuationToken: $continuationToken,
            );

            $stream = $this->gateway->generateStreamStep(
                $invocationId,
                $provider,
                $model,
                $instructions,
                $allMessages,
                $tools,
                $schema,
                $options,
                $timeout,
                $stepContext,
            );

            foreach ($stream as $event) {
                yield $event;

                if ($event instanceof Error) {
                    $sawError = true;
                }
            }

            $result = $stream->getReturn();

            if ($result !== null) {
                $accumulatedUsage = $accumulatedUsage->add($result->usage);
                $finalReason = $result->finishReason;
            }

            if ($result?->finishReason === FinishReason::Continue) {
                $allMessages[] = new AssistantMessage(
                    $result->text,
                    collect($result->toolCalls),
                    $result->providerContentBlocks,
                );

                $continuationToken = $result->continuationToken;

                continue;
            }

            $pendingApproval = $result !== null
                ? $this->pendingApprovalCalls($result->finishReason, $result->toolCalls, $tools)
                : [];

            if (filled($pendingApproval)) {
                foreach ($this->buildApprovalRequests($pendingApproval, $tools) as $approvalRequest) {
                    yield (new ToolApprovalRequestEvent(
                        strtolower((string) Str::uuid7()),
                        $approvalRequest,
                        time(),
                    ))->withInvocationId($invocationId);
                }

                $allMessages[] = new AssistantMessage(
                    $result?->text ?? '',
                    collect($result?->toolCalls ?? []),
                    $result?->providerContentBlocks ?? [],
                );

                break;
            }

            $toolResults = $result !== null
                ? $this->continuationToolResults($result->finishReason, $result->toolCalls, $stepContext->isFinalStep, $tools)
                : [];

            $shouldContinue = filled($toolResults);

            if ($shouldContinue) {
                foreach ($toolResults as $toolResult) {
                    yield (new ToolResultEvent(
                        strtolower((string) Str::uuid7()),
                        $toolResult,
                        true,
                        null,
                        time(),
                    ))->withInvocationId($invocationId);
                }
            }

            $allMessages[] = new AssistantMessage(
                $result?->text ?? '',
                collect($result?->toolCalls ?? []),
                $result?->providerContentBlocks ?? [],
            );

            if (! $shouldContinue) {
                break;
            }

            $allMessages[] = new ToolResultMessage(collect($toolResults));

            $continuationToken = $result?->continuationToken;
        }

        $reason = $finalReason ?? ($sawError ? null : FinishReason::Error);

        if ($reason !== null) {
            yield (new StreamEnd(
                strtolower((string) Str::uuid7()),
                $reason->value,
                $accumulatedUsage,
                time(),
            ))->withInvocationId($invocationId);
        }
    }

    /**
     * Resolve the step budget: explicit `maxSteps`, else 1.5x tools, else 5.
     *
     * @param  Tool[]  $tools
     */
    protected function resolveMaxSteps(?TextGenerationOptions $options, array $tools): int
    {
        if ($options?->maxSteps !== null) {
            return max(1, $options->maxSteps);
        }

        return count($tools) > 0 ? (int) round(count($tools) * 1.5) : 5;
    }

    /**
     * The tool calls from a step that require human approval before execution.
     *
     * @param  ToolCall[]  $toolCalls
     * @param  Tool[]  $tools
     * @return ToolCall[]
     */
    protected function pendingApprovalCalls(FinishReason $reason, array $toolCalls, array $tools): array
    {
        if ($reason !== FinishReason::ToolCalls || empty($toolCalls)) {
            return [];
        }

        return array_values(array_filter($toolCalls, function (ToolCall $toolCall) use ($tools) {
            $tool = $this->findTool($toolCall->name, $tools);

            return $tool !== null && RequiresApprovalResolver::requiresApproval($tool);
        }));
    }

    /**
     * Build the approval requests for the given pending tool calls (arguments transformed for display).
     *
     * @param  ToolCall[]  $pendingCalls
     * @param  Tool[]  $tools
     * @return ToolApprovalRequest[]
     */
    protected function buildApprovalRequests(array $pendingCalls, array $tools): array
    {
        return array_map(function (ToolCall $toolCall) use ($tools) {
            $tool = $this->findTool($toolCall->name, $tools);

            $arguments = $tool instanceof TransformsApprovalArguments
                ? $tool->transformApprovalArguments($toolCall->id, $toolCall->arguments)
                : $toolCall->arguments;

            return new ToolApprovalRequest(
                approvalId: strtolower((string) Str::uuid7()),
                toolCallId: $toolCall->id,
                toolName: $toolCall->name,
                arguments: $arguments,
            );
        }, $pendingCalls);
    }

    /**
     * Resolve the pending approvals for a resume, executing approved/auto tools and denying the rest.
     *
     * Tool calls that still have no approval decision are returned as "remaining" so the caller can
     * keep awaiting them — approvals may be supplied across several resumes, one decision at a time.
     *
     * @param  Tool[]  $tools
     * @param  array<int, ToolApprovalResponse|array<string, mixed>>  $approvalResponses
     * @return array{0: ToolCall[], 1: ToolResult[], 2: ToolCall[]}
     */
    protected function resolvePendingApprovals(array $messages, array $tools, array $approvalResponses): array
    {
        $lastAssistant = $this->lastAssistantMessage($messages);

        if ($lastAssistant === null) {
            return [[], [], []];
        }

        $pending = $this->pendingToolCalls($messages, $lastAssistant);

        if (empty($pending)) {
            return [[], [], []];
        }

        $responses = $this->indexApprovalResponses($approvalResponses);

        $resolved = [];
        $remaining = [];

        foreach ($pending as $toolCall) {
            $result = $this->resolveApprovalCall($toolCall, $tools, $responses);

            if ($result === null) {
                $remaining[] = $toolCall;
            } else {
                $resolved[] = $result;
            }
        }

        return [$pending, $resolved, $remaining];
    }

    /**
     * Resolve a single pending tool call into a tool result, or null when it is still awaiting approval.
     *
     * @param  Tool[]  $tools
     * @param  array<string, ToolApprovalResponse>  $responses
     */
    protected function resolveApprovalCall(ToolCall $toolCall, array $tools, array $responses): ?ToolResult
    {
        $tool = $this->findTool($toolCall->name, $tools);

        if ($tool !== null && RequiresApprovalResolver::requiresApproval($tool)) {
            $decision = $responses[$toolCall->id] ?? null;

            if ($decision === null) {
                return null;
            }

            if (! $decision->approved) {
                return new ToolResult(
                    $toolCall->id,
                    $toolCall->name,
                    $toolCall->arguments,
                    $this->denialResult($decision),
                    $toolCall->resultId,
                );
            }
        }

        if ($tool === null) {
            throw new NoSuchToolException($toolCall->name);
        }

        return new ToolResult(
            $toolCall->id,
            $toolCall->name,
            $toolCall->arguments,
            $this->executeTool($tool, $toolCall->arguments),
            $toolCall->resultId,
        );
    }

    /**
     * The tool result string returned to the model when a tool call is denied.
     */
    protected function denialResult(ToolApprovalResponse $decision): string
    {
        return 'Tool execution was denied by the user.'.
            ($decision->reason ? ' Reason: '.$decision->reason : '');
    }

    /**
     * Index the given approval responses by their tool call id.
     *
     * @param  array<int, ToolApprovalResponse|array<string, mixed>>  $approvalResponses
     * @return array<string, ToolApprovalResponse>
     */
    protected function indexApprovalResponses(array $approvalResponses): array
    {
        $indexed = [];

        foreach ($approvalResponses as $response) {
            $response = $response instanceof ToolApprovalResponse
                ? $response
                : ToolApprovalResponse::fromArray((array) $response);

            $indexed[$response->toolCallId] = $response;
        }

        return $indexed;
    }

    /**
     * The tool calls from the last assistant message that have no corresponding tool result yet.
     *
     * @return ToolCall[]
     */
    protected function pendingToolCalls(array $messages, AssistantMessage $lastAssistant): array
    {
        $resultIds = [];

        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($message->toolResults as $toolResult) {
                    $resultIds[$toolResult->id] = true;
                }
            }
        }

        return $lastAssistant->toolCalls
            ->reject(fn (ToolCall $toolCall) => isset($resultIds[$toolCall->id]))
            ->values()
            ->all();
    }

    /**
     * Find the last assistant message in the given message list.
     */
    protected function lastAssistantMessage(array $messages): ?AssistantMessage
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($messages[$i] instanceof AssistantMessage) {
                return $messages[$i];
            }
        }

        return null;
    }

    /**
     * Tool results to continue the loop with, or [] when this step should be the last.
     *
     * @param  ToolCall[]  $toolCalls
     * @param  Tool[]  $tools
     * @return ToolResult[]
     */
    protected function continuationToolResults(FinishReason $reason, array $toolCalls, bool $isFinalStep, array $tools): array
    {
        return $reason === FinishReason::ToolCalls && ! $isFinalStep && filled($toolCalls)
            ? $this->executeToolCalls($toolCalls, $tools)
            : [];
    }

    /**
     * @param  ToolCall[]  $toolCalls
     * @param  Tool[]  $tools
     * @return ToolResult[]
     */
    protected function executeToolCalls(array $toolCalls, array $tools): array
    {
        return array_map(function (ToolCall $toolCall) use ($tools) {
            $tool = $this->findTool($toolCall->name, $tools);

            if ($tool === null) {
                throw new NoSuchToolException($toolCall->name);
            }

            return new ToolResult(
                $toolCall->id,
                $toolCall->name,
                $toolCall->arguments,
                $this->executeTool($tool, $toolCall->arguments),
                $toolCall->resultId,
            );
        }, $toolCalls);
    }

    /**
     * @param  ToolResult[]  $toolResults
     */
    protected function buildStep(StepResponse $result, array $toolResults = []): Step
    {
        return new Step(
            $result->text,
            $result->toolCalls,
            $toolResults,
            $result->finishReason,
            $result->usage,
            $result->meta,
        );
    }

    /**
     * Build the final text response from all generated steps.
     *
     * @param  ToolApprovalRequest[]  $approvalRequests
     */
    protected function buildFinalResponse(
        Collection $steps,
        array $allMessages,
        int $originalMessageCount,
        ?StepResponse $lastResult,
        array $approvalRequests = [],
    ): TextResponse {
        $finalStep = $steps->last();

        $totalUsage = $steps->reduce(
            fn (Usage $carry, Step $step) => $carry->add($step->usage),
            new Usage,
        );

        $newMessages = collect(array_slice($allMessages, $originalMessageCount))->values();

        $toolCalls = $steps->flatMap(fn (Step $step) => $step->toolCalls);
        $toolResults = $steps->flatMap(fn (Step $step) => $step->toolResults);
        $approvals = collect($approvalRequests);

        if ($lastResult?->structured !== null) {
            return (new StructuredTextResponse(
                $lastResult->structured,
                $finalStep->text,
                $totalUsage,
                $finalStep->meta,
            ))->withToolCallsAndResults($toolCalls, $toolResults)
                ->withToolApprovalRequests($approvals)
                ->withSteps($steps);
        }

        return (new TextResponse(
            $finalStep->text,
            $totalUsage,
            $finalStep->meta,
        ))->withMessages($newMessages)
            ->withToolCallsAndResults($toolCalls, $toolResults)
            ->withToolApprovalRequests($approvals)
            ->withSteps($steps);
    }
}
