<?php
declare(strict_types=1);
namespace App\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

final class ResponseHeaders
{
    #[AsEventListener]
    public function onResponse(ResponseEvent $event): void
    {
        $headers = $event->getResponse()->headers;
        $headers->set('Content-Security-Policy', "default-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'same-origin');
        $headers->set('Cache-Control', 'no-store, private');
    }
}
