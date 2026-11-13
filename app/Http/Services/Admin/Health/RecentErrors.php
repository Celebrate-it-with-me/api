<?php

namespace App\Http\Services\Admin\Health;

use Illuminate\Support\Carbon;

/**
 * The latest error-level entries of the Laravel log, one sanitised line each (see Redactor).
 * Only the tail of the newest log file is read, so a huge log costs the same as a small one.
 */
class RecentErrors
{
    private const LEVELS = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    private const TAIL_BYTES = 524288;

    public function report(int $limit = 20): array
    {
        $limit = max(1, min($limit, 50));
        $file = $this->newestLog();

        if ($file === null) {
            return ['available' => false, 'file' => null, 'items' => []];
        }

        $text = $this->tail($file);
        preg_match_all('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:\d{2})?)\] [\w.\-]+\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.*)$/m', $text, $matches, PREG_SET_ORDER);

        $groups = [];
        foreach (array_reverse($matches) as $m) {
            $summary = Redactor::summary($m[3]);
            $key = $m[2] . '|' . $summary;
            if (isset($groups[$key])) {
                $groups[$key]['count']++;

                continue;
            }
            if (count($groups) >= $limit) {
                continue;
            }
            $groups[$key] = [
                'level' => strtolower($m[2]),
                'at' => Carbon::parse($m[1])->toIso8601String(),
                'message' => $summary,
                'count' => 1,
            ];
        }

        return ['available' => true, 'file' => basename($file), 'items' => array_values($groups)];
    }

    private function newestLog(): ?string
    {
        $files = glob(storage_path('logs/laravel*.log')) ?: [];
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $files[0] ?? null;
    }

    private function tail(string $file): string
    {
        $size = filesize($file) ?: 0;
        $h = fopen($file, 'rb');
        if (! $h) {
            return '';
        }
        fseek($h, max(0, $size - self::TAIL_BYTES));
        $text = (string) stream_get_contents($h);
        fclose($h);

        return $text;
    }
}
