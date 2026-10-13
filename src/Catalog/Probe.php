<?php

declare(strict_types=1);

namespace App\Catalog;

use Symfony\Component\Process\Process;

final class Probe
{
    public function start(string $path, string $executable): array
    {
        $process = new Process([$executable,'-v','error','-protocol_whitelist','file','-show_streams','-show_format','-of','json',$path], null, null, null, 10);
        $buffer = (object) ['output' => ''];
        $process->start(static function (string $type, string $chunk) use ($buffer): void {
            if ($type === Process::OUT) {
                $buffer->output .= $chunk;
            }
            if (strlen($buffer->output) > 1024 * 1024) {
                throw new \RuntimeException('Inspection output exceeded the limit.');
            }
        });
        return [$process,$buffer];
    }
    public function finish(array $task): array
    {
        [$process,$buffer] = $task;
        try {
            $process->wait();
            if (!$process->isSuccessful()) {
                throw new \RuntimeException('Probe failed.');
            }
            return $this->metadata(json_decode($buffer->output, true, 64, JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            return ['probe_state' => 'failed','probe_error' => $e instanceof \Symfony\Component\Process\Exception\ProcessTimedOutException ? 'FFprobe exceeded its 10 second timeout.' : 'Inspection failed. Check the FFprobe executable and file format.','probed_at' => gmdate(DATE_ATOM)];
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }
    public function inspect(string $path, string $executable): array
    {
        try {
            return $this->finish($this->start($path, $executable));
        } catch (\Throwable) {
            return ['probe_state' => 'failed','probe_error' => 'Inspection failed. Check the FFprobe executable and file format.','probed_at' => gmdate(DATE_ATOM)];
        }
    }

    public function metadata(array $data): array
    {
        $videos = array_values(array_filter($data['streams'] ?? [], static fn(array $s) => ($s['codec_type'] ?? '') === 'video' && empty($s['disposition']['attached_pic'])));
        usort($videos, static fn(array $a, array $b) => [($b['disposition']['default'] ?? 0), ($b['width'] ?? 0) * ($b['height'] ?? 0)] <=> [($a['disposition']['default'] ?? 0), ($a['width'] ?? 0) * ($a['height'] ?? 0)]);
        if (!$videos) {
            throw new \RuntimeException('No primary video stream was found.');
        }
        $video = $videos[0];
        $audio = array_values(array_filter($data['streams'] ?? [], static fn(array $s) => ($s['codec_type'] ?? '') === 'audio'));
        usort($audio, static fn(array $a, array $b) => ($b['disposition']['default'] ?? 0) <=> ($a['disposition']['default'] ?? 0));
        $rate = explode('/', (string) ($video['avg_frame_rate'] ?? '0/0'));
        $duration = $data['format']['duration'] ?? $video['duration'] ?? null;
        return ['probe_state' => 'complete','probe_error' => null,'probed_at' => gmdate(DATE_ATOM),
            'width' => $video['width'] ?? null,'height' => $video['height'] ?? null,
            'duration_seconds' => is_numeric($duration) && (float) $duration >= 0 ? (float) $duration : null,
            'video_codec' => $video['codec_name'] ?? null,'frame_rate' => count($rate) === 2 && (float) $rate[1] > 0 ? (float) $rate[0] / (float) $rate[1] : null,
            'audio_codec' => $audio[0]['codec_name'] ?? null,'audio_channels' => $audio[0]['channels'] ?? null];
    }
}
