<?php
declare(strict_types=1);
namespace App\Controller;

use App\Location\HostPath;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

final class LocationController extends AbstractController
{
    #[Route('/locations', name: 'locations', methods: ['GET', 'POST'])]
    public function index(Request $request, Connection $db, HostPath $paths): Response
    {
        $error = null;
        $input = ['label' => '', 'path' => '', 'hint' => 'mixed', 'recursive' => true];
        if ($request->isMethod('POST')) {
            $input = ['label' => trim($request->request->getString('label')), 'path' => $request->request->getString('path'),
                'hint' => $request->request->getString('hint'), 'recursive' => $request->request->getBoolean('recursive')];
            try {
                if (!$this->isCsrfTokenValid('locations', $request->request->getString('_token'))) { throw new \InvalidArgumentException('The form expired. Please try again.'); }
                if ($input['label'] === '' || mb_strlen($input['label']) > 100) { throw new \InvalidArgumentException('Enter a label of 1–100 characters.'); }
                if (!in_array($input['hint'], ['mixed', 'movies', 'tv'], true)) { throw new \InvalidArgumentException('Choose a valid content type.'); }
                $normalized = $paths->normalize($input['path']);
                $overlap = false;
                foreach ($db->fetchFirstColumn('SELECT normalized_root FROM locations') as $existing) { $overlap = $overlap || $paths->overlaps($normalized, $existing); }
                $db->insert('locations', ['label' => $input['label'], 'root_path' => $input['path'], 'normalized_root' => $normalized,
                    'recursive' => (int) $input['recursive'], 'content_hint' => $input['hint'], 'created_at' => gmdate(DATE_ATOM)]);
                $this->addFlash('success', 'Location saved. Accessibility has not been checked; offline locations can be saved.');
                if ($overlap) { $this->addFlash('warning', 'This location overlaps an existing root. Discovery will need to deduplicate physical paths.'); }
                return $this->redirectToRoute('locations');
            } catch (UniqueConstraintViolationException) { $error = 'This folder is already configured.';
            } catch (\InvalidArgumentException $e) { $error = $e->getMessage(); }
        }
        return $this->render('locations/index.html.twig', ['locations' => $db->fetchAllAssociative('SELECT * FROM locations ORDER BY label, id'), 'error' => $error, 'input' => $input]);
    }

    #[Route('/locations/{id}/toggle', name: 'location_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(int $id, Request $request, Connection $db): Response
    {
        if (!$this->isCsrfTokenValid('toggle-'.$id, $request->request->getString('_token'))) { throw $this->createAccessDeniedException('Invalid form token.'); }
        if (!$db->executeStatement('UPDATE locations SET enabled = 1 - enabled WHERE id = ?', [$id])) { throw $this->createNotFoundException(); }
        return $this->redirectToRoute('locations');
    }
}
