<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\ApprovalRequiredTool;

#[MaxSteps(5)]
class ApprovalAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant that can delete files when the user approves.';
    }

    public function tools(): iterable
    {
        return [new ApprovalRequiredTool];
    }
}
