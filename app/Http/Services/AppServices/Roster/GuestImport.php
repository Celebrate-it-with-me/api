<?php

namespace App\Http\Services\AppServices\Roster;

use App\Http\Services\AppServices\CalculateAccessCodeService;
use App\Http\Services\AppServices\GuestLimit;
use App\Http\Services\Logger\EventActivityLogger;
use App\Models\Events;
use App\Models\Guest;
use App\Support\PhoneNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Import guests from a spreadsheet: read the file, show a preview with what is wrong, and create the guests
 * the organizer confirms. A "family" column groups rows: the first row of a family is the main guest and the
 * following rows are their companions. Without it, every row is a main guest.
 */
class GuestImport
{
    public const MAX_ROWS = 1000;

    /** Header names (lowercase, no accents) that mean each field, in Spanish and English. */
    private const HEADERS = [
        'name' => ['nombre', 'nombre completo', 'name', 'full name', 'invitado', 'guest'],
        'phone' => ['telefono', 'tel', 'celular', 'movil', 'whatsapp', 'phone', 'mobile', 'cell'],
        'email' => ['correo', 'correo electronico', 'email', 'e-mail', 'mail'],
        'family' => ['familia', 'grupo', 'family', 'group', 'household'],
    ];

    public function __construct(
        private readonly GuestLimit $limit,
        private readonly CalculateAccessCodeService $codes,
        private readonly GuestRoster $roster,
    ) {}

    /**
     * Read the file and judge every row. Nothing is saved.
     *
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int>, limit: array<string, mixed>}
     */
    public function preview(Events $event, UploadedFile $file): array
    {
        $table = $this->readTable($file);
        $columns = $this->mapColumns($table['header']);

        if (! isset($columns['name'])) {
            throw new ImportProblem('missing_name_column');
        }
        if (count($table['rows']) > self::MAX_ROWS) {
            throw new ImportProblem('too_many_rows');
        }

        $existing = Guest::query()->where('event_id', $event->id)->get(['phone', 'email']);
        $seenPhones = $existing->pluck('phone')->filter()->map(fn ($p) => PhoneNumber::normalize($p)['e164'] ?? $p)->flip()->all();
        $seenEmails = $existing->pluck('email')->filter()->map(fn ($e) => mb_strtolower($e))->flip()->all();

        $rows = [];
        foreach ($table['rows'] as $i => $cells) {
            $name = trim((string) ($cells[$columns['name']] ?? ''));
            $phoneRaw = isset($columns['phone']) ? trim((string) ($cells[$columns['phone']] ?? '')) : '';
            $email = isset($columns['email']) ? trim((string) ($cells[$columns['email']] ?? '')) : '';
            $family = isset($columns['family']) ? trim((string) ($cells[$columns['family']] ?? '')) : '';

            if ($name === '' && $phoneRaw === '' && $email === '' && $family === '') {
                continue; // a blank line in the sheet
            }

            $phone = PhoneNumber::normalize($phoneRaw);
            $problems = [];
            if ($name === '') {
                $problems[] = 'name_missing';
            }
            if ($phone['status'] === PhoneNumber::INVALID) {
                $problems[] = 'phone_invalid';
            }
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $problems[] = 'email_invalid';
            }

            $duplicate = false;
            if ($phone['e164'] && isset($seenPhones[$phone['e164']])) {
                $duplicate = true;
            }
            if ($email !== '' && isset($seenEmails[mb_strtolower($email)])) {
                $duplicate = true;
            }
            if ($phone['e164']) {
                $seenPhones[$phone['e164']] = true;
            }
            if ($email !== '') {
                $seenEmails[mb_strtolower($email)] = true;
            }

            $rows[] = [
                'line' => $i + 2, // spreadsheet line, counting the header
                'name' => $name,
                'phone' => $phone['e164'],
                'phoneRaw' => $phoneRaw,
                'phoneNote' => in_array($phone['status'], [PhoneNumber::ASSUMED_US, PhoneNumber::INVALID], true) ? $phone['status'] : null,
                'email' => $email !== '' ? $email : null,
                'family' => $family !== '' ? $family : null,
                'problems' => $problems,
                'duplicate' => $duplicate,
            ];
        }

        $people = count($rows);

        return [
            'rows' => $rows,
            'summary' => [
                'total' => $people,
                'ready' => count(array_filter($rows, fn ($r) => ! $r['problems'] && ! $r['duplicate'])),
                'withProblems' => count(array_filter($rows, fn ($r) => (bool) $r['problems'])),
                'duplicates' => count(array_filter($rows, fn ($r) => $r['duplicate'])),
                'assumedUs' => count(array_filter($rows, fn ($r) => $r['phoneNote'] === PhoneNumber::ASSUMED_US)),
                'hasFamilies' => (int) isset($columns['family']),
            ],
            'limit' => ['max' => $this->limit->limitFor($event), 'current' => $this->limit->totalFor($event)],
        ];
    }

    /**
     * Create the guests of the confirmed rows. Rows with problems or already on the list are skipped, and the
     * whole import is refused if it would go over the plan's limit once the event is published.
     *
     * @param  list<array<string, mixed>>  $rows  `{name, phone?, email?, family?}` as the preview gave them
     * @return array{created: int, families: int, skipped: int}
     */
    public function commit(Events $event, array $rows): array
    {
        $clean = [];
        $seenPhones = Guest::query()->where('event_id', $event->id)->whereNotNull('phone')->pluck('phone')->flip()->all();
        $skipped = 0;

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $phone = PhoneNumber::normalize($row['phone'] ?? null);
            $email = trim((string) ($row['email'] ?? ''));

            $bad = $name === '' || $phone['status'] === PhoneNumber::INVALID || ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL));
            $dup = $phone['e164'] && isset($seenPhones[$phone['e164']]);

            if ($bad || $dup) {
                $skipped++;

                continue;
            }
            if ($phone['e164']) {
                $seenPhones[$phone['e164']] = true;
            }

            $clean[] = ['name' => $name, 'phone' => $phone['e164'], 'email' => $email !== '' ? $email : null, 'family' => trim((string) ($row['family'] ?? ''))];
        }

        $this->limit->assertCanAdd($event, count($clean));

        $created = 0;
        $families = 0;

        DB::transaction(function () use ($event, $clean, &$created, &$families) {
            $mainByFamily = [];

            foreach ($clean as $row) {
                $key = $row['family'] !== '' ? mb_strtolower($row['family']) : null;
                $parentId = $key !== null ? ($mainByFamily[$key] ?? null) : null;

                $guest = Guest::query()->create([
                    'event_id' => $event->id,
                    'parent_id' => $parentId,
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'phone' => $row['phone'],
                    'rsvp_status' => 'pending',
                    // Only main guests get an access code (companions answer through the main guest).
                    'code' => $parentId === null ? $this->codes->generateForEvent($event) : null,
                ]);
                $created++;

                if ($parentId === null) {
                    $families++;
                    if ($key !== null) {
                        $mainByFamily[$key] = $guest->id;
                    }
                }
            }
        });

        $this->roster->forgetStats($event);

        EventActivityLogger::log($event->id, 'guests_imported', Auth::user(), $event, ['count' => $created, 'families' => $families]);

        return ['created' => $created, 'families' => $families, 'skipped' => $skipped];
    }

    /** A ready-to-fill CSV, with the headers in the event's language. */
    public function template(Events $event): string
    {
        $english = ($event->locale ?? 'es') === 'en';
        $rows = $english
            ? [['Name', 'Phone', 'Email', 'Family'], ['Ana Pérez', '+1 305 555 0101', 'ana@example.com', 'Pérez'], ['Luis Pérez', '', '', 'Pérez'], ['Marta Gómez', '(786) 555-0102', '', '']]
            : [['Nombre', 'Teléfono', 'Correo', 'Familia'], ['Ana Pérez', '+1 305 555 0101', 'ana@example.com', 'Pérez'], ['Luis Pérez', '', '', 'Pérez'], ['Marta Gómez', '(786) 555-0102', '', '']];

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        rewind($out);

        return stream_get_contents($out);
    }

    // ── Reading the file ─────────────────────────────────────

    /** @return array{header: list<string>, rows: list<list<string>>} */
    private function readTable(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $lines = in_array($extension, ['xlsx', 'xls'], true) ? $this->readSpreadsheet($file) : $this->readCsv($file);

        if (! $lines) {
            throw new ImportProblem('empty_file');
        }

        $header = array_map(fn ($cell) => (string) $cell, array_shift($lines));

        return ['header' => $header, 'rows' => array_values($lines)];
    }

    /** @return list<list<string>> */
    private function readSpreadsheet(UploadedFile $file): array
    {
        try {
            $sheet = IOFactory::load($file->getPathname())->getActiveSheet();
        } catch (\Throwable) {
            throw new ImportProblem('unreadable_file');
        }

        $lines = [];
        foreach ($sheet->toArray(null, true, true, false) as $row) {
            $lines[] = array_map(fn ($cell) => is_scalar($cell) ? (string) $cell : '', $row);
        }

        return $lines;
    }

    /** @return list<list<string>> */
    private function readCsv(UploadedFile $file): array
    {
        $content = (string) file_get_contents($file->getPathname());
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1'); // Excel in Spanish often saves like this
        }

        $firstLine = strtok($content, "\n") ?: '';
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $lines = [];
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $lines[] = array_map(fn ($cell) => (string) $cell, $row);
        }
        fclose($handle);

        return $lines;
    }

    /** @param list<string> $header @return array<string, int> field => column index */
    private function mapColumns(array $header): array
    {
        $map = [];
        foreach ($header as $index => $title) {
            $clean = $this->plain($title);
            foreach (self::HEADERS as $field => $names) {
                if (! isset($map[$field]) && in_array($clean, $names, true)) {
                    $map[$field] = $index;
                }
            }
        }

        return $map;
    }

    private function plain(string $text): string
    {
        $text = mb_strtolower(trim($text));

        return strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }
}
