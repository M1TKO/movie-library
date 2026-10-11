<?php
declare(strict_types=1);
namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LibraryController extends AbstractController
{
    #[Route('/', name: 'library', methods: ['GET'])]
    public function index(Connection $db): Response
    {
        return $this->render('library/index.html.twig', ['locationCount' => $db->fetchOne('SELECT COUNT(*) FROM locations')]);
    }
    #[Route('/settings', name: 'settings', methods: ['GET'])]
    public function settings(Connection $db): Response
    {
        $workers = [];
        foreach (['filesystem','metadata'] as $name) {
            $row = $db->fetchAssociative('SELECT * FROM worker_health WHERE name=?',[$name]);
            $workers[] = ['name'=>$name,'status'=>$row && $row['state']==='running' && strtotime($row['heartbeat_at']) >= time()-15 ? 'Running' : 'Stopped or not responding', 'heartbeat'=>$row['heartbeat_at'] ?? 'Never', 'error'=>$row['last_error'] ?? null];
        }
        return $this->render('library/settings.html.twig',['workers'=>$workers]);
    }
}
