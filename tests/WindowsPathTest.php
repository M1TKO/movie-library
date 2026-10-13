<?php

declare(strict_types=1);

namespace App\Tests;

use App\Location\WindowsPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WindowsPathTest extends TestCase
{
    public static function validPaths(): array
    {
        return [
            ['D:/Movies/./Classics/../', 'd:\\movies'],
            ['D:\\', 'd:\\'],
            ['\\\\NAS\\Media\\Movies\\', '\\\\nas\\media\\movies'],
            ['D:\\Филми и TV', 'd:\\филми и tv'],
        ];
    }
    #[DataProvider('validPaths')]
    public function testNormalizesWithoutAccessingTheFilesystem(string $input, string $expected): void
    {
        self::assertSame($expected, (new WindowsPath())->normalize($input));
    }
    public static function invalidPaths(): array
    {
        return array_map(fn($p) => [$p], ['relative', 'D:Movies', '\\\\server', 'D:\\..\\Movies', 'D:\\Movies:stream', 'D:\\Movies.', "D:\\bad\0path", '\\\\?\\D:\\Movies']);
    }
    #[DataProvider('invalidPaths')]
    public function testRejectsUnsafeOrAmbiguousPaths(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new WindowsPath())->normalize($path);
    }
    public function testOverlapUsesDirectoryBoundaries(): void
    {
        $paths = new WindowsPath();
        self::assertTrue($paths->overlaps('d:\\movies', 'd:\\movies\\classics'));
        self::assertFalse($paths->overlaps('d:\\movies', 'd:\\movies2'));
    }
}
