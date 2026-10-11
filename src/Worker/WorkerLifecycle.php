<?php
declare(strict_types=1);
namespace App\Worker;

use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Lock\{LockFactory, LockInterface};
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Messenger\Event\{WorkerStartedEvent, WorkerRunningEvent, WorkerStoppedEvent, WorkerMessageFailedEvent};

final class WorkerLifecycle implements EventSubscriberInterface
{
    private ?LockInterface $lock = null;
    private string $name = '';
    private string $instance = '';
    private int $lastBeat = 0;
    public function __construct(private readonly Connection $db) {}
    public static function getSubscribedEvents(): array
    {
        return [WorkerStartedEvent::class => 'started', WorkerRunningEvent::class => 'running', WorkerStoppedEvent::class => 'stopped', WorkerMessageFailedEvent::class => 'failed'];
    }
    public function started(WorkerStartedEvent $event): void
    {
        $names = $event->getWorker()->getMetadata()->getTransportNames();
        if (count($names) !== 1 || !in_array($names[0], ['filesystem', 'metadata'], true)) {
            throw new \RuntimeException('Run exactly one queue per worker: filesystem or metadata.');
        }
        $this->name = $names[0];
        $this->lock = (new LockFactory(new FlockStore($_SERVER['APP_DATA_DIR'])))->createLock('worker-'.$this->name);
        if (!$this->lock->acquire()) { throw new \RuntimeException('A '.$this->name.' worker already owns this data directory.'); }
        $this->instance = getenv('APP_INSTANCE_ID') ?: bin2hex(random_bytes(16));
        $now = gmdate(DATE_ATOM);
        $this->db->executeStatement('INSERT INTO worker_health (name,instance_id,pid,state,started_at,heartbeat_at) VALUES (?,?,?,?,?,?) ON CONFLICT(name) DO UPDATE SET instance_id=excluded.instance_id,pid=excluded.pid,state=excluded.state,started_at=excluded.started_at,heartbeat_at=excluded.heartbeat_at,last_error=NULL', [$this->name,$this->instance,getmypid(),'running',$now,$now]);
    }
    public function running(WorkerRunningEvent $event): void
    {
        if (time() - $this->lastBeat >= 2) {
            $this->db->executeStatement('UPDATE worker_health SET heartbeat_at=? WHERE name=? AND instance_id=?', [gmdate(DATE_ATOM),$this->name,$this->instance]);
            $this->lastBeat = time();
        }
    }
    public function stopped(WorkerStoppedEvent $event): void
    {
        $this->db->executeStatement("UPDATE worker_health SET state='stopped', heartbeat_at=? WHERE name=? AND instance_id=?", [gmdate(DATE_ATOM),$this->name,$this->instance]);
        $this->lock?->release();
    }
    public function failed(WorkerMessageFailedEvent $event): void
    {
        // Do not persist arbitrary exception messages that could contain secrets.
        $this->db->executeStatement('UPDATE worker_health SET last_error=? WHERE name=? AND instance_id=?', ['Message failed; inspect local worker logs.',$this->name,$this->instance]);
    }
}
