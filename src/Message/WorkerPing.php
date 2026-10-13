<?php

declare(strict_types=1);

namespace App\Message;

final readonly class WorkerPing
{
    public function __construct(public string $id, public string $queue) {}
}
