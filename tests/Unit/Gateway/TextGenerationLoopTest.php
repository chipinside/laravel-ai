<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\RequiresApproval;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Contracts\TransformsApprovalArguments;
use Laravel\Ai\Exceptions\MissingToolApprovalException;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolApprovalResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest as ToolApprovalRequestEvent;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Laravel\Ai\Tools\Request;

test('it does not execute tool calls on the final generation step', function () {
    $tool = new TextGenerationLoopCountingTool;
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse(
            text: '',
            toolCalls: [new ToolCall('call-1', TextGenerationLoopCountingTool::class, [], 'call-1')],
            finishReason: FinishReason::ToolCalls,
            usage: new Usage,
            meta: new Meta('fake', 'model'),
            continuationToken: 'response-1',
        ),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 1),
        null,
    );

    expect($tool->calls)->toBe(0)
        ->and($gateway->generateCalls)->toBe(1)
        ->and($gateway->contexts[0]->isFinalStep)->toBeTrue()
        ->and($response->toolCalls)->toHaveCount(1)
        ->and($response->toolResults)->toHaveCount(0)
        ->and($response->steps)->toHaveCount(1)
        ->and($response->steps->first()->toolResults)->toBe([]);
});

test('it holds stream end until the streamed tool loop is complete', function () {
    $tool = new TextGenerationLoopCountingTool;
    $firstToolCall = new ToolCall('call-1', TextGenerationLoopCountingTool::class, [], 'call-1');
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(
            events: [new ToolCallEvent('tool-call-event', $firstToolCall, time())],
            returns: new StepResponse(text: '', toolCalls: [$firstToolCall], finishReason: FinishReason::ToolCalls, usage: new Usage(10, 1), meta: new Meta('fake', 'model'), continuationToken: 'response-1'),
        ),
        textGenerationLoopStreamStep(
            events: [new TextDelta('text-delta', 'message-1', 'Done', time())],
            returns: new StepResponse(text: 'Done', toolCalls: [], finishReason: FinishReason::Stop, usage: new Usage(5, 2), meta: new Meta('fake', 'model'), continuationToken: 'response-2'),
        ),
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
    ));

    $streamEnds = collect($events)->whereInstanceOf(StreamEnd::class);

    expect($tool->calls)->toBe(1)
        ->and($gateway->streamCalls)->toBe(2)
        ->and($streamEnds)->toHaveCount(1)
        ->and(collect($events)->whereInstanceOf(ToolResultEvent::class))->toHaveCount(1)
        ->and($streamEnds->first()->reason)->toBe(FinishReason::Stop->value)
        ->and($streamEnds->first()->usage->promptTokens)->toBe(15)
        ->and($streamEnds->first()->usage->completionTokens)->toBe(3);
});

test('it does not execute streamed tool calls on the final step', function () {
    $tool = new TextGenerationLoopCountingTool;
    $toolCall = new ToolCall('call-1', TextGenerationLoopCountingTool::class, [], 'call-1');
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(
            events: [new ToolCallEvent('tool-call-event', $toolCall, time())],
            returns: new StepResponse(text: '', toolCalls: [$toolCall], finishReason: FinishReason::ToolCalls, usage: new Usage(10, 1), meta: new Meta('fake', 'model'), continuationToken: 'response-1'),
        ),
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 1),
        null,
    ));

    expect($tool->calls)->toBe(0)
        ->and($gateway->streamCalls)->toBe(1)
        ->and(collect($events)->whereInstanceOf(ToolResultEvent::class))->toHaveCount(0)
        ->and(collect($events)->whereInstanceOf(StreamEnd::class))->toHaveCount(1);
});

test('it clamps non-positive maxSteps to at least one turn', function (int $maxSteps) {
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse(
            text: 'hi',
            toolCalls: [],
            finishReason: FinishReason::Stop,
            usage: new Usage(1, 1),
            meta: new Meta('fake', 'model'),
        ),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [],
        null,
        new TextGenerationOptions(maxSteps: $maxSteps),
        null,
    );

    expect($gateway->generateCalls)->toBe(1)
        ->and($response->text)->toBe('hi');
})->with([
    'zero' => 0,
    'negative' => -3,
]);

test('it accumulates streamed usage across multi-step turns', function () {
    $tool = new TextGenerationLoopCountingTool;
    $toolCall = new ToolCall('call-1', TextGenerationLoopCountingTool::class, [], 'call-1');
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(
            events: [new ToolCallEvent('tool-call', $toolCall, time())],
            returns: new StepResponse(text: '', toolCalls: [$toolCall], finishReason: FinishReason::ToolCalls, usage: new Usage(10, 1), meta: new Meta('fake', 'model')),
        ),
        textGenerationLoopStreamStep(
            events: [new TextDelta('delta', 'msg-1', 'done', time())],
            returns: new StepResponse(text: 'done', toolCalls: [], finishReason: FinishReason::Stop, usage: new Usage(5, 2), meta: new Meta('fake', 'model')),
        ),
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
    ));

    $streamEnd = collect($events)->whereInstanceOf(StreamEnd::class)->first();

    expect($streamEnd)->toBeInstanceOf(StreamEnd::class)
        ->and($streamEnd->usage->promptTokens)->toBe(15)
        ->and($streamEnd->usage->completionTokens)->toBe(3)
        ->and($streamEnd->reason)->toBe(FinishReason::Stop->value);
});

test('it throws when generation tool calls do not match local tools', function () {
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse(
            text: '',
            toolCalls: [new ToolCall('call-1', 'MissingTool', [], 'call-1')],
            finishReason: FinishReason::ToolCalls,
            usage: new Usage(10, 1),
            meta: new Meta('fake', 'model'),
            continuationToken: 'response-1',
        ),
    ]);

    expect(fn () => (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [],
        null,
        null,
        null,
    ))->toThrow(NoSuchToolException::class, "Model tried to call unavailable tool 'MissingTool'.");
});

test('it throws when streaming tool calls do not match local tools', function () {
    $toolCall = new ToolCall('call-1', 'MissingTool', [], 'call-1');
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(
            events: [new ToolCallEvent('tool-call-event', $toolCall, time())],
            returns: new StepResponse(text: '', toolCalls: [$toolCall], finishReason: FinishReason::ToolCalls, usage: new Usage(10, 1), meta: new Meta('fake', 'model'), continuationToken: 'response-1'),
        ),
    ]);

    expect(fn () => iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [],
        null,
        null,
        null,
    )))->toThrow(NoSuchToolException::class);
});

test('it emits a terminal stream end when a turn yields no stream end or error', function () {
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(events: [new TextDelta('text-delta', 'message-1', 'partial', time())]),
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [],
        null,
        null,
        null,
    ));

    $streamEnds = collect($events)->whereInstanceOf(StreamEnd::class);

    expect($streamEnds)->toHaveCount(1)
        ->and($streamEnds->first()->reason)->toBe(FinishReason::Error->value);
});

test('it does not emit a stream end when a turn errors without a stream end', function () {
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(events: [new Error('error-1', 'server_error', 'Server overloaded', false, time())]),
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [],
        null,
        null,
        null,
    ));

    expect(collect($events)->whereInstanceOf(StreamEnd::class))->toHaveCount(0)
        ->and(collect($events)->whereInstanceOf(Error::class))->toHaveCount(1);
});

test('it pauses for approval when a tool requires it', function () {
    $tool = new TextGenerationLoopApprovalTool;
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse(
            text: '',
            toolCalls: [new ToolCall('call-1', TextGenerationLoopApprovalTool::class, ['path' => '/tmp/x'], 'call-1')],
            finishReason: FinishReason::ToolCalls,
            usage: new Usage(10, 1),
            meta: new Meta('fake', 'model'),
            continuationToken: 'response-1',
        ),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 3),
        null,
    );

    expect($tool->calls)->toBe(0)
        ->and($gateway->generateCalls)->toBe(1)
        ->and($response->awaitingApproval())->toBeTrue()
        ->and($response->toolApprovalRequests)->toHaveCount(1)
        ->and($response->toolApprovalRequests->first()->toolCallId)->toBe('call-1')
        ->and($response->toolApprovalRequests->first()->toolName)->toBe(TextGenerationLoopApprovalTool::class)
        ->and($response->toolApprovalRequests->first()->arguments)->toBe(['path' => '/tmp/x'])
        ->and($response->toolResults)->toHaveCount(0);
});

test('it surfaces approval requests even on the final step', function () {
    $tool = new TextGenerationLoopApprovalTool;
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse(
            text: '',
            toolCalls: [new ToolCall('call-1', TextGenerationLoopApprovalTool::class, [], 'call-1')],
            finishReason: FinishReason::ToolCalls,
            usage: new Usage(10, 1),
            meta: new Meta('fake', 'model'),
            continuationToken: 'response-1',
        ),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 1),
        null,
    );

    expect($tool->calls)->toBe(0)
        ->and($response->awaitingApproval())->toBeTrue()
        ->and($response->toolApprovalRequests)->toHaveCount(1);
});

test('a tool may transform its approval request arguments for display only', function () {
    $tool = new TextGenerationLoopTransformingApprovalTool;
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse(
            text: '',
            toolCalls: [new ToolCall('call-1', TextGenerationLoopTransformingApprovalTool::class, ['path' => '/secret'], 'call-1')],
            finishReason: FinishReason::ToolCalls,
            usage: new Usage(10, 1),
            meta: new Meta('fake', 'model'),
            continuationToken: 'response-1',
        ),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 3),
        null,
    );

    expect($response->toolApprovalRequests->first()->arguments)->toBe(['path' => 'REDACTED']);
});

test('resume executes approved tool calls with original arguments', function () {
    $tool = new TextGenerationLoopApprovalTool;
    $toolCall = new ToolCall('call-1', TextGenerationLoopApprovalTool::class, ['path' => '/tmp/x'], 'call-1');

    $messages = [
        new UserMessage('delete it'),
        new AssistantMessage('', collect([$toolCall])),
    ];

    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse(text: 'Done', toolCalls: [], finishReason: FinishReason::Stop, usage: new Usage(5, 2), meta: new Meta('fake', 'model')),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        $messages,
        [$tool],
        null,
        (new TextGenerationOptions(maxSteps: 3))->resumingWith([new ToolApprovalResponse('call-1', approved: true)]),
        null,
    );

    expect($tool->calls)->toBe(1)
        ->and($tool->handledArguments)->toBe(['path' => '/tmp/x'])
        ->and($gateway->generateCalls)->toBe(1)
        ->and($response->text)->toBe('Done')
        ->and($response->awaitingApproval())->toBeFalse()
        ->and($response->toolResults)->toHaveCount(1)
        ->and($response->toolResults->first()->result)->toBe('counted');
});

test('resume denies tool calls with a denial result', function () {
    $tool = new TextGenerationLoopApprovalTool;
    $toolCall = new ToolCall('call-1', TextGenerationLoopApprovalTool::class, [], 'call-1');

    $messages = [
        new UserMessage('delete it'),
        new AssistantMessage('', collect([$toolCall])),
    ];

    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse(text: 'Okay, cancelled.', toolCalls: [], finishReason: FinishReason::Stop, usage: new Usage(5, 2), meta: new Meta('fake', 'model')),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        $messages,
        [$tool],
        null,
        (new TextGenerationOptions(maxSteps: 3))->resumingWith([new ToolApprovalResponse('call-1', approved: false, reason: 'too risky')]),
        null,
    );

    expect($tool->calls)->toBe(0)
        ->and($response->toolResults)->toHaveCount(1)
        ->and($response->toolResults->first()->result)->toBe('Tool execution was denied by the user. Reason: too risky');
});

test('resume handles a mix of approved and denied calls', function () {
    $tool = new TextGenerationLoopApprovalTool;
    $approved = new ToolCall('call-1', TextGenerationLoopApprovalTool::class, ['path' => '/a'], 'call-1');
    $denied = new ToolCall('call-2', TextGenerationLoopApprovalTool::class, ['path' => '/b'], 'call-2');

    $messages = [
        new UserMessage('do both'),
        new AssistantMessage('', collect([$approved, $denied])),
    ];

    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse(text: 'Done', toolCalls: [], finishReason: FinishReason::Stop, usage: new Usage(5, 2), meta: new Meta('fake', 'model')),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        $messages,
        [$tool],
        null,
        (new TextGenerationOptions(maxSteps: 3))->resumingWith([
            new ToolApprovalResponse('call-1', approved: true),
            new ToolApprovalResponse('call-2', approved: false),
        ]),
        null,
    );

    expect($tool->calls)->toBe(1)
        ->and($response->toolResults)->toHaveCount(2)
        ->and($response->toolResults->firstWhere('id', 'call-1')->result)->toBe('counted')
        ->and($response->toolResults->firstWhere('id', 'call-2')->result)->toBe('Tool execution was denied by the user.');
});

test('resume is a no-op when there are no pending tool calls', function () {
    $tool = new TextGenerationLoopApprovalTool;

    $messages = [
        new UserMessage('hi'),
        new AssistantMessage('previous answer', collect([])),
    ];

    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse(text: 'Hello again', toolCalls: [], finishReason: FinishReason::Stop, usage: new Usage(1, 1), meta: new Meta('fake', 'model')),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        $messages,
        [$tool],
        null,
        (new TextGenerationOptions(maxSteps: 3))->resumingWith([]),
        null,
    );

    expect($gateway->generateCalls)->toBe(1)
        ->and($response->text)->toBe('Hello again')
        ->and($response->toolResults)->toHaveCount(0);
});

test('resume throws when an approval decision is missing', function () {
    $tool = new TextGenerationLoopApprovalTool;
    $toolCall = new ToolCall('call-1', TextGenerationLoopApprovalTool::class, [], 'call-1');

    $messages = [
        new UserMessage('delete it'),
        new AssistantMessage('', collect([$toolCall])),
    ];

    $gateway = new TextGenerationLoopFakeGateway([]);

    expect(fn () => (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        $messages,
        [$tool],
        null,
        (new TextGenerationOptions(maxSteps: 3))->resumingWith([]),
        null,
    ))->toThrow(MissingToolApprovalException::class);
});

test('streaming pauses with a tool approval request event', function () {
    $tool = new TextGenerationLoopApprovalTool;
    $toolCall = new ToolCall('call-1', TextGenerationLoopApprovalTool::class, ['path' => '/tmp/x'], 'call-1');

    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(
            events: [new ToolCallEvent('tool-call-event', $toolCall, time())],
            returns: new StepResponse(text: '', toolCalls: [$toolCall], finishReason: FinishReason::ToolCalls, usage: new Usage(10, 1), meta: new Meta('fake', 'model'), continuationToken: 'response-1'),
        ),
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 3),
        null,
    ));

    $approvalEvents = collect($events)->whereInstanceOf(ToolApprovalRequestEvent::class);
    $streamEnds = collect($events)->whereInstanceOf(StreamEnd::class);

    expect($tool->calls)->toBe(0)
        ->and($gateway->streamCalls)->toBe(1)
        ->and($approvalEvents)->toHaveCount(1)
        ->and($approvalEvents->first()->approvalRequest->toolCallId)->toBe('call-1')
        ->and($streamEnds)->toHaveCount(1)
        ->and($streamEnds->first()->reason)->toBe(FinishReason::ToolCalls->value);
});

test('streaming resume emits resolved tool results', function () {
    $tool = new TextGenerationLoopApprovalTool;
    $toolCall = new ToolCall('call-1', TextGenerationLoopApprovalTool::class, ['path' => '/tmp/x'], 'call-1');

    $messages = [
        new UserMessage('delete it'),
        new AssistantMessage('', collect([$toolCall])),
    ];

    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(
            events: [new TextDelta('delta', 'msg-1', 'Done', time())],
            returns: new StepResponse(text: 'Done', toolCalls: [], finishReason: FinishReason::Stop, usage: new Usage(5, 2), meta: new Meta('fake', 'model')),
        ),
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        $messages,
        [$tool],
        null,
        (new TextGenerationOptions(maxSteps: 3))->resumingWith([new ToolApprovalResponse('call-1', approved: true)]),
        null,
    ));

    $toolResultEvents = collect($events)->whereInstanceOf(ToolResultEvent::class);
    $streamEnds = collect($events)->whereInstanceOf(StreamEnd::class);

    expect($tool->calls)->toBe(1)
        ->and($toolResultEvents)->toHaveCount(1)
        ->and($toolResultEvents->first()->toolResult->result)->toBe('counted')
        ->and($streamEnds)->toHaveCount(1)
        ->and($streamEnds->first()->reason)->toBe(FinishReason::Stop->value);
});

function textGenerationLoopProvider(): TextProvider
{
    $provider = Mockery::mock(TextProvider::class);
    $provider->allows('name')->andReturn('fake');

    return $provider;
}

/** @param  array<int, object>  $events */
function textGenerationLoopStreamStep(array $events = [], ?StepResponse $returns = null): array
{
    return [$events, $returns];
}

class TextGenerationLoopFakeGateway implements StepTextGateway
{
    public int $generateCalls = 0;

    public int $streamCalls = 0;

    /** @var StepContext[] */
    public array $contexts = [];

    public function __construct(
        public array $steps = [],
        public array $streams = [],
    ) {}

    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $this->generateCalls++;
        $this->contexts[] = $stepContext;

        return array_shift($this->steps);
    }

    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        $this->streamCalls++;
        $this->contexts[] = $stepContext;

        [$events, $result] = array_shift($this->streams);

        foreach ($events as $event) {
            yield $event;
        }

        return $result;
    }
}

class TextGenerationLoopCountingTool implements Tool
{
    public int $calls = 0;

    public function description(): string
    {
        return 'Counts invocations.';
    }

    public function handle(Request $request): string
    {
        $this->calls++;

        return 'counted';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

#[RequiresApproval]
class TextGenerationLoopApprovalTool implements Tool
{
    public int $calls = 0;

    public ?array $handledArguments = null;

    public function description(): string
    {
        return 'Requires approval before execution.';
    }

    public function handle(Request $request): string
    {
        $this->calls++;
        $this->handledArguments = $request->all();

        return 'counted';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

#[RequiresApproval]
class TextGenerationLoopTransformingApprovalTool implements Tool, TransformsApprovalArguments
{
    public function description(): string
    {
        return 'Requires approval and redacts its arguments.';
    }

    public function handle(Request $request): string
    {
        return 'counted';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function transformApprovalArguments(string $toolCallId, array $arguments): array
    {
        return ['path' => 'REDACTED'];
    }
}
