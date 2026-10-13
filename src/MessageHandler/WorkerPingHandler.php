<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\WorkerPing;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class WorkerPingHandler
{
    public function __construct(private readonly Connection $db) {}
    public function __invoke(WorkerPing $ping): void
    {
        // A real durable round trip, with idempotent replay and no media access.
        $this->db->executeStatement('INSERT OR IGNORE INTO worker_receipts (id, queue_name, completed_at) VALUES (?, ?, ?)', [$ping->id, $ping->queue, gmdate(DATE_ATOM)]);
    }
}
