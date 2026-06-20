<?php

namespace Laravel\Ai\Tools;

use Laravel\Ai\Attributes\RequiresApproval;
use Laravel\Ai\Contracts\Tool;
use ReflectionClass;

class RequiresApprovalResolver
{
    /**
     * Determine if the given tool requires human approval before execution.
     */
    public static function requiresApproval(Tool $tool): bool
    {
        return ! empty((new ReflectionClass($tool))->getAttributes(RequiresApproval::class));
    }
}
