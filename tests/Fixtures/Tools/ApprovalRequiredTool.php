<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\RequiresApproval;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

#[RequiresApproval]
class ApprovalRequiredTool implements Tool
{
    public static int $calls = 0;

    public static ?array $handledArguments = null;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Deletes a file from the filesystem.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        static::$calls++;
        static::$handledArguments = $request->all();

        return 'Deleted '.$request->string('path');
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->required(),
        ];
    }
}
