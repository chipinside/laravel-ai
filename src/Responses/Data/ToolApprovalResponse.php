<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class ToolApprovalResponse implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $toolCallId,
        public bool $approved,
        public ?string $reason = null,
    ) {}

    /**
     * Reconstruct an instance from a previously serialized toArray() payload.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            toolCallId: $data['tool_call_id'],
            approved: $data['approved'],
            reason: $data['reason'] ?? null,
        );
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'tool_call_id' => $this->toolCallId,
            'approved' => $this->approved,
            'reason' => $this->reason,
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
