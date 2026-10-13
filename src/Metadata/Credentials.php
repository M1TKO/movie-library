<?php

declare(strict_types=1);

namespace App\Metadata;

/** Stored outside SQLite so catalog backups do not contain the credential. */
final class Credentials
{
    public function configured(): bool
    {
        return $this->read() !== '';
    }
    public function read(): string
    {
        $path = $_SERVER['APP_DATA_DIR'] . '/tmdb.secret';
        if (!is_file($path)) {
            return '';
        }
        $raw = base64_decode((string) file_get_contents($path), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        return openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16)) ?: '';
    }
    public function save(string $token): void
    {
        if (!preg_match('/^[A-Za-z0-9._-]{16,2048}$/D', $token)) {
            throw new \InvalidArgumentException('Enter the TMDB API Read Access Token from your account API settings.');
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($token, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Cannot protect the credential.');
        }
        $path = $_SERVER['APP_DATA_DIR'] . '/tmdb.secret';
        $temp = $path . '.' . bin2hex(random_bytes(8));
        if (file_put_contents($temp, base64_encode($iv . $tag . $cipher), LOCK_EX) === false) {
            throw new \RuntimeException('Cannot save the credential.');
        }
        chmod($temp, 0600);
        if (!rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException('Cannot save the credential.');
        }
    }
    public function remove(): void
    {
        $path = $_SERVER['APP_DATA_DIR'] . '/tmdb.secret';
        if (is_file($path)) {
            unlink($path);
        }
    }
    private function key(): string
    {
        return hash('sha256', $_SERVER['APP_SECRET'], true);
    }
}
