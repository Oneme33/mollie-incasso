<?php

namespace Lukraak\MollieIncasso;

final class Failure extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $temporary = false, public readonly bool $unknown = false)
    {
        parent::__construct($reason);
    }
}
