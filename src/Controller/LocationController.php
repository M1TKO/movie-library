<?php
declare(strict_types=1);
namespace App\Controller;

use App\Location\HostPath;
use App\Location\LocationManager;
use App\Location\FolderBrowser;
use App\Discovery\ScanOptions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

final class LocationController extends AbstractController
{
    #[Route('/locations', name: 'locations', methods: ['GET', 'POST'])]
    public function index(Request $request, Connection $db, HostPath $paths, FolderBrowser $browser): Response
    {
        $error = null;
        $input = ['label' => '', 'path' => $request->query->getString('path'), 'hint' => 'mixed', 'recursive' => true, 'exclusions'=>implode("\n", ScanOptions::EXCLUSIONS)];
        if ($request->isMethod('POST')) {
            $input = ['label' => trim($request->request->getString('label')), 'path' => $request->request->getString('path'),
                'hint' => $request->request->getString('hint'), 'recursive' => $request->request->getBoolean('recursive'), 'exclusions'=>$request->request->getString('exclusions')];
            try {
                if (!$this->isCsrfTokenValid('locations', $request->request->getString('_token'))) { throw new \InvalidArgumentException('The form expired. Please try again.'); }
                if ($input['label'] === '' || mb_strlen($input['label']) > 100) { throw new \InvalidArgumentException('Enter a label of 1–100 characters.'); }
                if (!in_array($input['hint'], ['mixed', 'movies', 'tv'], true)) { throw new \InvalidArgumentException('Choose a valid content type.'); }
                $normalized = $paths->normalize($input['path']);
                $exclusions = json_encode(ScanOptions::exclusions($input['exclusions']), JSON_THROW_ON_ERROR);
                $accessible = $browser->browse($input['path'])['accessible'];
                $overlap = false;
                foreach ($db->fetchFirstColumn('SELECT normalized_root FROM locations') as $existing) { $overlap = $overlap || $paths->overlaps($normalized, $existing); }
                $db->insert('locations', ['label' => $input['label'], 'root_path' => $input['path'], 'normalized_root' => $normalized,
                    'recursive' => (int) $input['recursive'], 'content_hint' => $input['hint'], 'exclusions'=>$exclusions, 'status'=>$accessible ? 'accessible' : 'check_failed', 'created_at' => gmdate(DATE_ATOM)]);
                $this->addFlash($accessible ? 'success' : 'warning', $accessible ? 'Location saved and accessible. Start a scan when ready.' : 'Location saved, but access could not be verified within 3 seconds. It may be offline, linked, or inaccessible.');
                if ($overlap) { $this->addFlash('warning', 'This location overlaps an existing root. Discovery keeps one file record per normalized path and retains both memberships.'); }
                return $this->redirectToRoute('locations');
            } catch (UniqueConstraintViolationException) { $error = 'This folder is already configured.';
            } catch (\InvalidArgumentException $e) { $error = $e->getMessage(); }
        }
        return $this->render('locations/index.html.twig', ['locations' => $db->fetchAllAssociative('SELECT * FROM locations ORDER BY label, id'), 'error' => $error, 'input' => $input]);
    }

    #[Route('/folders', name: 'folders', methods: ['GET'])]
    public function folders(Request $request, FolderBrowser $browser): Response
    {
        $path = $request->query->getString('path');
        $result = null; $error = null;
        if ($path !== '') {
            try { $result = $browser->browse($path); }
            catch (\InvalidArgumentException $e) { $error = $e->getMessage(); }
        }
        return $this->render('locations/folders.html.twig', ['folder'=>$path,'result'=>$result,'error'=>$error]);
    }

    #[Route('/locations/{id}/toggle', name: 'location_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(int $id, Request $request, Connection $db, LocationManager $manager): Response
    {
        if (!$this->isCsrfTokenValid('toggle-'.$id, $request->request->getString('_token'))) { throw $this->createAccessDeniedException('Invalid form token.'); }
        $location = $db->fetchAssociative('SELECT * FROM locations WHERE id=?', [$id]);
        if (!$location) { throw $this->createNotFoundException(); }
        try { $manager->change($id, ['enabled'=>1-(int)$location['enabled']]); }
        catch (\InvalidArgumentException $e) { $this->addFlash('error', $e->getMessage()); }
        return $this->redirectToRoute('locations');
    }

    #[Route('/locations/{id}/edit', name: 'location_edit', requirements: ['id'=>'\d+'], methods: ['GET','POST'])]
    public function edit(int $id, Request $request, Connection $db, LocationManager $manager): Response
    {
        $location = $db->fetchAssociative('SELECT * FROM locations WHERE id=?', [$id]);
        if (!$location) { throw $this->createNotFoundException(); }
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit-'.$id, $request->request->getString('_token'))) { throw $this->createAccessDeniedException('Invalid form token.'); }
            try {
                $label = trim($request->request->getString('label'));
                if ($label === '' || mb_strlen($label) > 100) { throw new \InvalidArgumentException('Enter a label of 1–100 characters.'); }
                $manager->change($id, ['label'=>$label,'recursive'=>(int)$request->request->getBoolean('recursive'),'exclusions'=>json_encode(ScanOptions::exclusions($request->request->getString('exclusions')), JSON_THROW_ON_ERROR)]);
                return $this->redirectToRoute('locations');
            } catch (\InvalidArgumentException $e) { $error = $e->getMessage(); }
        }
        return $this->render('locations/edit.html.twig', ['location'=>$location,'error'=>$error,'exclusions'=>implode("\n",json_decode($location['exclusions'],true))]);
    }

    #[Route('/locations/{id}/remove', name: 'location_remove', requirements: ['id'=>'\d+'], methods: ['POST'])]
    public function remove(int $id, Request $request, LocationManager $manager): Response
    {
        if (!$this->isCsrfTokenValid('remove-'.$id, $request->request->getString('_token'))) { throw $this->createAccessDeniedException('Invalid form token.'); }
        try { $manager->change($id, [], true); $this->addFlash('success', 'Location removed. Catalog files and history were retained.'); }
        catch (\InvalidArgumentException $e) { $this->addFlash('error', $e->getMessage()); }
        return $this->redirectToRoute('locations');
    }
}
