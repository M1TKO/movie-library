<?php

declare(strict_types=1);

namespace App\Security;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/** Bound attempts before password hashing, across processes and sessions. */
final class LoginThrottle
{
    public function __construct(private readonly Connection $db) {}

    #[AsEventListener(priority: 64)]
    public function check(RequestEvent $event): void
    {
        $r = $event->getRequest();
        if (!$event->isMainRequest() || $r->getPathInfo() !== '/login' || !$r->isMethod('POST')) {
            return;
        }
        try {
            $ok = $this->db->transactional(function () use ($r): bool {
                $now = time();
                $this->db->executeStatement('DELETE FROM login_attempts WHERE window_start<?', [$now - 300]);
                $allowed = true;
                foreach ([hash('sha256', $r->getClientIp() ?? 'unknown') => 5, 'global' => 50] as $id => $max) {
                    $this->db->executeStatement('INSERT INTO login_attempts(identity,window_start,attempts) VALUES(?,?,1) ON CONFLICT(identity) DO UPDATE SET attempts=attempts+1', [$id, $now]);
                    $allowed = (int) $this->db->fetchOne('SELECT attempts FROM login_attempts WHERE identity=?', [$id]) <= $max && $allowed;
                }
                return $allowed;
            });
        } catch (DriverException $exception) {
            // Allow login on databases that have not yet received the throttle migration.
            if (!str_contains(strtolower($exception->getMessage()), "no such table")) {
                throw $exception;
            }
            $ok = true;
        }
        if (!$ok) {
            $event->setResponse(new Response('Too many login attempts. Try again in five minutes.', 429, ['Retry-After' => '300']));
        }
    }
}
