<?php

declare(strict_types=1);

namespace App\Tests;

use App\Catalog\FilenameParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FilenameParserTest extends TestCase
{
    #[DataProvider('names')]
    public function testLocalCandidates(string $path, string $type, ?string $title, ?int $year, ?int $season, array $episodes): void
    {
        self::assertSame(['type' => $type,'title' => $title,'year' => $year,'season' => $season,'episodes' => $episodes], (new FilenameParser())->parse($path));
    }
    public static function names(): iterable
    {
        yield ['Example.Movie.2020.1080p.BluRay.x264-GROUP.mkv','movie','Example Movie',2020,null,[]];
        yield ['Example.Show.S01E03.720p.mkv','tv','Example Show',null,1,[3]];
        yield ['Example.Show.1x03.mp4','tv','Example Show',null,1,[3]];
        yield ['Example.Show.S01E01E02.mkv','tv','Example Show',null,1,[1,2]];
        yield ['Example.Show.S01E01-E03.mkv','tv','Example Show',null,1,[1,2,3]];
        yield ['Example.Show.S00E01.mkv','tv','Example Show',null,0,[1]];
        yield ['Example Show/Season 01/S01E03.mkv','tv','Example Show',null,1,[3]];
        yield ['Example Movie (2020)/video.mkv','movie','Example Movie',2020,null,[]];
        yield ['video001.mkv','unresolved',null,null,null,[]];
        yield ['Media/video001.mkv','unresolved',null,null,null,[]];
        yield ['1917.2019.1080p.mkv','movie','1917',2019,null,[]];
        yield ['2001 A Space Odyssey.1968.1080p.mkv','movie','2001 A Space Odyssey',1968,null,[]];
        yield ['1917.mkv','movie','1917',null,null,[]];
        yield ['Amélie.2001.1080p.mkv','movie','Amélie',2001,null,[]];
        yield ['Пример.Филм.2020.WEB-DL.mkv','movie','Пример Филм',2020,null,[]];
        yield ["A Film's Title.mkv",'movie',"A Film's Title",null,null,[]];
        yield ['Show.2020.01.23.mkv','unresolved',null,null,null,[]];
        yield ['Show.S01E20-E01.mkv','unresolved',null,null,null,[]];
    }
    public function testTvHintDoesNotGuessAbsoluteEpisodeNumbers(): void
    {
        self::assertSame('unresolved', (new FilenameParser())->parse('Show 123.mkv', 'tv')['type']);
    }
}
