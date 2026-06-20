<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolApprovalResponse;
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
