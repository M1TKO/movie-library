<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function __invoke(Request $request, Connection $db): JsonResponse
    {
        if (!in_array($request->getClientIp(), ['127.0.0.1','::1'], true)) {
            return new JsonResponse(['status' => 'forbidden'], 403);
        }
        $db->fetchOne('SELECT COUNT(*) FROM worker_health');
        return new JsonResponse(['status' => 'ok','php' => PHP_VERSION,'sqlite' => $db->fetchOne('SELECT sqlite_version()'), 'extensions' => array_values(array_filter(['pdo_sqlite','mbstring','session','ctype','iconv','openssl','dom','xml','xmlwriter'], 'extension_loaded'))]);
    }
}
