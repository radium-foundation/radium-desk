<?php

namespace App\Services\Operations\HostLoad;

use Illuminate\Support\Facades\File;

class CpuProcessSampleReader
{
    /**
     * @return array{
     *     load1: float,
     *     load5: float,
     *     load15: float,
     *     top_process: array{cpu: float, pid: int, command: string}|null
     * }|null
     */
    public function latestSample(): ?array
    {
        if (! (bool) config('host_load_watchdog.sampler.enabled', true)) {
            return null;
        }

        $directory = (string) config('host_load_watchdog.sampler.directory', storage_path('logs/cpu-process-samples'));
        $path = rtrim($directory, '/').'/'.now('Asia/Kolkata')->format('Y-m-d').'.tsv';

        if (! File::isFile($path)) {
            return null;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! is_array($lines) || $lines === []) {
            return null;
        }

        $line = (string) end($lines);
        if ($line === '' || str_starts_with($line, 'ts_ist')) {
            return null;
        }

        return $this->parseLine($line);
    }

    /**
     * @return array{
     *     load1: float,
     *     load5: float,
     *     load15: float,
     *     top_process: array{cpu: float, pid: int, command: string}|null
     * }|null
     */
    private function parseLine(string $line): ?array
    {
        $parts = explode("\t", $line);
        if (count($parts) < 5) {
            return null;
        }

        $topProcess = null;
        $topFiveIndex = count($parts) - 2;
        if ($topFiveIndex >= 0 && ($parts[$topFiveIndex] ?? '') !== '') {
            $topProcess = $this->parseTopProcess((string) $parts[$topFiveIndex]);
        }

        return [
            'load1' => round((float) ($parts[2] ?? 0.0), 2),
            'load5' => round((float) ($parts[3] ?? 0.0), 2),
            'load15' => round((float) ($parts[4] ?? 0.0), 2),
            'top_process' => $topProcess,
        ];
    }

    /**
     * @return array{cpu: float, pid: int, command: string}|null
     */
    private function parseTopProcess(string $topFive): ?array
    {
        $first = explode('|', $topFive)[0] ?? '';
        if ($first === '') {
            return null;
        }

        $segments = explode(',', $first, 3);
        if (count($segments) < 3) {
            return null;
        }

        return [
            'cpu' => round((float) $segments[0], 1),
            'pid' => (int) $segments[1],
            'command' => $this->sanitizeCommand((string) $segments[2]),
        ];
    }

    private function sanitizeCommand(string $command): string
    {
        $command = trim(preg_replace('/\s+/', ' ', $command) ?? $command);

        if (strlen($command) > 160) {
            return substr($command, 0, 160);
        }

        return $command;
    }
}
