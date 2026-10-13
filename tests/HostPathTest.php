<?php

declare(strict_types=1);

namespace App\Tests;

use App\Location\{HostPath, WindowsPath};
use PHPUnit\Framework\TestCase;

final class HostPathTest extends TestCase
{
    public function testLinuxPreservesCaseAndLegalFilenameCharacters(): void
    {
        $paths = new HostPath(new WindowsPath(), 'Linux');
        self::assertSame('/mnt/Movies', $paths->normalize('/mnt//Movies/./'));
        self::assertNotSame($paths->normalize('/mnt/Movies'), $paths->normalize('/mnt/movies'));
        self::assertSame('/mnt/ Movies \\ special:* ', $paths->normalize('/mnt/ Movies \\ special:* '));
        self::assertSame('/', $paths->normalize('/'));
        self::assertTrue($paths->overlaps('/mnt/Movies', '/mnt/Movies/TV'));
        self::assertFalse($paths->overlaps('/mnt/Movies', '/mnt/Movies2'));
        self::assertFalse($paths->overlaps('/mnt/Movies', '/mnt/movies/TV'));
    }
    public function testLinuxRejectsWindowsPaths(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new HostPath(new WindowsPath(), 'Linux'))->normalize('D:\\Movies');
    }
    public function testLinuxRejectsParentTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new HostPath(new WindowsPath(), 'Linux'))->normalize('/mnt/link/../Movies');
    }
    public function testWindowsRetainsExistingIdentityRules(): void
    {
        $paths = new HostPath(new WindowsPath(), 'Windows');
        self::assertSame('d:\\movies', $paths->normalize('D:/Movies/'));
        self::assertTrue($paths->overlaps('d:\\movies', 'd:\\movies\\TV'));
    }
}
