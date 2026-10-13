<?php
declare(strict_types=1);
namespace App\Controller;
use App\Maintenance\Backups;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response};
use Symfony\Component\Routing\Attribute\Route;
final class BackupController extends AbstractController
{
    #[Route('/backups',name:'backups',methods:['GET','POST'])]
    public function index(Request $request,Backups $backups): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('backup',$request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
            try { $this->addFlash('success','Backup created: '.$backups->create()); }
            catch (\Throwable) { $this->addFlash('error','Backup failed. Check local disk space and permissions.'); }
            return $this->redirectToRoute('backups');
        }
        $items=[]; foreach (glob($_SERVER['APP_DATA_DIR'].'/backups/*/manifest.json') ?: [] as $path) { $items[]=dirname($path); } rsort($items);
        return $this->render('library/backups.html.twig',['items'=>$items]);
    }
}
