<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class ToolApprovalRequest implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $approvalId,
        public string $toolCallId,
        public string $toolName,
        public array $arguments,
    ) {}

    /**
     * Reconstruct an instance from a previously serialized toArray() payload.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            approvalId: $data['approval_id'],
            toolCallId: $data['tool_call_id'],
            toolName: $data['tool_name'],
            arguments: $data['arguments'],
        );
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'approval_id' => $this->approvalId,
            'tool_call_id' => $this->toolCallId,
            'tool_name' => $this->toolName,
            'arguments' => $this->arguments,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
