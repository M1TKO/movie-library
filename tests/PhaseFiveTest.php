<?php
declare(strict_types=1);
namespace App\Tests;
use App\Catalog\{Experience,Watched,LibraryQuery};
use App\Host\HostActions;
use App\Security\Owner;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
final class PhaseFiveTest extends WebTestCase
{
    private $client;
    private Connection $db;
    private int $series;
    private int $season;
    private int $movie;
    private array $episodes=[];
    private int $file;
    protected function setUp(): void
    {
        $this->client=self::createClient();
        $this->db=self::getContainer()->get(Connection::class);
        foreach (['events','run_file_results','run_locations','file_content_links','file_locations','media_files','episodes','seasons','movies','series','runs','messenger_messages','locations'] as $table) { $this->db->executeStatement('DELETE FROM '.$table); }
        $this->db->executeStatement('DELETE FROM owner_auth');
        $hash=password_hash('fixture-password',PASSWORD_DEFAULT);
        $this->db->insert('owner_auth',['id'=>1,'password_hash'=>$hash,'created_at'=>gmdate(DATE_ATOM)]);
        $this->client->loginUser(new Owner($hash));
        $now=gmdate(DATE_ATOM);
        $this->db->insert('movies',['title'=>'Fixture movie','created_at'=>$now,'updated_at'=>$now]); $this->movie=(int)$this->db->lastInsertId();
        $this->db->insert('series',['title'=>'Fixture show','created_at'=>$now,'updated_at'=>$now]); $this->series=(int)$this->db->lastInsertId();
        $this->db->insert('seasons',['series_id'=>$this->series,'season_number'=>0]); $this->season=(int)$this->db->lastInsertId();
        for ($i=1;$i<=3;++$i) {
            $this->db->insert('episodes',['season_id'=>$this->season,'episode_number'=>$i,'title'=>'Episode '.$i,'created_at'=>$now,'updated_at'=>$now]);
            $this->episodes[]=(int)$this->db->lastInsertId();
        }
        $this->db->insert('media_files',['filename'=>'double.mkv','original_path'=>'/synthetic/double.mkv','normalized_path'=>'/synthetic/double.mkv','size_bytes'=>100,'check_outcome'=>'missing','created_at'=>$now,'updated_at'=>$now]); $this->file=(int)$this->db->lastInsertId();
        foreach (array_slice($this->episodes,0,2) as $episode) { $this->db->insert('file_content_links',['file_id'=>$this->file,'episode_id'=>$episode]); }
    }
    public function testBulkScopeTimestampsHistoryAndStalePreview(): void
    {
        $watched=self::getContainer()->get(Watched::class);
        $targets=$watched->targets('series',$this->series);
        self::assertCount(2,$targets);
        $snapshot=$watched->snapshot($targets);
        self::assertSame(2,$watched->change('series',$this->series,true,$snapshot));
        self::assertSame(0,(int)$this->db->fetchOne('SELECT watched FROM episodes WHERE id=?',[$this->episodes[2]]));
        self::assertSame(2,(int)$this->db->fetchOne('SELECT COUNT(*) FROM episodes WHERE watched_at IS NOT NULL'));
        self::assertSame(2,(int)$this->db->fetchOne("SELECT COUNT(*) FROM events WHERE event_type='watched_changed'"));
        self::assertSame(0,$watched->change('series',$this->series,true,$watched->snapshot($watched->targets('series',$this->series))));
        try { $watched->change('series',$this->series,false,$snapshot); self::fail('Stale preview accepted'); } catch (\RuntimeException $e) { self::assertStringContainsString('Preview',$e->getMessage()); }
        $snapshot=$watched->snapshot($watched->targets('series',$this->series));
        $this->db->insert('file_content_links',['file_id'=>$this->file,'episode_id'=>$this->episodes[2]]);
        try { $watched->change('series',$this->series,false,$snapshot); self::fail('Expanded scope accepted'); } catch (\RuntimeException) {}
        self::assertSame(2,$watched->change('seasons',$this->season,false,$watched->snapshot($watched->targets('seasons',$this->season))));
        self::assertSame(0,(int)$this->db->fetchOne('SELECT COUNT(*) FROM episodes WHERE watched_at IS NOT NULL'));
    }
    public function testNavigationDistinctStorageAndFilteredWatchedTotals(): void
    {
        $this->db->update('episodes',['watched'=>1],['id'=>$this->episodes[0]]);
        $experience=self::getContainer()->get(Experience::class);
        $seasons=$experience->seasons($this->series);
        self::assertCount(2,$seasons[0]['episodes']);
        self::assertSame('Partially watched',$seasons[0]['state']);
        self::assertSame(1,(int)$experience->seriesStates()['partial']);
        self::assertSame(1,(int)$seasons[0]['totals']['files']);
        self::assertSame(100,(int)$seasons[0]['totals']['bytes']);
        self::assertSame(1,(int)$experience->watchedTotals()['watched']);
        self::assertSame(2,(int)$experience->watchedTotals()['unwatched']);
        $query=self::getContainer()->get(LibraryQuery::class);
        self::assertSame(1,(int)$query->browse(Request::create('/?view=series'))['watchedTotals']['watched']);
        self::assertSame(1,(int)$query->browse(Request::create('/?view=series&watched=partial'))['seriesStates']['partial']);
        self::assertSame(1,(int)$query->browse(Request::create('/?view=files'))['watchedTotals']['unwatched']);
        $this->client->request('GET','/catalog/series/'.$this->series);
        self::assertResponseIsSuccessful(); self::assertSelectorTextContains('main','Partially watched'); self::assertSelectorTextContains('main','Specials');
        $this->client->clickLink('Specials'); self::assertResponseIsSuccessful(); self::assertSelectorTextContains('main','Episode 1'); self::assertSelectorTextNotContains('main','Episode 3');
        $this->client->clickLink('Episode 1 · Episode 1'); self::assertResponseIsSuccessful(); self::assertSelectorTextContains('main','double.mkv');
        self::assertSelectorTextContains('main','missing'); self::assertSelectorExists('[data-copy-path]');
    }
    public function testWatchedFormsRejectCsrfAndPersistAcrossKernelRestart(): void
    {
        $this->client->request('POST','/watched/movies/'.$this->movie,['state'=>'1']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET','/watched/movies/'.$this->movie);
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Confirm mark watched'); self::assertResponseRedirects('/catalog/movies/'.$this->movie);
        $this->client->followRedirect(); self::assertSelectorTextContains('main','Watched');
        $this->db=self::getContainer()->get(Connection::class);
        self::assertSame(1,(int)$this->db->fetchOne('SELECT watched FROM movies WHERE id=?',[$this->movie]));
        self::assertNotNull($this->db->fetchOne('SELECT watched_at FROM movies WHERE id=?',[$this->movie]));
        $this->client->request('GET','/watched/movies/'.$this->movie);
        $this->client->submitForm('Confirm mark unwatched'); $this->client->followRedirect();
        self::assertSelectorTextContains('main','Unwatched');
        $this->db=self::getContainer()->get(Connection::class);
        self::assertNull($this->db->fetchOne('SELECT watched_at FROM movies WHERE id=?',[$this->movie]));
    }
    public function testStaleBulkFormReportsConflictAndRefreshesPreview(): void
    {
        $this->client->request('GET','/watched/series/'.$this->series);
        self::assertSelectorTextContains('main','2 cataloged episodes affected');
        $this->db->update('episodes',['watched'=>1],['id'=>$this->episodes[0]]);
        $this->client->submitForm('Confirm mark watched');
        self::assertResponseStatusCodeSame(409); self::assertSelectorTextContains('[role="alert"]','Preview the action again');
        $this->client->submitForm('Confirm mark watched'); self::assertResponseRedirects();
    }
    public function testHostCommandOnlyUsesCatalogPathAndRejectsUnavailableTargets(): void
    {
        $names=['MOVIE_LIBRARY_INTERACTIVE_DESKTOP','SESSIONNAME','DISPLAY'];
        $old=[]; foreach ($names as $name) { $old[$name]=getenv($name); }
        putenv('MOVIE_LIBRARY_INTERACTIVE_DESKTOP=1'); putenv('SESSIONNAME=Console'); putenv('DISPLAY=:fixture');
        try {
            $this->db->update('settings',['value'=>'true'],['name'=>'host_actions_enabled']);
            $host=self::getContainer()->get(HostActions::class);
            self::assertTrue($host->enabled());
            foreach ([$this->file,999999] as $id) {
                try { $host->command($id,'play'); self::fail('Unavailable target accepted'); } catch (\RuntimeException $e) { self::assertStringContainsString('available',$e->getMessage()); }
            }
            $path=PHP_OS_FAMILY==='Windows' ? "D:\Synthetic\Movie's & [test].mkv" : "/synthetic/Movie's & [test].mkv";
            $this->db->update('media_files',['original_path'=>$path,'check_outcome'=>'available'],['id'=>$this->file]);
            $command=$host->command($this->file,'play');
            if (PHP_OS_FAMILY==='Windows') {
                self::assertSame('powershell.exe',$command[0]);
                self::assertSame("Invoke-Item -LiteralPath '".str_replace("'","''",$path)."' -ErrorAction Stop",mb_convert_encoding(base64_decode(end($command)),'UTF-8','UTF-16LE'));
            } else { self::assertSame(['xdg-open',$path],$command); }
            try { $host->command($this->file,'arbitrary-command'); self::fail('Arbitrary action accepted'); } catch (\InvalidArgumentException) {}
            $folder=$host->command($this->file,'folder');
            if (PHP_OS_FAMILY==='Windows') { self::assertStringContainsString(dirname($path),mb_convert_encoding(base64_decode(end($folder)),'UTF-8','UTF-16LE')); }
            else { self::assertSame(['xdg-open',dirname($path)],$folder); }
            self::assertSame(0,(int)$this->db->fetchOne('SELECT SUM(watched) FROM episodes'));
        } finally {
            foreach ($old as $name=>$value) { putenv($value===false?$name:$name.'='.$value); }
            $this->db->update('settings',['value'=>'false'],['name'=>'host_actions_enabled']);
        }
    }
    public function testHostActionsDisabledWithoutDesktopAndRequireAuthentication(): void
    {
        $old=getenv('MOVIE_LIBRARY_INTERACTIVE_DESKTOP'); putenv('MOVIE_LIBRARY_INTERACTIVE_DESKTOP=0');
        try {
            $host=self::getContainer()->get(HostActions::class);
            $this->db->update('settings',['value'=>'true'],['name'=>'host_actions_enabled']);
            self::assertFalse($host->enabled());
            try { $host->launch($this->file,'play'); self::fail('Host action accepted'); } catch (\RuntimeException $e) { self::assertStringContainsString('disabled',$e->getMessage()); }
            $this->client->request('GET','/settings'); self::assertSelectorExists('input[name="host_actions"][disabled]');
            $this->client->request('POST','/files/'.$this->file.'/host/play'); self::assertResponseStatusCodeSame(403);
            $this->client->request('GET','/files/'.$this->file); self::assertSelectorNotExists('form[action$="/host/play"]');
            $this->client->restart();
            foreach (['/seasons/'.$this->season,'/episodes/'.$this->episodes[0],'/watched/series/'.$this->series] as $url) { $this->client->request('GET',$url); self::assertResponseRedirects('/login'); }
            $this->client->request('POST','/files/'.$this->file.'/host/play'); self::assertResponseRedirects('/login');
        } finally { putenv($old===false?'MOVIE_LIBRARY_INTERACTIVE_DESKTOP':'MOVIE_LIBRARY_INTERACTIVE_DESKTOP='.$old); }
    }
}
