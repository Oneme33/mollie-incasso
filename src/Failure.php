<?php

namespace Lukraak\MollieIncasso;

final class Failure extends \RuntimeException
{
    /** $detail: Mollie's own explanation of a rejection, already stripped of personal data. */
    public function __construct(public readonly string $reason, public readonly bool $temporary = false, public readonly bool $unknown = false, public readonly string $detail = '')
    {
        parent::__construct($reason);
    }
}
