<?php

namespace Laravel\Ai\Exceptions;

class MissingToolApprovalException extends AiException
{
    public function __construct(public readonly string $toolName, public readonly string $toolCallId)
    {
        parent::__construct(sprintf(
            "Missing approval decision for tool call '%s' (tool '%s').",
            $toolCallId,
            $toolName,
        ));
    }
}
