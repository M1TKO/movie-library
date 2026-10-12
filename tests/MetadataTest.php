<?php
declare(strict_types=1);
namespace App\Tests;
use App\Metadata\{Credentials,Provider,ProviderFailure,TmdbProvider,Ranker,MatchWriter,Dispatcher};
use App\Message\Enrich;
use App\MessageHandler\EnrichHandler;
use App\Worker\WorkerLifecycle;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\{MockHttpClient};
use Symfony\Component\HttpClient\Response\MockResponse;

final class MetadataTest extends KernelTestCase
{
    private Connection $db;
    protected function setUp(): void
    {
        self::bootKernel(); $this->db=self::getContainer()->get(Connection::class);
        foreach (['title_redirects','title_aliases','match_candidates','events','run_file_results','run_locations','file_content_links','file_locations','locations','media_files','episodes','seasons','movies','series','runs','messenger_messages','metadata_cache'] as $table) { $this->db->executeStatement('DELETE FROM '.$table); }
        (new Credentials())->remove();
        $this->db->update('settings',['value'=>'en-US'],['name'=>'metadata_language']);
    }
    protected function tearDown(): void { (new Credentials())->remove(); parent::tearDown(); }
    private function movie(string $name='Example',?int $provider=null,int $watched=0): int
    {
        $this->db->insert('movies',['title'=>$name,'release_year'=>2020,'tmdb_id'=>$provider,'watched'=>$watched,'watched_at'=>$watched?'2026-01-01':null,
            'local_identity'=>hash('sha256',mb_strtolower($name).'|2020'),'created_at'=>gmdate(DATE_ATOM),'updated_at'=>gmdate(DATE_ATOM)]);
        return (int)$this->db->lastInsertId();
    }
    private function file(): int
    {
        $this->db->insert('media_files',['original_path'=>'/synthetic/Example.2020.mkv','normalized_path'=>'/synthetic/'.bin2hex(random_bytes(4)),
            'filename'=>'Example.2020.mkv','created_at'=>gmdate(DATE_ATOM),'updated_at'=>gmdate(DATE_ATOM)]);
        return (int)$this->db->lastInsertId();
    }
    private function data(int $id=100,string $title='Example'): array { return ['id'=>$id,'title'=>$title,'original_title'=>$title,'release_date'=>'2020-01-01','overview'=>'Synopsis','genres'=>[['name'=>'Drama']],'external_ids'=>['imdb_id'=>'tt1234567'],'poster_path'=>null]; }
    private function handler(Provider $provider): EnrichHandler { return new EnrichHandler($this->db,$provider,new Ranker(),new MatchWriter($this->db),self::getContainer()->get(WorkerLifecycle::class),self::getContainer()->get(Dispatcher::class)); }
    public function testCredentialEncryptionValidationAndRedactedErrors(): void
    {
        $credentials=new Credentials(); $token=str_repeat('sensitive',4); $credentials->save($token);
        self::assertSame($token,$credentials->read());
        self::assertStringNotContainsString($token,file_get_contents($_SERVER['APP_DATA_DIR'].'/tmdb.secret'));
        $client=new MockHttpClient(function ($method,$url,$options) use ($token): MockResponse {
            self::assertSame('https://api.themoviedb.org/3/authentication',$url);
            self::assertContains('Authorization: Bearer '.$token,$options['headers']);
            return new MockResponse('{"success":false}', ['http_code'=>401]);
        });
        try { (new TmdbProvider($client,$this->db,$credentials))->validate(); self::fail('Unauthorized credential accepted'); }
        catch (ProviderFailure $e) { self::assertStringNotContainsString($token,$e->getMessage()); }
        $credentials->remove(); self::assertFalse($credentials->configured());
    }
    public function testProviderCacheLanguageIsolationAndOfflineReuse(): void
    {
        $credentials=new Credentials(); $credentials->save(str_repeat('token',8)); $calls=0;
        $client=new MockHttpClient(function ($method,$url,$options) use (&$calls): MockResponse { ++$calls; self::assertStringNotContainsString('synthetic',$url); return new MockResponse(json_encode(['results'=>[$this->data()]])); });
        $provider=new TmdbProvider($client,$this->db,$credentials);
        self::assertCount(1,$provider->search('movie','Example','en-US'));
        $credentials->remove();
        self::assertCount(1,$provider->search('movie','Example','en-US')); self::assertSame(1,$calls);
        $credentials->save(str_repeat('token',8)); $provider->search('movie','Example','bg-BG'); self::assertSame(2,$calls);
        $this->expectException(\InvalidArgumentException::class); $provider->search('movie','C:\\private\\Movie.mkv','en-US');
    }
    public function testRetryRateLimitAndResponseBounds(): void
    {
        $credentials=new Credentials(); $credentials->save(str_repeat('token',8));
        $client=new MockHttpClient([new MockResponse('', ['http_code'=>503]),new MockResponse(json_encode($this->data()))]);
        self::assertSame(100,(new TmdbProvider($client,$this->db,$credentials))->details('movie',100,'en-US')['id']);
        self::assertSame(2,$client->getRequestsCount());
        $client=new MockHttpClient(new MockResponse('', ['http_code'=>429,'response_headers'=>['retry-after: 60']]));
        try { (new TmdbProvider($client,$this->db,$credentials))->details('movie',101,'en-US'); self::fail(); } catch (ProviderFailure) { self::assertSame(1,$client->getRequestsCount()); }
        $client=new MockHttpClient(new MockResponse(str_repeat('x',2*1024*1024+1)));
        $this->expectException(ProviderFailure::class);
        (new TmdbProvider($client,$this->db,$credentials))->details('movie',102,'en-US');
    }
    public function testConservativeRankingAmbiguityYearAndOriginalTitle(): void
    {
        $ranker=new Ranker(); $a=$this->data();
        self::assertTrue($ranker->automatic($ranker->rank('Example',2020,'movie',[$a])));
        self::assertFalse($ranker->automatic($ranker->rank('Example',null,'movie',[$a])));
        self::assertFalse($ranker->automatic($ranker->rank('Example',2021,'movie',[$a])));
        self::assertFalse($ranker->automatic($ranker->rank('Example',2020,'movie',[$a,$this->data(101)])));
        $a['title']='Translated'; self::assertTrue($ranker->automatic($ranker->rank('Example',2020,'movie',[$a])));
    }
    public function testDurableQueueCoalescingAutoMatchAndFailureRetention(): void
    {
        $credentials=new Credentials(); $credentials->save(str_repeat('token',8));
        $id=$this->movie(); $dispatcher=self::getContainer()->get(Dispatcher::class);
        $run=$dispatcher->dispatch('movies',$id); self::assertSame($run,$dispatcher->dispatch('movies',$id));
        self::assertSame('metadata',$this->db->fetchOne('SELECT queue_name FROM messenger_messages'));
        $provider=new FixtureProvider(); $provider->results=[$this->data()]; $provider->detail=$this->data();
        ($this->handler($provider))(new Enrich($run));
        self::assertSame('completed',$this->db->fetchOne('SELECT state FROM runs WHERE id=?',[$run]));
        self::assertSame('auto_matched',$this->db->fetchOne('SELECT matching_state FROM movies WHERE id=?',[$id]));
        self::assertSame('tt1234567',$this->db->fetchOne('SELECT imdb_id FROM movies WHERE id=?',[$id]));
        self::assertNull($dispatcher->dispatch('movies',$id));
        $failed=$this->movie('Other',null,1); $provider->failure=true;
        $run=$dispatcher->dispatch('movies',$failed); ($this->handler($provider))(new Enrich($run));
        self::assertSame('failed',$this->db->fetchOne('SELECT state FROM runs WHERE id=?',[$run]));
        self::assertSame('Other',$this->db->fetchOne('SELECT title FROM movies WHERE id=?',[$failed]));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT watched FROM movies WHERE id=?',[$failed]));
    }
    public function testMergePreservesCopiesWatchedHistoryAndAliases(): void
    {
        $source=$this->movie('Local Example',null,1); $target=$this->movie('Example',100);
        $file=$this->file(); $this->db->insert('file_content_links',['file_id'=>$file,'movie_id'=>$source]);
        $this->db->insert('events',['occurred_at'=>gmdate(DATE_ATOM),'event_type'=>'watched','movie_id'=>$source,'title_snapshot'=>'Local Example']);
        $run=$this->fixtureRun(); $writer=new MatchWriter($this->db);
        self::assertSame($target,$writer->apply('movies',$source,$this->data(),true,'en-US',$run));
        self::assertSame($target,(int)$this->db->fetchOne('SELECT movie_id FROM file_content_links'));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT watched FROM movies WHERE id=?',[$target]));
        self::assertSame('Local Example',$this->db->fetchOne("SELECT title_snapshot FROM events WHERE event_type='watched' AND movie_id=?",[$target]));
        self::assertSame($target,(int)$this->db->fetchOne('SELECT title_id FROM title_aliases'));
        $writer->edit('movies',$target,'Owner title',2020,'https://www.imdb.com/title/tt7654321/');
        $writer->apply('movies',$target,$this->data(),false,'en-US',$run);
        self::assertSame('Owner title',$this->db->fetchOne('SELECT title FROM movies WHERE id=?',[$target]));
        self::assertSame('tt7654321',$this->db->fetchOne('SELECT imdb_id FROM movies WHERE id=?',[$target]));
    }
    public function testUnrelatedReassignmentDoesNotTransferWatchedState(): void
    {
        $source=$this->movie('Old identity',200,1); $file=$this->file();
        $this->db->insert('file_content_links',['file_id'=>$file,'movie_id'=>$source]);
        $writer=new MatchWriter($this->db); $run=$this->fixtureRun();
        self::assertSame($source,$writer->apply('movies',$source,$this->data(),false,'en-US',$run));
        self::assertSame('needs_review',$this->db->fetchOne('SELECT matching_state FROM movies WHERE id=?',[$source]));
        $target=$writer->apply('movies',$source,$this->data(),true,'en-US',$run);
        self::assertNotSame($source,$target);
        self::assertSame(1,(int)$this->db->fetchOne('SELECT watched FROM movies WHERE id=?',[$source]));
        self::assertSame(0,(int)$this->db->fetchOne('SELECT watched FROM movies WHERE id=?',[$target]));
        self::assertSame($target,(int)$this->db->fetchOne('SELECT movie_id FROM file_content_links WHERE file_id=?',[$file]));
    }
    public function testImdbRejectsUnconfirmedAndHostSpoofUrls(): void
    {
        foreach (['https://imdb.com/find?q=example','https://www.imdb.com.evil/title/tt1234567/','http://imdb.com/title/tt1234567/','javascript:alert(1)'] as $url) {
            try { MatchWriter::imdb($url); self::fail('Unsafe URL accepted'); } catch (\InvalidArgumentException) {}
        }
        self::assertSame('tt1234567',MatchWriter::imdb('https://www.imdb.com/title/tt1234567/'));
    }
    public function testSpecialsMultiEpisodeLookupOnlyOwnedEpisodesAndCorrection(): void
    {
        $file=$this->file(); $writer=new MatchWriter($this->db);
        [$table,$id]=$writer->assignFile($file,'tv','Example Show',2020,0,[1,2]);
        $this->db->update('series',['manual_lock'=>0,'matching_state'=>'pending'],['id'=>$id]);
        $this->db->executeStatement('UPDATE episodes SET watched=1 WHERE episode_number=1');
        (new Credentials())->save(str_repeat('token',8));
        $run=self::getContainer()->get(Dispatcher::class)->dispatch($table,$id);
        $provider=new FixtureProvider();
        $provider->results=[['id'=>100,'name'=>'Example Show','first_air_date'=>'2020-01-01']];
        $provider->detail=['id'=>100,'name'=>'Example Show','first_air_date'=>'2020-01-01','external_ids'=>['imdb_id'=>'tt1234567']];
        ($this->handler($provider))(new Enrich($run));
        self::assertSame('completed',$this->db->fetchOne('SELECT state FROM runs WHERE id=?',[$run]));
        self::assertSame(2,(int)$this->db->fetchOne('SELECT COUNT(*) FROM episodes'));
        self::assertSame(2,(int)$this->db->fetchOne('SELECT COUNT(*) FROM file_content_links'));
        self::assertSame('Provider episode 1',$this->db->fetchOne('SELECT title FROM episodes WHERE episode_number=1'));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT watched FROM episodes WHERE episode_number=1'));
        self::assertSame([[100,0,1],[100,0,2]],$provider->episodeCalls);
        self::assertSame(3000,(int)$this->db->fetchOne('SELECT tmdb_id FROM seasons WHERE season_number=0'));
        // Correction changes catalog links only, retaining watched state for old episode identities.
        $writer->assignFile($file,'tv','Example Show',2020,1,[3]);
        self::assertSame(1,(int)$this->db->fetchOne('SELECT COUNT(*) FROM file_content_links'));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT watched FROM episodes WHERE episode_number=1'));
    }
    public function testQueuedJobCannotOverwriteNewManualEditOrCancelledRun(): void
    {
        (new Credentials())->save(str_repeat('token',8)); $id=$this->movie(); $dispatcher=self::getContainer()->get(Dispatcher::class);
        $run=$dispatcher->dispatch('movies',$id); (new MatchWriter($this->db))->edit('movies',$id,'Owner choice',2020,null);
        $provider=new FixtureProvider(); ($this->handler($provider))(new Enrich($run));
        self::assertSame('cancelled',$this->db->fetchOne('SELECT state FROM runs WHERE id=?',[$run]));
        self::assertSame('Owner choice',$this->db->fetchOne('SELECT title FROM movies WHERE id=?',[$id]));
        self::assertSame(0,$provider->searchCalls);
        (new MatchWriter($this->db))->rematch('movies',$id); $run=$dispatcher->dispatch('movies',$id);
        $this->db->update('runs',['cancel_requested'=>1],['id'=>$run]); ($this->handler($provider))(new Enrich($run));
        self::assertSame('cancelled',$this->db->fetchOne('SELECT state FROM runs WHERE id=?',[$run]));
    }

    public function testDiscoveryQueuesMetadataAndRescansKeepMergedManualIdentity(): void
    {
        (new Credentials())->save(str_repeat('token',8));
        $source=$this->movie('Local Example',null,1); $target=$this->movie('Example',100);
        (new MatchWriter($this->db))->apply('movies',$source,$this->data(),true,'en-US',$this->fixtureRun());
        $path=$_SERVER['APP_DATA_DIR'].'/phase4-source/Local.Example.2020.mkv';
        $this->db->insert('locations',['label'=>'Synthetic','root_path'=>dirname($path),'normalized_root'=>self::getContainer()->get(\App\Location\HostPath::class)->normalize(dirname($path)),'created_at'=>gmdate(DATE_ATOM)]);
        $location=$this->db->fetchAssociative('SELECT * FROM locations ORDER BY id DESC LIMIT 1');
        $run=bin2hex(random_bytes(16)); $this->db->insert('runs',['id'=>$run,'job_type'=>'discovery','trigger_name'=>'test','queued_at'=>gmdate(DATE_ATOM)]);
        $catalog=self::getContainer()->get(\App\Catalog\CatalogWriter::class);
        $record=['path'=>$path,'size'=>123,'mtime'=>123,'probe'=>null,'relative'=>basename($path)];
        $catalog->record($run,$location,$record);
        self::assertSame($target,(int)$this->db->fetchOne('SELECT movie_id FROM file_content_links'));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT COUNT(*) FROM movies'));
        $record['size']=124; $catalog->record($run,$location,$record);
        self::assertSame(1,(int)$this->db->fetchOne('SELECT manual_lock FROM movies WHERE id=?',[$target]));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT watched FROM movies WHERE id=?',[$target]));
        $record['path']=dirname($path).'/New.Movie.2021.mkv'; $record['relative']=basename($record['path']);
        $catalog->record($run,$location,$record);
        self::assertSame(1,(int)$this->db->fetchOne("SELECT COUNT(*) FROM runs WHERE job_type='enrichment' AND state='queued'"));
        self::assertSame('metadata',$this->db->fetchOne('SELECT queue_name FROM messenger_messages'));
    }
    public function testAmbiguousAndEmptySearchNeedOwnerReviewAndManualConfirmationLocks(): void
    {
        (new Credentials())->save(str_repeat('token',8));
        $id=$this->movie(); $dispatcher=self::getContainer()->get(Dispatcher::class); $provider=new FixtureProvider();
        $provider->results=[$this->data(),$this->data(101)]; $provider->detail=$this->data();
        $run=$dispatcher->dispatch('movies',$id); ($this->handler($provider))(new Enrich($run));
        self::assertSame('needs_review',$this->db->fetchOne('SELECT matching_state FROM movies WHERE id=?',[$id]));
        self::assertNull($this->db->fetchOne('SELECT tmdb_id FROM movies WHERE id=?',[$id]));
        self::assertSame(2,(int)$this->db->fetchOne('SELECT COUNT(*) FROM match_candidates'));
        $run=$dispatcher->dispatch('movies',$id,'confirm',null,100); ($this->handler($provider))(new Enrich($run));
        self::assertSame('manually_confirmed',$this->db->fetchOne('SELECT matching_state FROM movies WHERE id=?',[$id]));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT manual_lock FROM movies WHERE id=?',[$id]));
        (new MatchWriter($this->db))->rematch('movies',$id);
        $run=$dispatcher->dispatch('movies',$id,'rematch'); $provider->results=[];
        ($this->handler($provider))(new Enrich($run));
        self::assertSame('unmatched',$this->db->fetchOne('SELECT matching_state FROM movies WHERE id=?',[$id]));
        self::assertSame(100,(int)$this->db->fetchOne('SELECT tmdb_id FROM movies WHERE id=?',[$id]));
    }
    public function testEditingDuringLookupDiscardsStaleProviderResult(): void
    {
        (new Credentials())->save(str_repeat('token',8)); $id=$this->movie();
        $run=self::getContainer()->get(Dispatcher::class)->dispatch('movies',$id);
        $provider=new FixtureProvider(); $provider->results=[$this->data()]; $provider->detail=$this->data();
        $provider->onSearch=function () use ($id): void { (new MatchWriter($this->db))->edit('movies',$id,'New owner decision',2020,null); };
        ($this->handler($provider))(new Enrich($run));
        self::assertSame('cancelled',$this->db->fetchOne('SELECT state FROM runs WHERE id=?',[$run]));
        self::assertSame('New owner decision',$this->db->fetchOne('SELECT title FROM movies WHERE id=?',[$id]));
        self::assertNull($this->db->fetchOne('SELECT tmdb_id FROM movies WHERE id=?',[$id]));
    }
    public function testTitlePunctuationAllowedWithoutLeakingFullPaths(): void
    {
        foreach (['Face/Off','Star Wars: A New Hope'] as $query) { \App\Metadata\SearchQuery::validate($query); }
        foreach (['/private/media/Example.mkv','C:/private/media/Example.mkv','\\server\\private\\Example.mkv','https://host/private'] as $query) {
            try { \App\Metadata\SearchQuery::validate($query); self::fail('Path accepted'); } catch (\InvalidArgumentException) {}
        }
        self::assertNull(TmdbProvider::posterKey('/../../private'));
        self::assertNull(TmdbProvider::posterKey('https://host/image.jpg'));
    }

    public function testPosterCacheRejectsUnsafePathsAndOversizedDownloadsAndReusesOfflineCopy(): void
    {
        $saved=$_SERVER['APP_DATA_DIR']; $fixture=$saved.'/poster-fixture-'.bin2hex(random_bytes(4)); mkdir($fixture,0700,true);
        $_SERVER['APP_DATA_DIR']=$fixture;
        try {
            $client=new MockHttpClient(function ($method,$url,$options): MockResponse {
                self::assertSame('https://image.tmdb.org/t/p/w342/fixture.jpg',$url);
                self::assertSame(0,$options['max_redirects']);
                return new MockResponse("\xff\xd8\xfffixture");
            });
            $provider=new TmdbProvider($client,$this->db,new Credentials());
            $provider->cachePoster('/fixture.jpg'); $key=TmdbProvider::posterKey('/fixture.jpg');
            self::assertFileExists($fixture.'/posters/'.$key);
            $provider->cachePoster('/fixture.jpg'); $provider->cachePoster('/../private.jpg');
            self::assertSame(1,$client->getRequestsCount());
            $client=new MockHttpClient(new MockResponse(str_repeat('x',2*1024*1024+1)));
            (new TmdbProvider($client,$this->db,new Credentials()))->cachePoster('/oversize.jpg');
            self::assertFileDoesNotExist($fixture.'/posters/'.TmdbProvider::posterKey('/oversize.jpg'));
        } finally {
            foreach (glob($fixture.'/posters/*') ?: [] as $file) { unlink($file); }
            if (is_dir($fixture.'/posters')) { rmdir($fixture.'/posters'); } rmdir($fixture); $_SERVER['APP_DATA_DIR']=$saved;
        }
    }
    private function fixtureRun(): string { $id=bin2hex(random_bytes(16)); $this->db->insert('runs',['id'=>$id,'job_type'=>'enrichment','trigger_name'=>'test','state'=>'completed','queued_at'=>gmdate(DATE_ATOM)]); return $id; }
}
final class FixtureProvider implements Provider
{
    public $onSearch=null; public array $results=[]; public array $detail=[]; public bool $failure=false; public int $searchCalls=0; public array $episodeCalls=[];
    public function validate(): void {}
    public function search(string $type,string $query,string $language): array { ++$this->searchCalls; if ($this->onSearch) { ($this->onSearch)(); } if ($this->failure) { throw new ProviderFailure('Fixture unavailable.'); } return $this->results; }
    public function details(string $type,int $id,string $language): array { return $this->detail; }
    public function season(int $series,int $season,string $language): array { return ['id'=>3000+$season,'name'=>'Season '.$season,'episodes'=>[['episode_number'=>1],['episode_number'=>2],['episode_number'=>99]]]; }
    public function episode(int $series,int $season,int $episode,string $language): array { $this->episodeCalls[]=[$series,$season,$episode]; return ['id'=>1000+$episode,'season_number'=>$season,'episode_number'=>$episode,'name'=>'Provider episode '.$episode,'air_date'=>'2020-01-01','external_ids'=>['imdb_id'=>'tt7654321']]; }
    public function cachePoster(?string $path): void {}
}
