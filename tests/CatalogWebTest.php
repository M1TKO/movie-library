<?php
declare(strict_types=1);
namespace App\Tests;

use App\Location\HostPath;
use App\Security\Owner;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CatalogWebTest extends WebTestCase
{
    public function testScanFormsSecurityProgressAndOfflineCatalog(): void
    {
        $client = self::createClient();
        $db = self::getContainer()->get(Connection::class);
        foreach (['events','run_file_results','run_locations','file_content_links','file_locations','media_files','episodes','seasons','movies','series','runs','messenger_messages','locations'] as $table) { $db->executeStatement('DELETE FROM '.$table); }
        $db->executeStatement('DELETE FROM owner_auth');
        $db->insert('owner_auth',['id'=>1,'password_hash'=>password_hash('fixture-password',PASSWORD_DEFAULT),'created_at'=>gmdate(DATE_ATOM)]);
        foreach (['/folders','/runs','/files/1','/catalog/movies/1'] as $path) { $client->request('GET',$path); self::assertResponseRedirects('/login'); }
        $client->request('POST','/scans'); self::assertResponseRedirects('/login');
        $client->loginUser(new Owner($db->fetchOne('SELECT password_hash FROM owner_auth')));
        $client->request('POST','/scans'); self::assertResponseStatusCodeSame(403);
        $client->request('POST','/availability-checks'); self::assertResponseStatusCodeSame(403);
        $path = $_SERVER['APP_DATA_DIR'].'/web-offline-root';
        $db->insert('locations',['label'=>'Offline fixture','root_path'=>$path,'normalized_root'=>self::getContainer()->get(HostPath::class)->normalize($path),'created_at'=>gmdate(DATE_ATOM)]);
        $client->request('GET','/locations'); $client->submitForm('Scan this location'); self::assertResponseRedirects();
        $client->followRedirect(); self::assertSelectorTextContains('h1','Scan progress');
        self::assertSelectorTextContains('#run-progress','Queued');
        self::assertSelectorExists('[hx-trigger="every 2s"]');
        $client->submitForm('Cancel scan'); $client->followRedirect();
        self::assertSelectorTextContains('#run-progress','Cancelled'); self::assertSelectorNotExists('[hx-trigger]');
        $db = self::getContainer()->get(Connection::class);
        $db->insert('media_files',['original_path'=>$path.'/video001.mkv','normalized_path'=>$path.'/video001.mkv','filename'=>'video001.mkv','size_bytes'=>0,'created_at'=>gmdate(DATE_ATOM),'updated_at'=>gmdate(DATE_ATOM)]);
        $id = (int)$db->lastInsertId();
        $client->request('GET','/?view=unresolved'); self::assertSelectorTextContains('h2','video001.mkv');
        $db = self::getContainer()->get(Connection::class);
        self::assertSame(1,(int)$db->fetchOne("SELECT COUNT(*) FROM runs WHERE job_type='availability' AND trigger_name='library_session'"));
        $client->request('GET','/?view=files'); self::assertResponseIsSuccessful();
        $db = self::getContainer()->get(Connection::class);
        self::assertSame(1,(int)$db->fetchOne("SELECT COUNT(*) FROM runs WHERE job_type='availability'"));
        $client->submitForm('Check known file availability'); self::assertResponseRedirects();
        $client->followRedirect(); self::assertSelectorTextContains('h1','Availability progress');
        $client->request('GET','/files/'.$id); self::assertResponseIsSuccessful(); self::assertSelectorTextContains('dl','0 bytes');
        $client->request('GET','/settings'); self::assertResponseIsSuccessful();
        $client->submitForm('Save settings',['extensions'=>'mkv mp4','ffprobe'=>'ffprobe']); self::assertResponseRedirects('/settings');
        $client->request('GET','/runs'); self::assertResponseIsSuccessful();
        $client->request('GET','/folders',['path'=>$_SERVER['APP_DATA_DIR']]); self::assertResponseIsSuccessful(); self::assertSelectorTextContains('a.button','Use this folder');
    }
}
