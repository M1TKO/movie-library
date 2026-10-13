<?php
declare(strict_types=1);
namespace App\Tests;
use App\Maintenance\{Backups,RuntimeLog,Preferences};
use App\Security\Owner;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
final class PhaseSixTest extends WebTestCase
{
    private Connection $db;
    private $client;
    private array $settings;
    protected function setUp(): void
    {
        $this->client=self::createClient([],['REMOTE_ADDR'=>'127.0.0.1']);
        $this->db=self::getContainer()->get(Connection::class);
        $this->settings=$this->db->fetchAllAssociative('SELECT * FROM settings');
        foreach (['events','run_file_results','run_locations','file_content_links','file_locations','media_files','episodes','seasons','movies','series','runs','messenger_messages','worker_receipts','worker_health','locations','owner_auth','login_attempts'] as $t) { $this->db->executeStatement('DELETE FROM '.$t); }
        $hash=password_hash('phase-six-password',PASSWORD_DEFAULT);
        $this->db->insert('owner_auth',['id'=>1,'password_hash'=>$hash,'created_at'=>gmdate(DATE_ATOM)]);
        $this->client->loginUser(new Owner($hash));
        $this->db->executeStatement("UPDATE settings SET value='false' WHERE name='lan_enabled'");
    }
    protected function tearDown(): void
    {
        $db=new \PDO('sqlite:'.$_SERVER['DATABASE_PATH']);
        foreach ($this->settings as $row) { $q=$db->prepare('UPDATE settings SET value=? WHERE name=?'); $q->execute([$row['value'],$row['name']]); }
        $db->exec('DELETE FROM login_attempts');
        parent::tearDown();
    }
    public function testRoundTripRetainsCatalogHistoryAndExcludesSecretsAndJobs(): void
    {
        $now=gmdate(DATE_ATOM);
        $this->db->insert('movies',['title'=>'Portable fixture','watched'=>1,'watched_at'=>$now,'created_at'=>$now,'updated_at'=>$now]); $id=(int)$this->db->lastInsertId();
        $this->db->insert('media_files',['filename'=>'fixture.mkv','original_path'=>'/synthetic/fixture.mkv','normalized_path'=>'/synthetic/fixture.mkv','created_at'=>$now,'updated_at'=>$now]); $file=(int)$this->db->lastInsertId();
        $this->db->insert('file_content_links',['file_id'=>$file,'movie_id'=>$id]);
        $this->db->insert('events',['occurred_at'=>$now,'event_type'=>'watched_changed','movie_id'=>$id,'path_snapshot'=>'/synthetic/fixture.mkv']);
        $this->db->insert('runs',['id'=>'backup-job','job_type'=>'discovery','trigger_name'=>'manual','deduplication_key'=>'backup-job','options'=>'{}','queued_at'=>$now]);
        $hash=$this->db->fetchOne('SELECT password_hash FROM owner_auth');
        $backups=self::getContainer()->get(Backups::class);
        $dir=$backups->create();
        $copy=new \PDO('sqlite:'.$dir.'/catalog.sqlite');
        self::assertSame(0,(int)$copy->query('SELECT COUNT(*) FROM owner_auth')->fetchColumn());
        self::assertSame(0,(int)$copy->query('SELECT COUNT(*) FROM messenger_messages')->fetchColumn()); $copy=null;
        self::assertStringNotContainsString($hash,(string)file_get_contents($dir.'/catalog.sqlite'));
        $this->db->update('movies',['watched'=>0,'title'=>'Changed'],['id'=>$id]);
        $before=$backups->restore($dir,'phase-six-password');
        self::assertFileExists($before.'/manifest.json');
        self::assertSame('Portable fixture',$this->db->fetchOne('SELECT title FROM movies WHERE id=?',[$id]));
        self::assertSame($now,$this->db->fetchOne('SELECT watched_at FROM movies WHERE id=?',[$id]));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT COUNT(*) FROM events'));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT COUNT(*) FROM file_content_links'));
        self::assertSame($hash,$this->db->fetchOne('SELECT password_hash FROM owner_auth'));
        self::assertSame('interrupted',$this->db->fetchOne("SELECT state FROM runs WHERE id='backup-job'"));
        self::assertSame('false',$this->db->fetchOne("SELECT value FROM settings WHERE name='lan_enabled'"));
        self::assertSame(1,(int)$this->db->fetchOne('PRAGMA foreign_keys'));
        self::assertFileDoesNotExist($_SERVER['APP_DATA_DIR'].'/maintenance');
    }
    public function testRestoreRejectsWrongOwnerActiveWorkerAndTampering(): void
    {
        $backups=self::getContainer()->get(Backups::class); $dir=$backups->create();
        try { $backups->restore($dir,'wrong'); self::fail('Wrong password accepted'); } catch (\RuntimeException $e) { self::assertStringContainsString('authentication',$e->getMessage()); }
        $lock=(new LockFactory(new FlockStore($_SERVER['APP_DATA_DIR'])))->createLock('worker-filesystem'); $lock->acquire();
        try { $backups->restore($dir,'phase-six-password'); self::fail('Live worker accepted'); } catch (\RuntimeException $e) { self::assertStringContainsString('Stop the application',$e->getMessage()); } finally { $lock->release(); }
        file_put_contents($dir.'/catalog.sqlite','tampered',FILE_APPEND);
        try { $backups->restore($dir,'phase-six-password'); self::fail('Tampering accepted'); } catch (\RuntimeException $e) { self::assertStringContainsString('checksum',$e->getMessage()); }
        self::assertSame(1,(int)$this->db->fetchOne('SELECT COUNT(*) FROM owner_auth'));
    }
    public function testIncompatibleBackupCannotReplaceCatalog(): void
    {
        $b=self::getContainer()->get(Backups::class); $dir=$b->create();
        $copy=new \PDO('sqlite:'.$dir.'/catalog.sqlite'); $copy->exec('CREATE TABLE future_schema(id INTEGER)'); $copy=null;
        $mp=$dir.'/manifest.json'; $m=json_decode(file_get_contents($mp),true); $m['sha256']=hash_file('sha256',$dir.'/catalog.sqlite'); file_put_contents($mp,json_encode($m));
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('schema'); $b->restore($dir,'phase-six-password');
    }
    public function testPrivateNetworkOriginsAndAnonymousAccess(): void
    {
        $this->db->executeStatement("UPDATE settings SET value='true' WHERE name='lan_enabled'");
        $this->db->executeStatement("UPDATE settings SET value='192.168.1.10' WHERE name='lan_address'");
        $this->client->request('GET','http://192.168.1.10/settings',[],[],['REMOTE_ADDR'=>'192.168.1.20']); self::assertResponseIsSuccessful();
        $this->client->request('POST','http://192.168.1.10/backups',[],[],['REMOTE_ADDR'=>'192.168.1.20','HTTP_ORIGIN'=>'http://evil.example']); self::assertResponseStatusCodeSame(403);
        $this->client->request('GET','http://192.168.1.10/settings',[],[],['REMOTE_ADDR'=>'8.8.8.8']); self::assertResponseStatusCodeSame(403);
        $this->client->restart();
        foreach (['/','/settings','/locations','/runs','/backups'] as $path) {
            $this->client->request('GET','http://192.168.1.10'.$path,[],[],['REMOTE_ADDR'=>'192.168.1.20']); self::assertResponseRedirects('http://192.168.1.10/login');
        }
    }
    public function testLoginThrottleAndBackupCsrf(): void
    {
        $this->client->request('POST','/backups'); self::assertResponseStatusCodeSame(403);
        $this->client->request('GET','/backups'); $this->client->submitForm('Create consistent backup'); self::assertResponseRedirects('/backups');
        $this->client->restart();
        for ($i=0;$i<5;++$i) { $this->client->request('POST','/login',['_username'=>'owner','_password'=>'wrong']); self::assertResponseRedirects('/login'); }
        $this->client->request('POST','/login',['_username'=>'owner','_password'=>'wrong']); self::assertResponseStatusCodeSame(429);
    }
    public function testPreferencesValidationAndRedactedRotatingLogs(): void
    {
        $p=self::getContainer()->get(Preferences::class);
        $values=$p->values(); $values['lan_enabled']='true'; $values['lan_address']='8.8.8.8';
        try { $p->validate($values); self::fail('Public address accepted'); } catch (\InvalidArgumentException) {}
        $dir=$_SERVER['APP_DATA_DIR'].'/logs'; if (!is_dir($dir)) { mkdir($dir,0700,true); }
        $path=$dir.'/redaction-test-'.bin2hex(random_bytes(4)).'.log';
        $log=new RuntimeLog($path,['splitsecret'],100);
        $log->append('Bearer split'); $log->append("secret api_key=anothersecret\n");
        self::assertStringNotContainsString('splitsecret',file_get_contents($path));
        self::assertStringNotContainsString('anothersecret',file_get_contents($path));
        for ($i=0;$i<20;++$i) { $log->append(str_repeat('x',60)."\n"); } $log->flush();
        self::assertLessThanOrEqual(100,filesize($path)); self::assertFileExists($path.'.3'); self::assertFileDoesNotExist($path.'.4');
    }
}
