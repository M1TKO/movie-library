<?php

declare(strict_types=1);

namespace App\Tests;

use App\Catalog\Probe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\{Process, ExecutableFinder};

final class ProbeTest extends TestCase
{
    public function testMainStreamExcludesArtworkAndPrefersDefaultAudioVideo(): void
    {
        $data = ['streams' => [
            ['codec_type' => 'video','width' => 4000,'height' => 4000,'disposition' => ['attached_pic' => 1,'default' => 1]],
            ['codec_type' => 'video','width' => 3840,'height' => 2160,'codec_name' => 'hevc'],
            ['codec_type' => 'video','width' => 1920,'height' => 800,'codec_name' => 'h264','avg_frame_rate' => '24000/1001','disposition' => ['default' => 1]],
            ['codec_type' => 'audio','codec_name' => 'ac3','channels' => 6],
            ['codec_type' => 'audio','codec_name' => 'aac','channels' => 2,'disposition' => ['default' => 1]],
        ],'format' => ['duration' => '120.25']];
        $result = (new Probe())->metadata($data);
        self::assertSame('complete', $result['probe_state']);
        self::assertSame(1920, $result['width']);
        self::assertSame(800, $result['height']);
        self::assertSame(120.25, $result['duration_seconds']);
        self::assertEqualsWithDelta(23.976, $result['frame_rate'], 0.001);
        self::assertSame('aac', $result['audio_codec']);
        self::assertSame(2, $result['audio_channels']);
    }
    public function testUnknownValuesAndArtworkOnly(): void
    {
        $result = (new Probe())->metadata(['streams' => [['codec_type' => 'video','avg_frame_rate' => '0/0']]]);
        self::assertNull($result['duration_seconds']);
        self::assertNull($result['frame_rate']);
        self::assertNull($result['width']);
        $this->expectException(\RuntimeException::class);
        (new Probe())->metadata(['streams' => [['codec_type' => 'video','disposition' => ['attached_pic' => 1]]]]);
    }
    public function testRealGeneratedVideoAndCorruptFile(): void
    {
        $finder = new ExecutableFinder();
        $probe = getenv('TEST_FFPROBE') ?: $finder->find('ffprobe');
        $encoder = getenv('TEST_FFMPEG') ?: $finder->find('ffmpeg');
        if (!$probe || !$encoder) {
            self::markTestSkipped('Install FFmpeg/FFprobe or set TEST_FFMPEG and TEST_FFPROBE for real inspection coverage.');
        }
        $root = $_SERVER['APP_DATA_DIR'] . '/probe-' . bin2hex(random_bytes(8));
        mkdir($root, 0700, true);
        $path = $root . '/Synthetic space & quote\' video.mkv';
        $corrupt = $root . '/corrupt.mp4';
        try {
            (new Process([$encoder,'-v','error','-f','lavfi','-i','color=c=black:s=160x90:r=24','-t','0.5','-c:v','mpeg4',$path], null, null, null, 20))->mustRun();
            $hash = hash_file('sha256', $path);
            $result = (new Probe())->inspect($path, $probe);
            self::assertSame('complete', $result['probe_state'], $result['probe_error'] ?? '');
            self::assertSame(160, $result['width']);
            self::assertSame(90, $result['height']);
            self::assertEqualsWithDelta(0.5, $result['duration_seconds'], 0.05);
            self::assertSame('mpeg4', $result['video_codec']);
            self::assertSame($hash, hash_file('sha256', $path));
            file_put_contents($corrupt, 'not a video');
            self::assertSame('failed', (new Probe())->inspect($corrupt, $probe)['probe_state']);
        } finally {
            if (is_file($path)) {
                unlink($path);
            } if (is_file($corrupt)) {
                unlink($corrupt);
            } rmdir($root);
        }
    }
}
