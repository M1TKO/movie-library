<?php

declare(strict_types=1);

namespace App\Security;

use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

final class NetworkPolicy
{
    public function __construct(private readonly Connection $db) {}
    public static function privateAddress(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        $parts = array_map('intval', explode('.', $ip));
        return $parts[0] === 10 || ($parts[0] === 172 && $parts[1] >= 16 && $parts[1] <= 31) || ($parts[0] === 192 && $parts[1] === 168);
    }
    #[AsEventListener(priority: 128)]
    public function check(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if (is_file($_SERVER['APP_DATA_DIR'] . '/maintenance')) {
            $event->setResponse(new Response('Catalog maintenance in progress.', 503, ['Retry-After' => '30']));
            return;
        }
        $ip = $request->getClientIp() ?? '';
        $local = in_array($ip, ['127.0.0.1','::1'], true);
        $host = $request->getHost();
        $hosts = ['localhost','127.0.0.1','[::1]'];
        $lan = $this->db->fetchOne("SELECT value FROM settings WHERE name='lan_enabled'") === 'true';
        $address = (string) $this->db->fetchOne("SELECT value FROM settings WHERE name='lan_address'");
        if ($lan && self::privateAddress($address)) {
            $hosts[] = $address;
        }
        if (!in_array($host, $hosts, true)) {
            $event->setResponse(new Response('Untrusted host.', 400));
            return;
        }
        if (!$local && (!$lan || !self::privateAddress($ip))) {
            $event->setResponse(new Response('Network access is not permitted.', 403));
            return;
        }
        $origin = $request->headers->get('Origin');
        if (!$request->isMethodSafe() && $origin && $origin !== $request->getSchemeAndHttpHost()) {
            $event->setResponse(new Response('Origin is not permitted.', 403));
        }
    }
}
