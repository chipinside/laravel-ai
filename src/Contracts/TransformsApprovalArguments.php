<?php

namespace Laravel\Ai\Contracts;

interface TransformsApprovalArguments
{
    /**
     * Transform the arguments surfaced in this tool's approval request before it is
     * returned to the caller or broadcast. This is presentation-only and does not
     * affect the arguments the tool is actually executed with.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function transformApprovalArguments(string $toolCallId, array $arguments): array;
}
