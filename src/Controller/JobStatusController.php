<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class JobStatusController extends AbstractController
{
    #[Route('/jobs/status', name: 'job_status', methods: ['GET'])]
    public function status(Connection $db): Response
    {
        return $this->render('runs/status.html.twig', ['jobs' => $db->fetchAllAssociative("SELECT * FROM runs WHERE state IN ('queued','running') ORDER BY queued_at,id LIMIT 10")]);
    }
}
