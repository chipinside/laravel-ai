<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolApprovalResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Tests\Fixtures\Agents\ApprovalAgent;
use Tests\Fixtures\Tools\ApprovalRequiredTool;

beforeEach(function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);

    ApprovalRequiredTool::$calls = 0;
    ApprovalRequiredTool::$handledArguments = null;
});

test('a tool requiring approval pauses execution and surfaces an approval request', function () {
    Http::fake([
        'api.openai.com/*' => Http::sequence([
            fakeOpenAiApprovalToolCallResponse(),
        ]),
    ]);

    $response = (new ApprovalAgent)->prompt('Delete /tmp/report.pdf', provider: 'openai');

    expect(ApprovalRequiredTool::$calls)->toBe(0)
        ->and(Http::recorded())->toHaveCount(1)
        ->and($response->awaitingApproval())->toBeTrue()
        ->and($response->toolApprovalRequests)->toHaveCount(1)
        ->and($response->toolApprovalRequests->first()->toolName)->toBe('ApprovalRequiredTool')
        ->and($response->toolApprovalRequests->first()->arguments)->toBe(['path' => '/tmp/report.pdf']);
});

test('resume after approval executes the tool and continues the conversation', function () {
    Http::fake([
        'api.openai.com/*' => Http::sequence([
            fakeOpenAiApprovalToolCallResponse(),
            fakeOpenAiResponse('I deleted the file.'),
        ]),
    ]);

    $first = (new ApprovalAgent)->prompt('Delete /tmp/report.pdf', provider: 'openai');

    $toolCallId = $first->toolApprovalRequests->first()->toolCallId;

    $resumed = new AnonymousAgent(
        instructions: 'You are a helpful assistant.',
        messages: [new UserMessage('Delete /tmp/report.pdf'), ...$first->messages],
        tools: [new ApprovalRequiredTool],
    );

    $response = $resumed->resume([
        new ToolApprovalResponse($toolCallId, approved: true),
    ], provider: 'openai');

    expect(ApprovalRequiredTool::$calls)->toBe(1)
        ->and(ApprovalRequiredTool::$handledArguments)->toBe(['path' => '/tmp/report.pdf'])
        ->and((string) $response)->toBe('I deleted the file.')
        ->and($response->awaitingApproval())->toBeFalse()
        ->and($response->toolResults)->toHaveCount(1);
});

test('resume after denial reports the denial to the model without executing the tool', function () {
    Http::fake([
        'api.openai.com/*' => Http::sequence([
            fakeOpenAiApprovalToolCallResponse(),
            fakeOpenAiResponse('Understood, I will not delete it.'),
        ]),
    ]);

    $first = (new ApprovalAgent)->prompt('Delete /tmp/report.pdf', provider: 'openai');

    $toolCallId = $first->toolApprovalRequests->first()->toolCallId;

    $resumed = new AnonymousAgent(
        instructions: 'You are a helpful assistant.',
        messages: [new UserMessage('Delete /tmp/report.pdf'), ...$first->messages],
        tools: [new ApprovalRequiredTool],
    );

    $response = $resumed->resume([
        new ToolApprovalResponse($toolCallId, approved: false, reason: 'Not allowed'),
    ], provider: 'openai');

    expect(ApprovalRequiredTool::$calls)->toBe(0)
        ->and($response->toolResults)->toHaveCount(1)
        ->and($response->toolResults->first()->result)->toBe('Tool execution was denied by the user. Reason: Not allowed');
});

test('streamResume after approval executes the tool and streams the continuation', function () {
    Http::fake([
        'api.openai.com/*' => Http::sequence([
            Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    $this->outputItemAdded('fc_approval_1', 'call_approval_1', 'ApprovalRequiredTool'),
                    $this->functionCallArgumentsDelta('fc_approval_1', '{"path":"/tmp/report.pdf"}'),
                    $this->functionCallArgumentsDone('fc_approval_1', '{"path":"/tmp/report.pdf"}'),
                    $this->responseCompleted(10, 5, output: [
                        ['type' => 'function_call', 'status' => 'completed', 'id' => 'fc_approval_1', 'call_id' => 'call_approval_1', 'name' => 'ApprovalRequiredTool', 'arguments' => '{"path":"/tmp/report.pdf"}'],
                    ]),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
            Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    $this->outputTextDelta('I deleted the file.'),
                    $this->outputTextDone('I deleted the file.'),
                    $this->responseCompleted(20, 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]),
    ]);

    $streamed = null;

    $first = (new ApprovalAgent)
        ->stream('Delete /tmp/report.pdf', provider: 'openai')
        ->then(function (StreamedAgentResponse $response) use (&$streamed) {
            $streamed = $response;
        });

    foreach ($first as $event) {
        // Drain the stream so the pause surfaces the approval request...
    }

    expect(ApprovalRequiredTool::$calls)->toBe(0)
        ->and($streamed->awaitingApproval())->toBeTrue()
        ->and($streamed->toolApprovalRequests)->toHaveCount(1);

    $toolCallId = $streamed->toolApprovalRequests->first()->toolCallId;

    $resumed = new AnonymousAgent(
        instructions: 'You are a helpful assistant.',
        messages: [
            new UserMessage('Delete /tmp/report.pdf'),
            new AssistantMessage($streamed->text, $streamed->toolCalls),
        ],
        tools: [new ApprovalRequiredTool],
    );

    $resumedResponse = null;
    $events = [];

    $stream = $resumed
        ->streamResume([new ToolApprovalResponse($toolCallId, approved: true)], provider: 'openai')
        ->then(function (StreamedAgentResponse $response) use (&$resumedResponse) {
            $resumedResponse = $response;
        });

    foreach ($stream as $event) {
        $events[] = $event;
    }

    $toolResultEvents = array_values(array_filter($events, fn ($event) => $event instanceof ToolResultEvent));

    expect(ApprovalRequiredTool::$calls)->toBe(1)
        ->and(ApprovalRequiredTool::$handledArguments)->toBe(['path' => '/tmp/report.pdf'])
        ->and($toolResultEvents)->toHaveCount(1)
        ->and($resumedResponse->text)->toBe('I deleted the file.')
        ->and($resumedResponse->awaitingApproval())->toBeFalse();
});

function fakeOpenAiApprovalToolCallResponse(): PromiseInterface
{
    return Http::response([
        'id' => 'resp_approval_1',
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [[
            'type' => 'function_call',
            'id' => 'fc_approval_1',
            'call_id' => 'call_approval_1',
            'name' => 'ApprovalRequiredTool',
            'arguments' => '{"path":"/tmp/report.pdf"}',
            'status' => 'completed',
        ]],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ]);
}
