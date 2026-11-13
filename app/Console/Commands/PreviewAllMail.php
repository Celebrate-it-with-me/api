<?php

namespace App\Console\Commands;

use App\Support\Mail\MailPreviewRenderer;
use App\Support\Mail\MailRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PreviewAllMail extends Command
{
    protected $signature = 'mail:preview-all {--path= : Output folder (default storage/app/mail-previews, outside git)}';

    protected $description = 'Render every registered mail in each of its languages to HTML files and fail if one is broken';

    public function handle(): int
    {
        $path = rtrim($this->option('path') ?: storage_path('app/mail-previews'), '/');
        File::deleteDirectory($path);
        $failures = 0;
        $rows = [];

        foreach (MailRegistry::all() as $definition) {
            if (! $definition->hasMail()) {
                continue;
            }
            foreach ($definition->langs as $lang) {
                try {
                    $out = MailPreviewRenderer::render($definition, $lang);
                } catch (\Throwable $e) {
                    $failures++;
                    $rows[] = [$definition->key, $lang, 'ERROR', $e->getMessage()];

                    continue;
                }
                File::ensureDirectoryExists("{$path}/{$lang}");
                File::put("{$path}/{$lang}/{$definition->key}.html", $out['html']);
                $failures += $out['problems'] ? 1 : 0;
                $rows[] = [$definition->key, $lang, $out['problems'] ? 'FAIL' : 'ok', implode('; ', $out['problems']) ?: $out['subject']];
            }
        }

        $this->table(['Mail', 'Lang', 'Status', 'Subject / problems'], $rows);
        $this->line("Files in {$path}");

        if ($failures) {
            $this->error("{$failures} mail(s) failed.");

            return self::FAILURE;
        }
        $this->info('All mails render.');

        return self::SUCCESS;
    }
}
