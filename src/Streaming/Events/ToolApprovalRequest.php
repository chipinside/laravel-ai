<?php

namespace Laravel\Ai\Streaming\Events;

use Laravel\Ai\Responses\Data;

class ToolApprovalRequest extends StreamEvent
{
    public function __construct(
        public string $id,
        public Data\ToolApprovalRequest $approvalRequest,
        public int $timestamp,
    ) {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'tool_approval_request',
            'approval_id' => $this->approvalRequest->approvalId,
            'tool_call_id' => $this->approvalRequest->toolCallId,
            'tool_name' => $this->approvalRequest->toolName,
            'arguments' => $this->approvalRequest->arguments,
            'timestamp' => $this->timestamp,
        ];
    }
}
