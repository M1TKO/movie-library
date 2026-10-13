<?php

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\Experience;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response};
use Symfony\Component\Routing\Attribute\Route;

final class TvController extends AbstractController
{
    public function __construct(private readonly \App\Host\HostActions $host) {}
    #[Route('/seasons/{id}', name: 'season_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function season(int $id, Connection $db, Experience $experience): Response
    {
        $season = $db->fetchAssociative('SELECT s.*,t.title series_title FROM seasons s JOIN series t ON t.id=s.series_id WHERE s.id=?', [$id]);
        if (!$season) {
            throw $this->createNotFoundException();
        }
        $episodes = $experience->episodes($id);
        $watched = count(array_filter($episodes, static fn(array $episode) => (bool) $episode['watched']));
        $state = !$watched ? 'Unwatched' : ($watched === count($episodes) ? 'Watched' : 'Partially watched');
        $totals = $experience->totals('SELECT f.* FROM media_files f WHERE EXISTS(SELECT 1 FROM file_content_links l JOIN episodes e ON e.id=l.episode_id WHERE l.file_id=f.id AND e.season_id=?)', [$id]);
        return $this->render('library/season.html.twig', compact('season', 'episodes', 'watched', 'state', 'totals'));
    }
    #[Route('/episodes/{id}', name: 'episode_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function episode(int $id, Request $request, Connection $db, Experience $experience, \App\Catalog\History $history): Response
    {
        $episode = $db->fetchAssociative('SELECT e.*,s.season_number,s.series_id,t.title series_title,t.imdb_id series_imdb FROM episodes e JOIN seasons s ON s.id=e.season_id JOIN series t ON t.id=s.series_id WHERE e.id=?', [$id]);
        if (!$episode) {
            throw $this->createNotFoundException();
        }
        $count = (int) $db->fetchOne('SELECT COUNT(*) FROM file_content_links WHERE episode_id=?', [$id]);
        $page = max(1, min($request->query->getInt('page', 1), max(1, (int) ceil($count / 50))));
        $files = $db->fetchAllAssociative('SELECT f.* FROM media_files f JOIN file_content_links l ON l.file_id=f.id WHERE l.episode_id=? ORDER BY f.id LIMIT 50 OFFSET ' . (($page - 1) * 50), [$id]);
        return $this->render('library/episode.html.twig', $history->page('episodes', $id, $request->query->getInt('history_page', 1)) + ['hostEnabled' => $this->host->enabled(),'episode' => $episode,'files' => $files,'page' => $page,'count' => $count,'totals' => $experience->totals('SELECT f.* FROM media_files f JOIN file_content_links l ON l.file_id=f.id WHERE l.episode_id=?',[$id])]);
    }
}
