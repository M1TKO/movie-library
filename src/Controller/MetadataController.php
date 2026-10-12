<?php
declare(strict_types=1);
namespace App\Controller;
use App\Metadata\{Credentials,Provider,ProviderFailure,Dispatcher,MatchWriter,TmdbProvider};
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response,BinaryFileResponse};
use Symfony\Component\Routing\Attribute\Route;

final class MetadataController extends AbstractController
{
    #[Route('/metadata/settings',name:'metadata_settings',methods:['GET','POST'])]
    public function settings(Request $request,Connection $db,Credentials $credentials,Provider $provider,Dispatcher $dispatcher): Response
    {
        $error=null; $notice=null;
        if ($request->isMethod('POST')) {
            $this->csrf($request,'metadata-settings');
            try {
                $action=$request->request->getString('action','save');
                if ($action==='remove') { $credentials->remove(); $notice='Credential removed. Cached metadata and local catalog remain available.'; }
                elseif ($action==='queue') { $notice=$dispatcher->backlog().' pending titles queued for metadata. Review existing titles to retry failures.'; }
                else {
                    $language=$request->request->getString('language','en-US');
                    if (!preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D',$language)) { throw new \InvalidArgumentException('Use a language code such as en-US, bg-BG, or fr-FR.'); }
                    $token=trim($request->request->getString('credential'));
                    if ($token!=='') { $credentials->save($token); }
                    $db->update('settings',['value'=>$language],['name'=>'metadata_language']);
                    if ($action==='validate') { $provider->validate(); $notice='TMDB credential validated. Settings saved.'; }
                    else { $notice='Metadata settings saved. Queue pending titles when ready.'; }
                }
            } catch (ProviderFailure|\InvalidArgumentException $e) { $error=$e->getMessage(); }
        }
        return $this->render('metadata/settings.html.twig',['error'=>$error,'notice'=>$notice,'configured'=>$credentials->configured(),
            'language'=>$db->fetchOne("SELECT value FROM settings WHERE name='metadata_language'") ?: 'en-US']);
    }
    #[Route('/matches',name:'match_review',methods:['GET'])]
    public function review(Request $request,Connection $db): Response
    {
        $page=max(1,min(1000000,$request->query->getInt('page',1)));
        $rows=$db->fetchAllAssociative("SELECT 'movies' type,id,title,matching_state,manual_lock FROM movies WHERE matching_state IN ('pending','needs_review','unmatched') UNION ALL SELECT 'series',id,title,matching_state,manual_lock FROM series WHERE matching_state IN ('pending','needs_review','unmatched') ORDER BY title,type,id LIMIT 50 OFFSET ?",[($page-1)*50],[\PDO::PARAM_INT]);
        $runs=$db->fetchAllAssociative("SELECT * FROM runs WHERE job_type='enrichment' ORDER BY queued_at DESC,id DESC LIMIT 20");
        return $this->render('metadata/review.html.twig',['rows'=>$rows,'runs'=>$runs,'page'=>$page]);
    }
    #[Route('/matches/{type}/{id}',name:'match_edit',requirements:['type'=>'movies|series','id'=>'\\d+'],methods:['GET','POST'])]
    public function edit(string $type,int $id,Request $request,Connection $db,Dispatcher $dispatcher,MatchWriter $writer): Response
    {
        $title=$db->fetchAssociative("SELECT * FROM $type WHERE id=?",[$id]);
        if (!$title) {
            $target=$db->fetchOne('SELECT target_id FROM title_redirects WHERE content_type=? AND source_id=?',[$type,$id]);
            if ($target) { return $this->redirectToRoute('match_edit',['type'=>$type,'id'=>$target],303); }
            throw $this->createNotFoundException();
        }
        $error=null;
        if ($request->isMethod('POST')) {
            $this->csrf($request,'match-'.$type.'-'.$id);
            try {
                $action=$request->request->getString('action');
                if (in_array($action,['search','confirm','refresh','retry'],true)) {
                    $providerId=$request->request->getInt('provider_id');
                    if ($action==='confirm' && $title['tmdb_id'] && (int)$title['tmdb_id']!==$providerId && !$request->request->getBoolean('reassignment')) { throw new \InvalidArgumentException('Confirm unrelated reassignment. Watched state stays with the previous title.'); }
                    $run=$dispatcher->dispatch($type,$id,$action==='retry'?'refresh':$action,$request->request->getString('query',$title['title']),$providerId ?: null);
                    $this->addFlash('success',$run ? 'Metadata job queued. Results appear below after the metadata worker completes it.' : 'No metadata job needed.');
                } elseif ($action==='rematch') { $writer->rematch($type,$id); $dispatcher->dispatch($type,$id,'rematch'); $this->addFlash('success','Automatic match lock reopened.'); }
                elseif (in_array($action,['edit','unmatched'],true)) {
                    $year=$request->request->getString('year');
                    if ($year!=='' && !ctype_digit($year)) { throw new \InvalidArgumentException('Use a four-digit year or leave blank.'); }
                    $writer->edit($type,$id,$request->request->getString('title'),$year===''?null:(int)$year,$request->request->getString('imdb'),$action==='unmatched');
                    $this->addFlash('success','Local correction saved and locked against automatic replacement.');
                } else { throw new \InvalidArgumentException('Unknown match action.'); }
                return $this->redirectToRoute('match_edit',['type'=>$type,'id'=>$id]);
            } catch (\InvalidArgumentException $e) { $error=$e->getMessage(); }
        }
        $column=$type==='movies'?'movie_id':'series_id';
        $candidates=$db->fetchAllAssociative("SELECT * FROM match_candidates WHERE $column=? ORDER BY score DESC,id",[$id]);
        foreach ($candidates as &$candidate) { $candidate['data']=json_decode($candidate['summary'],true); $candidate['poster']=TmdbProvider::posterKey($candidate['data']['poster_path'] ?? null); }
        unset($candidate);
        $files=$type==='movies' ? $db->fetchAllAssociative('SELECT f.* FROM media_files f JOIN file_content_links l ON l.file_id=f.id WHERE l.movie_id=? ORDER BY f.id LIMIT 50',[$id])
            : $db->fetchAllAssociative('SELECT DISTINCT f.* FROM media_files f JOIN file_content_links l ON l.file_id=f.id JOIN episodes e ON e.id=l.episode_id JOIN seasons s ON s.id=e.season_id WHERE s.series_id=? ORDER BY f.id LIMIT 50',[$id]);
        return $this->render('metadata/edit.html.twig',['title'=>$title,'type'=>$type,'error'=>$error,'candidates'=>$candidates,'files'=>$files,
            'runs'=>$db->fetchAllAssociative('SELECT * FROM runs WHERE deduplication_key=? ORDER BY queued_at DESC,id DESC LIMIT 10',['metadata:'.$type.':'.$id])]);
    }
    #[Route('/files/{id}/assign',name:'file_assign',requirements:['id'=>'\\d+'],methods:['GET','POST'])]
    public function assign(int $id,Request $request,Connection $db,MatchWriter $writer,Dispatcher $dispatcher): Response
    {
        $file=$db->fetchAssociative('SELECT * FROM media_files WHERE id=?',[$id]);
        if (!$file) { throw $this->createNotFoundException(); } $error=null;
        if ($request->isMethod('POST')) {
            $this->csrf($request,'file-assign-'.$id);
            try {
                $year=$request->request->getString('year'); $season=$request->request->getString('season'); $raw=$request->request->getString('episodes');
                if (($year!=='' && !ctype_digit($year)) || ($season!=='' && !ctype_digit($season)) || ($raw!=='' && !preg_match('/^[0-9]+(?:[ ,]+[0-9]+)*$/D',$raw))) { throw new \InvalidArgumentException('Use whole season/episode numbers, separated by spaces or commas.'); }
                [$table,$title]=$writer->assignFile($id,$request->request->getString('type'),$request->request->getString('title'),$year===''?null:(int)$year,
                    $season===''?null:(int)$season,$raw===''?[]:array_map('intval',preg_split('/[ ,]+/',$raw)));
                if ($table==='series') { $dispatcher->dispatch($table,$title); }
                return $this->redirectToRoute('match_edit',['type'=>$table,'id'=>$title]);
            } catch (\InvalidArgumentException $e) { $error=$e->getMessage(); }
        }
        return $this->render('metadata/assign.html.twig',['file'=>$file,'error'=>$error,'parsed'=>json_decode($file['parsed_fields'],true)]);
    }
    #[Route('/posters/{key}',name:'poster',requirements:['key'=>'[a-f0-9]{64}\\.jpg'],methods:['GET'])]
    public function poster(string $key): Response
    {
        $path=$_SERVER['APP_DATA_DIR'].'/posters/'.$key;
        if (is_file($path)) { return new BinaryFileResponse($path,200,['Content-Type'=>'image/jpeg']); }
        return new Response('<svg xmlns="http://www.w3.org/2000/svg" width="342" height="513"><rect width="100%" height="100%" fill="#243044"/><text x="171" y="256" text-anchor="middle" fill="white" font-family="sans-serif">Poster unavailable</text></svg>',200,['Content-Type'=>'image/svg+xml']);
    }
    private function csrf(Request $request,string $key): void { if (!$this->isCsrfTokenValid($key,$request->request->getString('_token'))) { throw $this->createAccessDeniedException('Invalid form token.'); } }
}
