<?php

declare(strict_types=1);

namespace App\Message;

final class CheckAvailability
{
    public function __construct(public readonly string $runId) {}
}
