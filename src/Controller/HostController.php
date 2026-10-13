<?php

declare(strict_types=1);

namespace App\Controller;

use App\Host\HostActions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response};
use Symfony\Component\Routing\Attribute\Route;

final class HostController extends AbstractController
{
    #[Route('/files/{id}/host/{action}', name: 'host_action', requirements: ['id' => '\d+','action' => 'play|folder'], methods: ['POST'])]
    public function action(int $id, string $action, Request $request, HostActions $host): Response
    {
        if (!$this->isCsrfTokenValid('host-' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $host->launch($id, $action);
            $this->addFlash('success', 'Host open request completed. Playback does not change watched state.');
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }
        return $this->redirectToRoute('file_detail', ['id' => $id]);
    }
}
