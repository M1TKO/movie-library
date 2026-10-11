<?php
declare(strict_types=1);
namespace App\Tests;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FoundationTest extends WebTestCase
{
    private function freshClient(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = self::createClient([], ['REMOTE_ADDR' => '127.0.0.1']);
        $db = self::getContainer()->get(Connection::class);
        $db->executeStatement('DELETE FROM locations');
        $db->executeStatement('DELETE FROM owner_auth');
        return $client;
    }
    public function testOwnerSetupLoginLocationsAndPersistence(): void
    {
        $client = $this->freshClient();
        $client->request('GET', '/setup');
        self::assertResponseIsSuccessful();
        $client->submitForm('Create owner account', ['password' => 'test-password-long', 'confirm' => 'test-password-long']);
        self::assertResponseRedirects('/login');
        $hash = self::getContainer()->get(Connection::class)->fetchOne('SELECT password_hash FROM owner_auth');
        self::assertNotSame('test-password-long', $hash);
        self::assertTrue(password_verify('test-password-long', $hash));
        $client->followRedirect();
        $client->submitForm('Sign in', ['_password' => 'wrong-password']);
        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'Sign-in failed');
        $client->submitForm('Sign in', ['_password' => 'test-password-long']);
        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Your library');
        $client->request('GET', '/locations');
        $moviePath = PHP_OS_FAMILY === 'Windows' ? 'Z:\\Movies' : '/mnt/offline/Movies';
        $duplicatePath = PHP_OS_FAMILY === 'Windows' ? 'z:/movies/' : '/mnt/offline/Movies/';
        $tvPath = PHP_OS_FAMILY === 'Windows' ? '\\\\offline-server\\TV' : '/mnt/offline-server/TV';
        $client->submitForm('Save location', ['label' => 'Offline movies', 'path' => $moviePath, 'hint' => 'movies']);
        self::assertResponseRedirects('/locations');
        $client->followRedirect();
        self::assertSelectorTextContains('code', $moviePath);
        $client->submitForm('Save location', ['label' => 'Duplicate', 'path' => $duplicatePath, 'hint' => 'mixed']);
        self::assertSelectorTextContains('[role="alert"]', 'already configured');
        $client->submitForm('Save location', ['label' => 'TV share', 'path' => $tvPath, 'hint' => 'tv', 'recursive' => false]);
        $client->followRedirect();
        self::assertSelectorCount(2, '.location');
        $client->submitForm('Disable');
        $client->followRedirect();
        self::assertSelectorTextContains('.location .badge', 'Disabled');
        $client->request('GET', '/setup');
        self::assertResponseRedirects('/login');
        // Rebooting the kernel on requests and a new DB connection both retain state.
        $db = new \PDO('sqlite:'.$_SERVER['DATABASE_PATH']);
        self::assertSame(2, (int) $db->query('SELECT COUNT(*) FROM locations')->fetchColumn());
        self::assertSame(0, (int) $db->query("SELECT recursive FROM locations WHERE label = 'TV share'")->fetchColumn());
        $client->request('GET', '/');
        $client->submitForm('Sign out');
        $client->request('GET', '/locations');
        self::assertResponseRedirects('/login');
    }
    public function testUnauthenticatedRequestsCannotReadOrChangeLocations(): void
    {
        $client = $this->freshClient();
        foreach (['/', '/locations', '/settings'] as $url) {
            $client->request('GET', $url);
            self::assertResponseRedirects('/login');
        }
        $client->request('POST', '/locations', ['label' => 'Blocked', 'path' => 'D:\\Movies']);
        self::assertResponseRedirects('/login');
        self::assertSame(0, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM locations'));
    }
    public function testSetupIsLocalOnlyAndCsrfProtected(): void
    {
        $client = $this->freshClient();
        $client->request('GET', '/setup', [], [], ['REMOTE_ADDR' => '192.168.1.25']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/setup', ['password' => 'test-password-long', 'confirm' => 'test-password-long']);
        self::assertSelectorTextContains('[role="alert"]', 'form expired');
        self::assertSame(0, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM owner_auth'));
    }
    public function testDatabasePolicyIsApplied(): void
    {
        $this->freshClient();
        $db = self::getContainer()->get(Connection::class);
        self::assertSame(1, (int) $db->fetchOne('PRAGMA foreign_keys'));
        self::assertSame(5000, (int) $db->fetchOne('PRAGMA busy_timeout'));
        self::assertSame('wal', $db->fetchOne('PRAGMA journal_mode'));
    }
    public function testAuthenticatedMutationsRejectMissingCsrf(): void
    {
        $client = $this->freshClient();
        $db = self::getContainer()->get(Connection::class);
        $db->insert('owner_auth', ['id' => 1, 'password_hash' => password_hash('fixture-password', PASSWORD_DEFAULT), 'created_at' => gmdate(DATE_ATOM)]);
        $client->loginUser(new \App\Security\Owner($db->fetchOne('SELECT password_hash FROM owner_auth')));
        $client->request('POST', '/locations', ['label' => 'Blocked', 'path' => 'D:\\Movies', 'hint' => 'movies']);
        self::assertSelectorTextContains('[role="alert"]', 'form expired');
        self::assertSame(0, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM locations'));
        $client->request('POST', '/locations/1/toggle');
        self::assertResponseStatusCodeSame(403);
    }
    public function testRejectsUnexpectedHostsAndSetsBrowserHeaders(): void
    {
        $client = $this->freshClient();
        $client->request('GET', '/setup');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        $client->request('GET', 'http://untrusted.example/setup');
        self::assertResponseStatusCodeSame(400);
    }
}
