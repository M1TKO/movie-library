<?php
declare(strict_types=1);
namespace App\Controller;
use App\Catalog\Watched;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response};
use Symfony\Component\Routing\Attribute\Route;
final class WatchedController extends AbstractController
{
    #[Route('/watched/{type}/{id}', name: 'watched', requirements: ['type'=>'movies|episodes|seasons|series','id'=>'\d+'], methods: ['GET','POST'])]
    public function action(string $type,int $id,Request $request,Watched $service,Connection $db): Response
    {
        $item=$db->fetchAssociative('SELECT * FROM '.$type.' WHERE id=?',[$id]);
        if (!$item) { throw $this->createNotFoundException(); }
        $targets=$service->targets($type,$id); $error=null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('watched-'.$type.'-'.$id,$request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
            $state=$request->request->getString('state');
            if (!in_array($state,['0','1'],true)) { return new Response('Invalid watched state.',400); }
            try {
                $count=$service->change($type,$id,$state==='1',$request->request->getString('snapshot'));
                $this->addFlash('success',$count.' watched states changed.');
                if ($type==='movies' || $type==='series') { return $this->redirectToRoute('title_detail',['type'=>$type,'id'=>$id]); }
                return $this->redirectToRoute($type==='seasons'?'season_detail':'episode_detail',['id'=>$id]);
            } catch (\RuntimeException $e) { $error=$e->getMessage(); $targets=$service->targets($type,$id); }
        }
        return $this->render('library/watched.html.twig',['type'=>$type,'item'=>$item,'targets'=>$targets,'snapshot'=>$service->snapshot($targets),'error'=>$error],new Response('', $error?409:200));
    }
}
