<?php

namespace Laravel\Ai\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class RequiresApproval
{
    public function __construct(public ?string $message = null)
    {
        //
    }
}
