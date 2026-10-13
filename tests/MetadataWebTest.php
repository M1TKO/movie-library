<?php

declare(strict_types=1);

namespace App\Tests;

use App\Metadata\{Credentials,Dispatcher,MatchWriter,TmdbProvider};
use App\Security\Owner;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Process\Process;

final class MetadataWebTest extends WebTestCase
{
    public function testAuthenticatedOfflineReviewCorrectionQueueAndWorker(): void
    {
        $client = self::createClient([], ['REMOTE_ADDR' => '127.0.0.1']);
        $db = self::getContainer()->get(Connection::class);
        foreach (['title_redirects','title_aliases','match_candidates','events','run_file_results','run_locations','file_content_links','file_locations','media_files','episodes','seasons','movies','series','runs','messenger_messages','worker_health','owner_auth'] as $table) {
            $db->executeStatement('DELETE FROM ' . $table);
        }
        (new Credentials())->remove();
        $db->insert('owner_auth', ['id' => 1,'password_hash' => password_hash('test-password-long', PASSWORD_DEFAULT),'created_at' => gmdate(DATE_ATOM)]);
        $client->loginUser(new Owner($db->fetchOne('SELECT password_hash FROM owner_auth')));
        $db->insert('media_files', ['original_path' => '/synthetic/video001.mkv','normalized_path' => '/synthetic/video001.mkv','filename' => 'video001.mkv','created_at' => gmdate(DATE_ATOM),'updated_at' => gmdate(DATE_ATOM)]);
        $file = (int) $db->lastInsertId();
        $client->request('GET', '/metadata/settings');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'TMDB settings');
        self::assertSelectorExists('img[src="/tmdb-logo.svg"]');
        $client->submitForm('Save metadata settings', ['language' => 'bg-BG']);
        self::assertSelectorTextContains('[role="status"]', 'saved');
        self::assertSelectorNotExists('input[value="sensitive-test-token"]');
        $client->request('POST', '/metadata/settings', ['action' => 'save','language' => 'en-US']);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/files/' . $file . '/assign');
        $client->submitForm('Save file assignment', ['type' => 'tv','title' => 'Example Show','year' => '2020','season' => '0','episodes' => '1 2']);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        $db = self::getContainer()->get(Connection::class);
        $series = (int) $db->fetchOne('SELECT id FROM series');
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM file_content_links'));
        $client->submitForm('Save local correction', ['title' => 'Owner show','year' => '2020','imdb' => 'https://www.imdb.com/title/tt1234567/']);
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Owner show');
        $client->request('GET', '/catalog/series/' . $series);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="https://www.imdb.com/title/tt1234567/"]');
        self::assertSelectorTextContains('.poster-placeholder', 'No poster available');
        $client->request('GET', '/matches/series/' . $series);
        $client->submitForm('Search candidates', ['query' => 'Owner show']);
        $client->followRedirect();
        $db = self::getContainer()->get(Connection::class);
        $run = $db->fetchOne("SELECT id FROM runs WHERE job_type='enrichment'");
        self::assertIsString($run);
        $client->request('GET', '/runs/' . $run);
        self::assertSelectorTextContains('h1', 'Metadata progress');
        self::assertSelectorTextContains('#run-progress', 'Metadata worker');
        // Real metadata worker consumes the durable message without touching files or using a real credential.
        $db->insert('runs', ['id' => str_repeat('b', 32),'job_type' => 'enrichment','trigger_name' => 'test','state' => 'running','queued_at' => gmdate(DATE_ATOM)]);
        $db->insert('runs', ['id' => str_repeat('c', 32),'job_type' => 'discovery','trigger_name' => 'test','state' => 'running','queued_at' => gmdate(DATE_ATOM)]);
        $worker = new Process([PHP_BINARY,'bin/console','messenger:consume','metadata','--limit=1','--time-limit=10','--no-interaction'], dirname(__DIR__), ['APP_ENV' => 'test','APP_DEBUG' => '0','APP_DATA_DIR' => $_SERVER['APP_DATA_DIR']], null, 20);
        $worker->mustRun();
        self::assertSame('interrupted', $db->fetchOne('SELECT state FROM runs WHERE id=?', [str_repeat('b', 32)]));
        self::assertSame('running', $db->fetchOne('SELECT state FROM runs WHERE id=?', [str_repeat('c', 32)]));
        self::assertSame('failed', $db->fetchOne('SELECT state FROM runs WHERE id=?', [$run]));
        self::assertSame('Owner show', $db->fetchOne('SELECT title FROM series WHERE id=?', [$series]));
        self::assertSame(1, (int) $db->fetchOne('SELECT manual_lock FROM series WHERE id=?', [$series]));
        $client->request('GET', '/matches');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'TMDB is not configured');
        $client->request('GET', '/posters/' . str_repeat('a', 64) . '.jpg');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/svg+xml');
        $client->request('GET', '/matches/series/' . $series);
        $client->submitForm('Explicitly reopen matching');
        $client->followRedirect();
        $db = self::getContainer()->get(Connection::class);
        self::assertSame(0, (int) $db->fetchOne('SELECT manual_lock FROM series WHERE id=?', [$series]));
        $db->insert('title_redirects', ['content_type' => 'series','source_id' => 9999999,'target_id' => $series]);
        $client->request('GET', '/matches/series/9999999');
        self::assertResponseRedirects('/matches/series/' . $series, 303);
        $client->request('GET', '/catalog/series/9999999');
        self::assertResponseRedirects('/catalog/series/' . $series);
        $client->request('POST', '/files/' . $file . '/assign', ['title' => 'Tampered']);
        self::assertResponseStatusCodeSame(403);
    }
    public function testAnonymousCannotAccessCredentialsReviewOrPosters(): void
    {
        $client = self::createClient();
        foreach (['/metadata/settings','/matches','/files/1/assign','/posters/' . str_repeat('a',64) . '.jpg'] as $path) {
            $client->request('GET',$path);
            self::assertResponseRedirects('/login');
        }
    }
}
