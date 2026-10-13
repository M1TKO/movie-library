<?php

declare(strict_types=1);

namespace App\Metadata;

interface Provider
{
    public function validate(): void;
    public function search(string $type, string $query, string $language): array;
    public function details(string $type, int $id, string $language): array;
    public function season(int $series, int $season, string $language): array;
    public function episode(int $series, int $season, int $episode, string $language): array;
    public function cachePoster(?string $path): void;
}
