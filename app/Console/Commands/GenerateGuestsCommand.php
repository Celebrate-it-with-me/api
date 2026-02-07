<?php

namespace App\Console\Commands;

use App\Models\Events;
use App\Models\Guest;
use App\Models\Menu;
use Illuminate\Console\Command;

class GenerateGuestsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'guests:generate
                            {--event= : Event ID or name to generate guests for}
                            {--main-guests=20 : Number of main guests to create}
                            {--companions=40 : Number of companions to create}
                            {--clear : Clear existing guests before generating new ones}
                            {--list-events : List available events}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate test guests and companions for an event';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // List events if requested
        if ($this->option('list-events')) {
            $this->listEvents();
            return 0;
        }

        // Get event
        $event = $this->getEvent();
        if (!$event) {
            return 1;
        }

        // Get options
        $mainGuestsCount = (int) $this->option('main-guests');
        $companionsCount = (int) $this->option('companions');
        $clearExisting = $this->option('clear');

        // Validate input
        if ($mainGuestsCount <= 0 || $companionsCount < 0) {
            $this->error('Invalid guest counts. Main guests must be > 0, companions must be >= 0.');
            return 1;
        }

        // Clear existing guests if requested
        if ($clearExisting) {
            if ($this->confirm("This will delete ALL existing guests for event '{$event->event_name}'. Continue?")) {
                $this->clearExistingGuests($event);
            } else {
                $this->info('Operation cancelled.');
                return 0;
            }
        }

        // Show summary
        $this->info("Generating guests for event: {$event->event_name} (ID: {$event->id})");
        $this->info("Main guests: {$mainGuestsCount}");
        $this->info("Companions: {$companionsCount}");

        if (!$this->confirm('Proceed?', true)) {
            $this->info('Operation cancelled.');
            return 0;
        }

        // Generate guests
        $this->generateGuests($event, $mainGuestsCount, $companionsCount);

        return 0;
    }

    /**
     * Get the event to generate guests for
     */
    private function getEvent(): ?Events
    {
        $eventOption = $this->option('event');

        if (!$eventOption) {
            // Show available events and ask user to select
            $events = Events::orderBy('event_name')->get();

            if ($events->isEmpty()) {
                $this->error('No events found. Please create an event first.');
                return null;
            }

            $this->info('Available events:');
            foreach ($events as $event) {
                $guestCount = Guest::where('event_id', $event->id)->count();
                $mainGuestCount = Guest::where('event_id', $event->id)->whereNull('parent_id')->count();
                $companionCount = Guest::where('event_id', $event->id)->whereNotNull('parent_id')->count();
                $this->line("  [{$event->id}] {$event->event_name} ({$mainGuestCount} main guests, {$companionCount} companions)");
            }

            $eventId = $this->ask('Enter event ID');

            if (!$eventId) {
                $this->error('Event ID is required.');
                return null;
            }

            return Events::find($eventId);
        }

        // Try to find by ID first, then by name
        if (is_numeric($eventOption)) {
            $event = Events::find($eventOption);
        } else {
            $event = Events::where('event_name', 'like', "%{$eventOption}%")->first();
        }

        if (!$event) {
            $this->error("Event not found: {$eventOption}");
            return null;
        }

        return $event;
    }

    /**
     * List available events
     */
    private function listEvents(): void
    {
        $events = Events::orderBy('event_name')->get();

        if ($events->isEmpty()) {
            $this->info('No events found.');
            return;
        }

        $this->info('Available events:');

        $this->table(
            ['ID', 'Name', 'Start Date', 'Main Guests', 'Companions', 'Total'],
            $events->map(function ($event) {
                $mainGuests = Guest::where('event_id', $event->id)->whereNull('parent_id')->count();
                $companions = Guest::where('event_id', $event->id)->whereNotNull('parent_id')->count();
                return [
                    $event->id,
                    $event->event_name,
                    $event->start_date ?? 'Not set',
                    $mainGuests,
                    $companions,
                    $mainGuests + $companions
                ];
            })->toArray()
        );
    }

    /**
     * Clear existing guests for an event
     */
    private function clearExistingGuests(Events $event): void
    {
        $existingCount = Guest::where('event_id', $event->id)->count();

        if ($existingCount > 0) {
            $this->info("Deleting {$existingCount} existing guests...");
            Guest::where('event_id', $event->id)->delete();
            $this->info('✅ Existing guests deleted.');
        } else {
            $this->info('No existing guests to delete.');
        }
    }

    /**
     * Generate guests and companions
     */
    private function generateGuests(Events $event, int $mainGuestsCount, int $companionsCount): void
    {
        $totalOperations = $mainGuestsCount + $companionsCount;
        $progressBar = $this->output->createProgressBar($totalOperations);
        $progressBar->start();

        // Get available menus for the event
        $menus = Menu::where('event_id', $event->id)->get();

        // Create main guests (parent_id = null)
        $this->newLine();
        $this->info('Creating main guests...');

        $mainGuests = collect();
        for ($i = 0; $i < $mainGuestsCount; $i++) {
            $statuses = ['pending', 'attending', 'not-attending'];
            $weights = [30, 50, 20]; // 30% pending, 50% attending, 20% not attending
            $randomStatus = $this->weightedRandom($statuses, $weights);
            $mainGuest = Guest::factory()
                ->mainGuest()
                ->forEvent($event)
                ->state(function () use ($menus, $randomStatus) {
                    $guestData = [];
                    $guestData['rsvp_status'] = $randomStatus;
                    $guestData['rsvp_status_date'] = $randomStatus !== 'pending'
                        ? fake()->dateTimeBetween('-15 days', 'now')
                        : null;

                    if ($menus->isNotEmpty() && rand(1, 100) <= 70) {
                        $guestData['assigned_menu_id'] = $menus->random()->id;
                    }

                    return $guestData;
                })
                ->create();

            $mainGuests->push($mainGuest);
            $progressBar->advance();
        }

        // Create companions (parent_id pointing to main guests)
        if ($companionsCount > 0) {
            $this->newLine();
            $this->info('Creating companions...');

            for ($i = 0; $i < $companionsCount; $i++) {
                // Randomly select a main guest to be the parent
                $randomMainGuest = $mainGuests->random();

                Guest::factory()
                    ->companion($randomMainGuest)
                    ->forEvent($event)
                    ->state([
                        'rsvp_status' => $randomMainGuest->rsvp_status, // Inherit from main guest
                        'rsvp_status_date' => $randomMainGuest->rsvp_status_date,
                        'assigned_menu_id' => $randomMainGuest->assigned_menu_id, // Inherit menu
                        'is_vip' => false, // Companions are usually not VIP
                    ])
                    ->create();

                $progressBar->advance();
            }
        }

        $progressBar->finish();

        // Show results
        $this->newLine(2);
        $this->showResults($event);
    }

    /**
     * Weighted random selection
     */
    private function weightedRandom(array $values, array $weights): mixed
    {
        $totalWeight = array_sum($weights);
        $random = rand(1, $totalWeight);

        $currentWeight = 0;
        foreach ($values as $index => $value) {
            $currentWeight += $weights[$index];
            if ($random <= $currentWeight) {
                return $value;
            }
        }

        return $values[0]; // Fallback
    }

    /**
     * Show generation results
     */
    private function showResults(Events $event): void
    {
        $totalGuests = Guest::where('event_id', $event->id)->count();
        $mainGuestsCreated = Guest::where('event_id', $event->id)->whereNull('parent_id')->count();
        $companionsCreated = Guest::where('event_id', $event->id)->whereNotNull('parent_id')->count();

        $this->info('✅ Guests generated successfully!');
        $this->newLine();

        $this->table(
            ['Type', 'Count'],
            [
                ['Main Guests (parent_id = null)', $mainGuestsCreated],
                ['Companions (parent_id set)', $companionsCreated],
                ['Total Guests', $totalGuests],
            ]
        );

        // Show companions distribution per main guest
        $distribution = Guest::where('event_id', $event->id)
            ->whereNull('parent_id')
            ->withCount('companions')
            ->get()
            ->groupBy('companions_count')
            ->map->count()
            ->sortKeys();

        if ($distribution->isNotEmpty()) {
            $this->newLine();
            $this->info('Companions distribution per main guest:');
            foreach ($distribution as $companionCount => $guestCount) {
                $this->line("  {$guestCount} main guests have {$companionCount} companions");
            }
        }

        // Show RSVP status distribution
        $rsvpStats = Guest::where('event_id', $event->id)
            ->whereNull('parent_id') // Only count main guests for RSVP stats
            ->selectRaw('rsvp_status, COUNT(*) as count')
            ->groupBy('rsvp_status')
            ->pluck('count', 'rsvp_status');

        if ($rsvpStats->isNotEmpty()) {
            $this->newLine();
            $this->info('RSVP Status (main guests):');
            foreach ($rsvpStats as $status => $count) {
                $this->line("  {$count} guests: {$status}");
            }
        }

        $this->newLine();
        $this->info("Event: {$event->event_name}");
        $this->info("Total people attending the event: {$totalGuests}");
    }
}
