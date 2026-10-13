<?php

declare(strict_types=1);

namespace App\Controller;

use App\Availability\CheckDispatcher;
use App\Location\{LocationRelocator,FolderBrowser};
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response};
use Symfony\Component\Routing\Attribute\Route;

final class AvailabilityController extends AbstractController
{
    #[Route('/availability-checks', name: 'availability_start', methods: ['POST'])]
    public function start(Request $request, CheckDispatcher $checks): Response
    {
        if (!$this->isCsrfTokenValid('availability', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
        $id = $checks->dispatch();
        return $id ? $this->redirectToRoute('run_detail', ['id' => $id]) : $this->redirectToRoute('library');
    }
    #[Route('/locations/{id}/relocate', name: 'location_relocate', requirements: ['id' => '\d+'], methods: ['GET','POST'])]
    public function relocate(int $id, Request $request, Connection $db, LocationRelocator $relocator, FolderBrowser $browser, CheckDispatcher $checks): Response
    {
        $location = $db->fetchAssociative('SELECT * FROM locations WHERE id=?', [$id]);
        if (!$location) {
            throw $this->createNotFoundException();
        }
        $error = null;
        $preview = null;
        $target = $request->request->getString('path');
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('relocate-' . $id, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid form token.');
            }
            try {
                if ($request->request->getString('action') === 'confirm') {
                    $relocator->relocate($id, $target);
                    $run = $checks->dispatch('relocation');
                    $this->addFlash('success', 'Catalog paths updated; verification queued. File identities, watched state and history retained.');
                    return $run ? $this->redirectToRoute('run_detail', ['id' => $run]) : $this->redirectToRoute('locations');
                }
                $preview = $browser->browse($target);
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }
        return $this->render('locations/relocate.html.twig', ['location' => $location,'target' => $target,'preview' => $preview,'error' => $error,'files' => $db->fetchAllAssociative('SELECT relative_path FROM file_locations WHERE location_id=? ORDER BY relative_path LIMIT 50', [$id])]);
    }
}
