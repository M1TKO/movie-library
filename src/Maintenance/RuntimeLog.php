<?php

declare(strict_types=1);

namespace App\Maintenance;

/** Line buffering prevents secrets split across subprocess chunks from escaping redaction. */
final class RuntimeLog
{
    private string $pending = '';
    public function __construct(private readonly string $path, private readonly array $secrets = [], private readonly int $limit = 5242880) {}
    public function append(string $chunk): void
    {
        $this->pending .= $chunk;
        while (($end = strpos($this->pending, "\n")) !== false) {
            $line = substr($this->pending, 0, $end + 1);
            $this->pending = substr($this->pending, $end + 1);
            $this->write($line);
        }
        if (strlen($this->pending) > 65536) {
            $this->pending = '';
            $this->write("[oversized diagnostic line omitted]\n");
        }
    }
    public function flush(): void
    {
        if ($this->pending !== '') {
            $this->write($this->pending . "\n");
            $this->pending = '';
        }
    }
    private function write(string $line): void
    {
        foreach ($this->secrets as $secret) {
            if ($secret !== '') {
                $line = str_replace($secret, '[redacted]', $line);
            }
        }
        $line = preg_replace('/(Bearer\s+)[A-Za-z0-9._-]+/i', '$1[redacted]', $line);
        $line = preg_replace('/((?:api_key|password|APP_SECRET|session|token)[=:]\s*)[^\s,;"}]+/i', '$1[redacted]', $line);
        clearstatcache(true, $this->path);
        if (is_file($this->path) && filesize($this->path) + strlen($line) > $this->limit) {
            if (is_file($this->path . '.3')) {
                unlink($this->path . '.3');
            }
            for ($i = 2;$i >= 1;--$i) {
                if (is_file($this->path . '.' . $i)) {
                    rename($this->path . '.' . $i, $this->path . '.' . ($i + 1));
                }
            }
            rename($this->path, $this->path . '.1');
        }
        if (file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write runtime log.');
        }
        chmod($this->path,0600);
    }
}
