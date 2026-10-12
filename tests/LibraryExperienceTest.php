<?php
declare(strict_types=1);
namespace App\Tests;
use App\Catalog\{LibraryQuery, History};
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
final class LibraryExperienceTest extends KernelTestCase
{
    private Connection $db;
    private int $movie;
    protected function setUp(): void
    {
        self::bootKernel(); $this->db=self::getContainer()->get(Connection::class);
        foreach (['events','run_file_results','run_locations','file_content_links','file_locations','media_files','episodes','seasons','movies','series','runs','messenger_messages','locations'] as $table) { $this->db->executeStatement('DELETE FROM '.$table); }
        $now=gmdate(DATE_ATOM);
        $this->db->insert('movies',['title'=>'Example Movie','original_title'=>'Original Name','watched'=>1,'created_at'=>$now,'updated_at'=>$now]);
        $this->movie=(int)$this->db->lastInsertId();
        $this->db->insert('locations',['label'=>'Fixture','root_path'=>'/synthetic','normalized_root'=>'/synthetic','created_at'=>$now]); $location=(int)$this->db->lastInsertId();
        foreach (['a.mkv','b.mkv','c.mkv'] as $i=>$name) {
            $this->db->insert('media_files',['filename'=>$name,'original_path'=>'/synthetic/'.$name,'normalized_path'=>'/synthetic/'.$name,'size_bytes'=>100,'height'=>$i===0?720:1080,'check_outcome'=>$i===2?'missing':'available','created_at'=>$now,'updated_at'=>$now]);
            $file=(int)$this->db->lastInsertId();
            $this->db->insert('file_content_links',['file_id'=>$file,'movie_id'=>$this->movie]);
            if ($i===0) { $this->db->insert('file_locations',['file_id'=>$file,'location_id'=>$location,'relative_path'=>$name]); }
        }
    }
    public function testCombinedFiltersUseSameCopyAndCountDistinctPhysicalStorage(): void
    {
        $query=self::getContainer()->get(LibraryQuery::class);
        $result=$query->browse(Request::create('/?view=movies&q=Original&watched=watched&availability=available'));
        self::assertSame(1,$result['total']); self::assertSame(3,(int)$result['totals']['files']);
        self::assertSame(2,(int)$result['totals']['available']); self::assertSame(1,(int)$result['totals']['missing']); self::assertSame(0,(int)$result['totals']['uncheckable']);
        self::assertSame(300,(int)$result['totals']['bytes']); self::assertSame(200,(int)$result['totals']['available_bytes']);
        $location=$this->db->fetchOne('SELECT id FROM locations');
        self::assertSame(0,$query->browse(Request::create('/?view=movies&location='.$location.'&resolution=1080'))['total']);
        self::assertSame(1,$query->browse(Request::create('/?view=movies&location='.$location.'&resolution=720'))['total']);
        self::assertSame(0,$query->browse(Request::create('/?q=%25'))['total']);
        self::assertSame(3,$query->browse(Request::create('/?sort=invalid&direction=invalid'))['total']);
        self::assertSame(1,$query->browse(Request::create('/?page=99999'))['page']);
    }
    public function testStablePaginationAndPaginatedLinkedFileHistory(): void
    {
        $now=gmdate(DATE_ATOM); $file=$this->db->fetchOne('SELECT id FROM media_files LIMIT 1');
        for ($i=0;$i<55;++$i) {
            $this->db->insert('events',['event_type'=>'changed','file_id'=>$file,'occurred_at'=>$now,'path_snapshot'=>'/synthetic/a.mkv']);
            $this->db->insert('movies',['title'=>'Same title','created_at'=>$now,'updated_at'=>$now]);
        }
        $history=self::getContainer()->get(History::class);
        $first=$history->page('movies',$this->movie,1); $second=$history->page('movies',$this->movie,2);
        self::assertSame(55,$first['eventTotal']); self::assertCount(50,$first['events']); self::assertCount(5,$second['events']);
        self::assertEmpty(array_intersect(array_column($first['events'],'id'),array_column($second['events'],'id')));
        $query=self::getContainer()->get(LibraryQuery::class);
        $first=$query->browse(Request::create('/?view=movies&per_page=25')); $second=$query->browse(Request::create('/?view=movies&per_page=25&page=2'));
        self::assertCount(25,$first['rows']); self::assertCount(25,$second['rows']); self::assertEmpty(array_intersect(array_column($first['rows'],'id'),array_column($second['rows'],'id')));
    }
    public function testSeriesWatchedFilterUsesOnlyCatalogedEpisodesAndStorageCountsMultiEpisodeFileOnce(): void
    {
        $now=gmdate(DATE_ATOM);
        $this->db->insert('series',['title'=>'Show','created_at'=>$now,'updated_at'=>$now]); $series=(int)$this->db->lastInsertId();
        $this->db->insert('seasons',['series_id'=>$series,'season_number'=>1]); $season=(int)$this->db->lastInsertId();
        $file=$this->db->fetchOne('SELECT id FROM media_files LIMIT 1');
        for ($i=1;$i<=3;++$i) {
            $this->db->insert('episodes',['season_id'=>$season,'episode_number'=>$i,'title'=>'Episode','watched'=>$i===1?1:0,'created_at'=>$now,'updated_at'=>$now]);
            if ($i<3) { $this->db->insert('file_content_links',['file_id'=>$file,'episode_id'=>$this->db->lastInsertId()]); }
        }
        $query=self::getContainer()->get(LibraryQuery::class);
        $result=$query->browse(Request::create('/?view=series&watched=partial'));
        self::assertSame(1,$result['total']); self::assertSame(1,(int)$result['totals']['files']); self::assertSame(100,(int)$result['totals']['bytes']);
        $this->db->executeStatement('UPDATE episodes SET watched=1 WHERE episode_number=2');
        self::assertSame(1,$query->browse(Request::create('/?view=series&watched=watched'))['total']);
    }
}
