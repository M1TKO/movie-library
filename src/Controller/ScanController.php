<?php
declare(strict_types=1);
namespace App\Controller;

use App\Discovery\ScanDispatcher;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

final class ScanController extends AbstractController
{
    #[Route('/scans', name: 'scan_start', methods: ['POST'])]
    public function start(Request $request, ScanDispatcher $dispatcher): Response
    {
        if (!$this->isCsrfTokenValid('scan', $request->request->getString('_token'))) { throw $this->createAccessDeniedException('Invalid form token.'); }
        $value = $request->request->getString('location');
        if ($value !== '' && (!ctype_digit($value) || (int)$value < 1)) { throw $this->createNotFoundException(); }
        try { $id = $dispatcher->dispatch($value === '' ? null : (int)$value); }
        catch (\InvalidArgumentException $e) { $this->addFlash('error', $e->getMessage()); return $this->redirectToRoute('locations'); }
        return $this->redirectToRoute('run_detail', ['id'=>$id]);
    }

    #[Route('/runs', name: 'runs', methods: ['GET'])]
    public function index(Request $request, Connection $db): Response
    {
        $total = (int)$db->fetchOne("SELECT COUNT(*) FROM runs WHERE job_type IN ('discovery','availability','enrichment')");
        $page = max(1, min(max(1, (int)ceil($total / 50)), $request->query->getInt('page', 1)));
        return $this->render('runs/index.html.twig', ['runs'=>$db->fetchAllAssociative("SELECT * FROM runs WHERE job_type IN ('discovery','availability','enrichment') ORDER BY queued_at DESC,id DESC LIMIT 50 OFFSET ?", [($page-1)*50], [\PDO::PARAM_INT]),'page'=>$page,'total'=>$total]);
    }

    #[Route('/runs/{id}', name: 'run_detail', requirements: ['id'=>'[a-f0-9]{32}'], methods: ['GET'])]
    public function detail(string $id, Request $request, Connection $db): Response
    {
        $run = $db->fetchAssociative('SELECT * FROM runs WHERE id=?', [$id]);
        if (!$run) { throw $this->createNotFoundException(); }
        $fileTotal = (int)$db->fetchOne('SELECT COUNT(*) FROM run_file_results WHERE run_id=?', [$id]);
        $page = max(1, min(max(1, (int)ceil($fileTotal / 50)), $request->query->getInt('page', 1)));
        $worker = $db->fetchAssociative("SELECT * FROM worker_health WHERE name=?", [$run['job_type']==='enrichment'?'metadata':'filesystem']);
        return $this->render('runs/detail.html.twig', ['run'=>$run,'roots'=>$db->fetchAllAssociative('SELECT * FROM run_locations WHERE run_id=? ORDER BY id', [$id]),
            'files'=>$db->fetchAllAssociative('SELECT * FROM run_file_results WHERE run_id=? ORDER BY id LIMIT 50 OFFSET ?', [$id,($page-1)*50], [\PDO::PARAM_STR,\PDO::PARAM_INT]),'page'=>$page,
            'fileTotal'=>$fileTotal,
            'workerHealthy'=>$worker && $worker['state'] === 'running' && strtotime($worker['heartbeat_at']) >= time()-15]);
    }

    #[Route('/runs/{id}/retry', name: 'run_retry', requirements: ['id'=>'[a-f0-9]{32}'], methods: ['POST'])]
    public function retry(string $id, Request $request, Connection $db, ScanDispatcher $scans, \App\Availability\CheckDispatcher $checks): Response
    {
        if (!$this->isCsrfTokenValid('retry-'.$id, $request->request->getString('_token'))) { throw $this->createAccessDeniedException('Invalid form token.'); }
        $run = $db->fetchAssociative('SELECT * FROM runs WHERE id=?', [$id]);
        if (!$run) { throw $this->createNotFoundException(); }
        if (in_array($run['state'], ['queued','running'], true)) { return $this->redirectToRoute('run_detail', ['id'=>$id]); }
        try {
            if ($run['job_type'] === 'availability') { $next = $checks->dispatch('manual_retry'); }
            elseif ($run['job_type'] === 'discovery') {
                $roots = json_decode($run['options'], true, 512, JSON_THROW_ON_ERROR)['roots'];
                $next = $scans->dispatch(count($roots) === 1 ? (int)$roots[0]['id'] : null);
            } else { return $this->redirectToRoute('match_review'); }
        } catch (\InvalidArgumentException $e) { $this->addFlash('error', $e->getMessage()); return $this->redirectToRoute('locations'); }
        return $next ? $this->redirectToRoute('run_detail', ['id'=>$next]) : $this->redirectToRoute('library');
    }

    #[Route('/runs/{id}/cancel', name: 'run_cancel', requirements: ['id'=>'[a-f0-9]{32}'], methods: ['POST'])]
    public function cancel(string $id, Request $request, Connection $db): Response
    {
        if (!$this->isCsrfTokenValid('cancel-'.$id, $request->request->getString('_token'))) { throw $this->createAccessDeniedException('Invalid form token.'); }
        $db->executeStatement("UPDATE runs SET cancel_requested=1, state=CASE WHEN state='queued' THEN 'cancelled' ELSE state END, finished_at=CASE WHEN state='queued' THEN ? ELSE finished_at END WHERE id=? AND state IN ('queued','running')", [gmdate(DATE_ATOM),$id]);
        $db->executeStatement("UPDATE run_locations SET enumeration_outcome='cancelled' WHERE run_id=? AND EXISTS(SELECT 1 FROM runs WHERE id=? AND state='cancelled')", [$id,$id]);
        return $this->redirectToRoute('run_detail', ['id'=>$id]);
    }
}
