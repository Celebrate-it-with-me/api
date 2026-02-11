<?php

namespace App\Console\Commands;

use App\Http\Services\AppServices\CalculateAccessCodeService;
use App\Models\Events;
use App\Models\Guest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Random\RandomException;

class ImportGuestFromExcel extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cwm:import-guest
        {--event= : Event Id to assign guests}
        {--file= : Excel file to import}
        {--dry-run : Perform a dry run without making changes}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import guests from an XLSX file into guests table using Families column to group companions.';
    
    /**
     * Execute the console command.
     * @throws RandomException
     * @throws \Throwable
     */
    public function handle(CalculateAccessCodeService $accessCodeService)
    {
        $eventId = (int)$this->option('event');
        $filePath = (string)$this->option('file');
        $dryRun = (bool)$this->option('dry-run');
        
        if ($eventId <= 0) {
            $this->error('Missing or invalid --event option.');
            return self::FAILURE;
        }
        
        $event = Events::findOrFail($eventId);
        
        if ($filePath === '' || !file_exists($filePath)) {
            $this->error('Missing or invalid --file option.');
            return self::FAILURE;
        }
        
        $this->info("Importing guests for event_id={$eventId}");
        $this->info("File: {$filePath}");
        $this->info($dryRun ? 'Mode: DRY RUN (no DB writes)' : 'Mode: WRITE');
        
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        
        // Read header row and map columns.
        $highestColumn = $sheet->getHighestColumn();
        $highestRow = (int) $sheet->getHighestRow();
        
        $header = $sheet->rangeToArray("A1:{$highestColumn}1", null, true, false)[0];
        $colIndex = $this->mapHeaderColumns($header);
        
        foreach (['guest_name', 'families', 'gender'] as $required) {
            if (!isset($colIndex[$required])) {
                $this->error("Missing required column in header: {$required}");
                return self::FAILURE;
            }
        }
        
        $inserted = 0;
        $skipped = 0;
        
        $currentParentGuestId = null;
        $currentParentName = null;
        
        DB::beginTransaction();
        
        try {
            for ($row = 2; $row <= $highestRow; $row++) {
                
                $rowValues = $sheet->rangeToArray("A{$row}:{$highestColumn}{$row}", null, true, false)[0];
                
                $nameRaw = trim((string) ($rowValues[$colIndex['guest_name']] ?? ''));
                if ($nameRaw === '') {
                    $skipped++;
                    continue;
                }
                
                $familiesRaw = trim((string) ($rowValues[$colIndex['families']] ?? ''));
                $genderRaw = trim((string) ($rowValues[$colIndex['gender']] ?? ''));
                
                $isPrincipal = $this->isNumericNonEmpty($familiesRaw);
                $gender = $this->normalizeGender($genderRaw);
                
                if ($isPrincipal) {
                    // Principal guest
                    $code = $accessCodeService->generateForEvent($event);
                    $payload = [
                        'event_id' => $eventId,
                        'parent_id' => null,
                        'name' => $this->normalizeName($nameRaw),
                        'gender' => $gender,
                        'code' => $code,
                    ];
                    
                    if ($dryRun) {
                        $this->line("[PRINCIPAL] {$payload['name']} (gender=" . ($gender ?? 'null') . ")");
                        $currentParentGuestId = null; // placeholder in dry run
                        $currentParentName = $payload['name'];
                        $inserted++;
                        continue;
                    }
                    
                    $guest = Guest::create($payload);
                    
                    $currentParentGuestId = $guest->id;
                    $currentParentName = $guest->name;
                    
                    $this->line("[PRINCIPAL] {$guest->name} (id={$guest->id})");
                    $inserted++;
                    continue;
                }
                
                // Companion
                if ($currentParentGuestId === null) {
                    // No parent found yet -> treat as principal OR skip. I recommend skip with warning.
                    $this->warn("[SKIP] Row {$row}: Companion without previous principal. Name={$nameRaw}");
                    $skipped++;
                    continue;
                }
                
                $payload = [
                    'event_id' => $eventId,
                    'parent_id' => $dryRun ? null : $currentParentGuestId, // dry run doesn't have IDs
                    'name' => $this->normalizeName($nameRaw),
                    'gender' => $gender,
                    'code' => null,
                ];
                
                if ($dryRun) {
                    $this->line("  [COMPANION of {$currentParentName}] {$payload['name']} (gender=" . ($gender ?? 'null') . ")");
                    $inserted++;
                    continue;
                }
                
                $guest = Guest::create($payload);
                
                $this->line("  [COMPANION of {$currentParentName}] {$guest->name} (id={$guest->id})");
                $inserted++;
            }
            
            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
            
            $this->info("Done. Inserted={$inserted}, Skipped={$skipped}");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error("Import failed: " . $e->getMessage());
            return self::FAILURE;
        }
    }
    
    /**
     * Maps the header row of a dataset to their respective indices.
     *
     * This method normalizes the headers by trimming whitespace,
     * converting to lowercase, and replacing spaces with underscores.
     * The expected headers are: "GUEST NAME", "FAMILIES",
     * "CHILDREN", "BABIES", and "GENDER",
     * which correspond to specific keys in the resulting map.
     *
     * @param array $header The array of header values to be processed.
     * @return array An associative array mapping normalized header keys to their indices.
     */
    private function mapHeaderColumns(array $header): array
    {
        // Normalizes headers and maps them to indices.
        $map = [];
        foreach ($header as $i => $value) {
            $key = Str::of((string) $value)->trim()->lower()->replace(' ', '_')->toString();
            
            // Expected headers from your file:
            // GUEST NAME, FAMILIES, CHILDREN, BABIES, GENDER
            if ($key === 'guest_name') $map['guest_name'] = $i;
            if ($key === 'families') $map['families'] = $i;
            if ($key === 'children') $map['children'] = $i;
            if ($key === 'babies') $map['babies'] = $i;
            if ($key === 'gender') $map['gender'] = $i;
        }
        return $map;
    }
    
    private function isNumericNonEmpty(?string $value): bool
    {
        if ($value === null) return false;
        $value = trim($value);
        return $value !== '' && is_numeric($value);
    }
    
    private function normalizeGender(?string $raw): ?string
    {
        $raw = strtoupper(trim((string) $raw));
        if ($raw === '') return null;
        
        return match ($raw) {
            'MALE' => 'male',
            'FEMALE' => 'female',
            'OTHER' => 'other',
            default => null,
        };
    }
    
    private function normalizeName(string $name): string
    {
        // Collapse multiple spaces and trim.
        $name = preg_replace('/\s+/', ' ', trim($name));
        return $name ?: 'Unknown';
    }
}
