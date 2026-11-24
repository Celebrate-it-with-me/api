<?php

namespace App\Console\Commands;

use App\Http\Services\Admin\Templates\Packages\ClassicSource;
use Illuminate\Console\Command;

/** The switch `invitation.classic_source` (free-cover-composition-contract 6.2, C2): native (default) or package. */
class ClassicSourceCommand extends Command
{
    protected $signature = 'templates:classic-source
        {mode : native, package or status}
        {--actor= : Email of the staff member who runs it (goes to the audit log; the operating system user is always recorded)}
        {--reason= : Why (5 to 500 characters, goes to the audit log); required to change}';

    protected $description = 'Show or change where the free Classic invitation is drawn from: the native engine or the classic package';

    public function handle(ClassicSource $classic): int
    {
        $mode = (string) $this->argument('mode');
        if ($mode === 'status') {
            $this->line('classic_source = ' . $classic->current());
            $this->line('Eventos publicados sin versión fijada (siguen el origen): ' . $classic->followingPublished());

            return self::SUCCESS;
        }
        if (! in_array($mode, [ClassicSource::NATIVE, ClassicSource::PACKAGE], true)) {
            $this->error('Usa native, package o status.');

            return self::INVALID;
        }
        $reason = trim((string) $this->option('reason'));
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            $this->error('Indica --reason="..." (entre 5 y 500 caracteres).');

            return self::INVALID;
        }

        try {
            $actor = ClassicSource::actor($this->option('actor'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        try {
            $result = $classic->switchTo($mode, $reason, $actor);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($result['changed']
            ? "classic_source: {$result['from']} -> {$result['to']}. Los eventos con versión fijada no cambian; cambian los borradores y {$result['following']} evento(s) publicado(s) sin versión fijada (etapa Save the date)."
            : "classic_source ya era {$result['to']}: sin cambios.");

        return self::SUCCESS;
    }
}
