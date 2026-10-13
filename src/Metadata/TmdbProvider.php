<?php

declare(strict_types=1);

namespace App\Metadata;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TmdbProvider implements Provider
{
    private const CACHE_TTL = 604800;
    private const CACHE_LIMIT = 2000;
    private const MAX_RESPONSE_BYTES = 2097152;
    private float $lastRequest = 0;
    public function __construct(private readonly HttpClientInterface $http, private readonly Connection $db, private readonly Credentials $credentials) {}
    public function validate(): void
    {
        $this->request('authentication', [], false);
    }
    public function search(string $type, string $query, string $language): array
    {
        $this->type($type);
        $query = trim($query);
        SearchQuery::validate($query);
        return $this->request('search/' . $type, ['query' => $query,'language' => $language,'include_adult' => 'false'])['results'] ?? [];
    }
    public function details(string $type, int $id, string $language): array
    {
        $this->type($type);
        $this->id($id);
        return $this->request($type . '/' . $id, ['language' => $language,'append_to_response' => 'external_ids']);
    }
    public function season(int $series, int $season, string $language): array
    {
        $this->id($series);
        if ($season < 0 || $season > 999) {
            throw new \InvalidArgumentException('Invalid season.');
        }
        return $this->request("tv/$series/season/$season", ['language' => $language]);
    }
    public function episode(int $series, int $season, int $episode, string $language): array
    {
        $this->id($series);
        if ($season < 0 || $season > 999 || $episode < 0 || $episode > 9999) {
            throw new \InvalidArgumentException('Invalid episode.');
        }
        return $this->request("tv/$series/season/$season/episode/$episode", ['language' => $language,'append_to_response' => 'external_ids']);
    }
    private function request(string $path, array $query, bool $cache = true): array
    {
        ksort($query);
        $identity = hash('sha256', $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $row = $cache ? $this->db->fetchAssociative("SELECT payload FROM metadata_cache WHERE provider='tmdb' AND request_identity=? AND cache_state='complete' AND expires_at > ?", [$identity, gmdate(DATE_ATOM)]) : false;
        if ($row) {
            try {
                $data = json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR);
                if (is_array($data)) {
                    return $data;
                }
            } catch (\JsonException) {
                $this->db->executeStatement("DELETE FROM metadata_cache WHERE provider='tmdb' AND request_identity=?", [$identity]);
            }
        }
        $token = $this->credentials->read();
        if ($token === '') {
            throw new ProviderFailure('TMDB is not configured. Local catalog data remains available.');
        }
        for ($attempt = 0;$attempt < 3;++$attempt) {
            $pause = 0.25 - (microtime(true) - $this->lastRequest);
            if ($pause > 0) {
                usleep((int) ($pause * 1000000));
            }
            $this->lastRequest = microtime(true);
            try {
                $response = $this->http->request('GET', 'https://api.themoviedb.org/3/' . $path, [
                    'headers' => ['Authorization' => 'Bearer ' . $token,'Accept' => 'application/json'],'query' => $query,
                    'timeout' => 5,'max_duration' => 10,'max_redirects' => 0,'buffer' => false]);
                $status = $response->getStatusCode();
                if ($status === 429 || $status >= 500) {
                    $retry = $response->getHeaders(false)['retry-after'][0] ?? '';
                    $response->cancel();
                    $wait = ctype_digit($retry) ? (int) $retry : (strtotime($retry) ?: time()) - time();
                    if ($wait > 5 || $attempt === 2) {
                        throw new ProviderFailure('TMDB is temporarily unavailable or rate limited. Retry later.');
                    }
                    usleep((int) (max(2 ** $attempt, $wait) * 1000000));
                    continue;
                }
                if ($status !== 200) {
                    $response->cancel();
                    throw new ProviderFailure(in_array($status, [401,403], true) ? 'TMDB rejected the credential. Check Settings.' : 'TMDB could not supply this metadata.');
                }
                $body = '';
                foreach ($this->http->stream($response) as $chunk) {
                    $body .= $chunk->getContent();
                    if (strlen($body) > self::MAX_RESPONSE_BYTES) {
                        $response->cancel();
                        throw new ProviderFailure('TMDB response exceeded the size limit.');
                    }
                }
                $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($data)) {
                    throw new ProviderFailure('TMDB returned invalid metadata.');
                }
                if ($cache) {
                    $this->db->executeStatement("INSERT INTO metadata_cache(provider,request_identity,payload,fetched_at,expires_at,cache_state) VALUES('tmdb',?,?,?,?, 'complete') ON CONFLICT(provider,request_identity) DO UPDATE SET payload=excluded.payload,fetched_at=excluded.fetched_at,expires_at=excluded.expires_at,cache_state=excluded.cache_state", [$identity,$body,gmdate(DATE_ATOM),gmdate(DATE_ATOM, time() + self::CACHE_TTL)]);
                    $this->db->executeStatement("DELETE FROM metadata_cache WHERE provider='tmdb' AND request_identity NOT IN (SELECT request_identity FROM metadata_cache WHERE provider='tmdb' ORDER BY fetched_at DESC,request_identity LIMIT " . self::CACHE_LIMIT . ")");
                }
                return $data;
            } catch (ProviderFailure $e) {
                throw $e;
            } catch (\Throwable) {
                if ($attempt === 2) {
                    throw new ProviderFailure('TMDB request failed. Check the connection and retry later.');
                }
                usleep((2 ** $attempt) * 1000000);
            }
        }
        throw new ProviderFailure('TMDB request failed.');
    }
    public static function posterKey(?string $path): ?string
    {
        return $path && preg_match('~^/[A-Za-z0-9_-]+\\.jpg$~D', $path) ? hash('sha256', $path) . '.jpg' : null;
    }
    public function cachePoster(?string $path): void
    {
        $key = self::posterKey($path);
        if (!$key) {
            return;
        }
        $dir = $_SERVER['APP_DATA_DIR'] . '/posters';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        if (is_file($dir . '/' . $key)) {
            return;
        }
        try {
            $response = $this->http->request('GET', 'https://image.tmdb.org/t/p/w342' . $path, ['timeout' => 5,'max_duration' => 10,'max_redirects' => 0,'buffer' => false]);
            if ($response->getStatusCode() !== 200) {
                $response->cancel();
                return;
            }
            $body = '';
            foreach ($this->http->stream($response) as $chunk) {
                $body .= $chunk->getContent();
                if (strlen($body) > 2 * 1024 * 1024) {
                    $response->cancel();
                    return;
                }
            }
            if (!str_starts_with($body, "\xff\xd8\xff")) {
                return;
            }
            $files = glob($dir . '/*.jpg') ?: [];
            $total = 0;
            usort($files, fn($a, $b) => filemtime($a) <=> filemtime($b));
            foreach ($files as $file) {
                $total += filesize($file);
            }
            foreach ($files as $file) {
                if ($total + strlen($body) <= max(10, min(1024, (int) ($this->db->fetchOne("SELECT value FROM settings WHERE name='poster_cache_mib'") ?: 100))) * 1024 * 1024) {
                    break;
                } $total -= filesize($file);
                unlink($file);
            }
            $temp = $dir . '/' . $key . '.' . bin2hex(random_bytes(4));
            file_put_contents($temp, $body, LOCK_EX);
            chmod($temp, 0600);
            rename($temp, $dir . '/' . $key);
        } catch (\Throwable) { /* Missing images never invalidate a match. */
        }
    }
    private function type(string $type): void
    {
        if (!in_array($type, ['movie','tv'], true)) {
            throw new \InvalidArgumentException('Invalid content type.');
        }
    }
    private function id(int $id): void
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Invalid TMDB identity.');
        }
    }
}
