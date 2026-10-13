<?php
declare(strict_types=1);
namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use App\Discovery\ScanOptions;
use Symfony\Component\Process\Process;

final class LibraryController extends AbstractController
{
    public function __construct(private readonly \App\Catalog\Experience $experience, private readonly \App\Host\HostActions $host) {}
    #[Route('/', name: 'library', methods: ['GET'])]
    public function index(Connection $db, Request $request, \App\Availability\CheckDispatcher $checks, \App\Catalog\LibraryQuery $library): Response
    {
        if (!$request->getSession()->get('availability_requested', false)) {
            $checks->dispatch('library_session');
            $request->getSession()->set('availability_requested', true);
        }
        $stats = [];
        foreach (['movies','series','episodes','media_files'] as $table) { $stats[$table] = (int)$db->fetchOne('SELECT COUNT(*) FROM '.$table); }
        $stats['episodes'] = (int)$db->fetchOne('SELECT COUNT(*) FROM episodes e WHERE EXISTS(SELECT 1 FROM file_content_links l WHERE l.episode_id=e.id)');
        $stats['unresolved'] = (int)$db->fetchOne('SELECT COUNT(*) FROM media_files f WHERE NOT EXISTS(SELECT 1 FROM file_content_links l WHERE l.file_id=f.id)');
        $stats['availability'] = $this->experience->totals();
        $stats['watched'] = $this->experience->watchedTotals();
        $stats['series_states'] = $this->experience->seriesStates();
        $stats['available_bytes'] = (int)$db->fetchOne("SELECT COALESCE(SUM(size_bytes),0) FROM media_files WHERE check_outcome='available'");
        return $this->render('library/index.html.twig', $library->browse($request) + ['stats'=>$stats,'availabilityRun'=>$db->fetchAssociative("SELECT * FROM runs WHERE job_type='availability' ORDER BY queued_at DESC,id DESC LIMIT 1")]);
    }
    #[Route('/files/{id}', name: 'file_detail', requirements: ['id'=>'\d+'], methods: ['GET'])]
    public function fileDetail(int $id, Request $request, Connection $db, \App\Catalog\History $history): Response
    {
        $file = $db->fetchAssociative('SELECT * FROM media_files WHERE id=?', [$id]);
        if (!$file) { throw $this->createNotFoundException(); }
        return $this->render('library/file.html.twig', $history->page('file', $id, $request->query->getInt('history_page', 1)) + ['file'=>$file,'hostEnabled'=>$this->host->enabled(),
            'links'=>$db->fetchAllAssociative('SELECT m.id movie_id,m.title movie_title,s.id series_id,s.title series_title,se.id season_id,e.id episode_id,se.season_number,e.episode_number FROM file_content_links l LEFT JOIN movies m ON m.id=l.movie_id LEFT JOIN episodes e ON e.id=l.episode_id LEFT JOIN seasons se ON se.id=e.season_id LEFT JOIN series s ON s.id=se.series_id WHERE l.file_id=?', [$id]),
            'locations'=>$db->fetchAllAssociative('SELECT l.label,l.enabled,fl.in_scope FROM file_locations fl JOIN locations l ON l.id=fl.location_id WHERE fl.file_id=?', [$id])]);
    }
    #[Route('/catalog/{type}/{id}', name: 'title_detail', requirements: ['type'=>'movies|series','id'=>'\d+'], methods: ['GET'])]
    public function title(string $type, int $id, Request $request, Connection $db, \App\Catalog\History $history): Response
    {
        $title = $db->fetchAssociative('SELECT * FROM '.$type.' WHERE id=?', [$id]);
        if (!$title) {
            $target=$db->fetchOne('SELECT target_id FROM title_redirects WHERE content_type=? AND source_id=?',[$type,$id]);
            if ($target) { return $this->redirectToRoute('title_detail',['type'=>$type,'id'=>$target]); }
            throw $this->createNotFoundException();
        }

        $experience=$this->experience;
        $physical=$type==='movies' ? 'SELECT f.* FROM media_files f WHERE EXISTS(SELECT 1 FROM file_content_links l WHERE l.file_id=f.id AND l.movie_id=?)' : 'SELECT f.* FROM media_files f WHERE EXISTS(SELECT 1 FROM file_content_links l JOIN episodes e ON e.id=l.episode_id JOIN seasons s ON s.id=e.season_id WHERE l.file_id=f.id AND s.series_id=?)';
        $totals=$experience->totals($physical,[$id]);
        $page = max(1, min($request->query->getInt('page',1),max(1,(int)ceil($totals['files']/50))));
        $sql = $type === 'movies'
            ? 'SELECT f.*,NULL season_number,NULL episode_number,NULL episode_title,NULL episode_imdb FROM media_files f JOIN file_content_links l ON l.file_id=f.id WHERE l.movie_id=? ORDER BY f.id'
            : 'SELECT f.*,s.season_number,e.episode_number,e.title episode_title,e.imdb_id episode_imdb FROM episodes e JOIN seasons s ON s.id=e.season_id JOIN file_content_links l ON l.episode_id=e.id JOIN media_files f ON f.id=l.file_id WHERE s.series_id=? ORDER BY s.season_number,e.episode_number,f.id';
        $files = $db->fetchAllAssociative($sql.' LIMIT 50 OFFSET ?', [$id,($page-1)*50], [\PDO::PARAM_INT,\PDO::PARAM_INT]);
        return $this->render('library/title.html.twig', $history->page($type, $id, $request->query->getInt('history_page', 1)) + ['title'=>$title,'type'=>$type,'files'=>$files,'page'=>$page,'poster'=>\App\Metadata\TmdbProvider::posterKey($title['poster_path']),
            'totals'=>$totals,'seasons'=>$type==='series'?$experience->seasons($id):[],'hostEnabled'=>$this->host->enabled(),'genres'=>json_decode($title['genres'],true)]);
    }
    #[Route('/settings', name: 'settings', methods: ['GET','POST'])]
    public function settings(Connection $db, Request $request, \App\Maintenance\Preferences $preferences): Response
    {
        $error = null; $probeVersion = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('settings', $request->request->getString('_token'))) { throw $this->createAccessDeniedException('Invalid form token.'); }
            try {
                $current=$preferences->values();
                $input=[];
                foreach (['page_size','probe_concurrency','poster_cache_mib','log_level','retention_days','lan_address'] as $name) { $input[$name]=$request->request->getString($name,$current[$name]); }
                $input['lan_enabled']=$request->request->getBoolean('lan_enabled')?'true':'false';
                if (!in_array($request->getClientIp(),['127.0.0.1','::1'],true) && ($input['lan_enabled']!==$current['lan_enabled'] || $input['lan_address']!==$current['lan_address'])) { throw new \InvalidArgumentException('Change network access locally on the host.'); }
                $release=$preferences->validate($input);
                $extensions = ScanOptions::extensions($request->request->getString('extensions'));
                $probe = trim($request->request->getString('ffprobe'));
                if ($probe === '' || strlen($probe) > 4096 || str_contains($probe, "\0")) { throw new \InvalidArgumentException('Enter an executable name or an absolute FFprobe executable path.'); }
                $host=$this->host;
                $enabled=$request->request->getBoolean('host_actions') && $host->desktop();
                $db->transactional(function () use ($db, $extensions, $probe, $enabled, $release): void {
                    foreach ($release as $name=>$value) { $db->executeStatement('INSERT INTO settings(name,value) VALUES(?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value',[$name,$value]); }
                    $db->update('settings',['value'=>$enabled?'true':'false'],['name'=>'host_actions_enabled']);
                    $db->update('settings', ['value'=>implode(' ', $extensions)], ['name'=>'video_extensions']);
                    $db->update('settings', ['value'=>$probe], ['name'=>'ffprobe_executable']);
                });
                if ($request->request->getString('action') === 'validate') {
                    try {
                        $process = new Process([$probe, '-version'], null, null, null, 5);
                        $process->mustRun();
                        $line = strtok($process->getOutput(), "\n");
                        if (!$line || !str_starts_with($line, 'ffprobe version')) { throw new \RuntimeException(); }
                        $probeVersion = mb_substr($line, 0, 200);
                    } catch (\Throwable) { $error = 'Settings saved, but FFprobe validation failed. Install FFprobe or set its full executable path. Files remain discoverable without it.'; }
                } else { $this->addFlash('success', 'Discovery settings saved. They apply to newly queued scans.'); return $this->redirectToRoute('settings'); }
            } catch (\InvalidArgumentException $e) { $error = $e->getMessage(); }
        }
        $workers = [];
        foreach (['filesystem','metadata'] as $name) {
            $row = $db->fetchAssociative('SELECT * FROM worker_health WHERE name=?',[$name]);
            $workers[] = ['name'=>$name,'status'=>$row && $row['state']==='running' && strtotime($row['heartbeat_at']) >= time()-15 ? 'Running' : 'Stopped or not responding', 'heartbeat'=>$row['heartbeat_at'] ?? 'Never', 'error'=>$row['last_error'] ?? null];
        }
        return $this->render('library/settings.html.twig',['workers'=>$workers,'error'=>$error,'probeVersion'=>$probeVersion,
            'preferences'=>$preferences->values(),'hostDesktop'=>$this->host->desktop(),'hostEnabled'=>$db->fetchOne("SELECT value FROM settings WHERE name='host_actions_enabled'")==='true','extensions'=>$db->fetchOne("SELECT value FROM settings WHERE name='video_extensions'"),'ffprobe'=>$db->fetchOne("SELECT value FROM settings WHERE name='ffprobe_executable'")]);
    }
}
