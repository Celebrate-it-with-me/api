<?php

namespace App\Http\Services\AppServices;

use App\Models\Guest;
use App\Models\GuestRsvpLog;
use App\Models\Rsvp;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrganizerRsvpServices
{
    const STATUS_ATTENDING = 'attending';

    const STATUS_PENDING = 'pending';

    const STATUS_NOT_ATTENDING = 'not-attending';

    public function __construct() {}

    /**
     * Process organizer RSVP confirmation for main guest and companions
     *
     * @throws \Exception
     */
    public function processRsvpConfirmation(Guest $mainGuest, array $validatedData): array
    {
        DB::beginTransaction();

        try {
            $organizerId = Auth::id();
            $updatedGuests = [];

            // 1. Process Main Guest
            $updatedGuests[] = $this->updateMainGuest($mainGuest, $validatedData['guest'], $organizerId);

            // 2. Process Companions (if provided)
            if (isset($validatedData['companions']) && is_array($validatedData['companions'])) {
                foreach ($validatedData['companions'] as $companionData) {
                    $updatedGuests[] = $this->updateCompanion($mainGuest, $companionData, $organizerId);
                }
            }

            // 3. Clear cache for event stats
            $this->clearEventCache($mainGuest->event_id);

            DB::commit();

            // 4. Return results with fresh data
            $mainGuest->load('companions');

            return [
                'success' => true,
                'guest' => $mainGuest,
                'updated_guests_summary' => $updatedGuests,
                'statistics' => $this->getEventStatistics($mainGuest->event_id),
            ];

        } catch (\Exception $e) {
            DB::rollback();
            throw new \Exception('Failed to process RSVP confirmation: ' . $e->getMessage());
        }
    }

    /**
     * Get RSVP status for main guest and companions (for modal pre-population)
     */
    public function getRsvpStatusData(Guest $mainGuest): array
    {
        $mainGuest->load('companions');

        return [
            'success' => true,
            'main_guest' => [
                'id' => $mainGuest->id,
                'name' => $mainGuest->name,
                'email' => $mainGuest->email,
                'phone' => $mainGuest->phone,
                'rsvp_status' => $mainGuest->rsvp_status,
                'rsvp_status_date' => $mainGuest->rsvp_status_date?->format('Y-m-d H:i:s'),
                'notes' => $mainGuest->notes,
            ],
            'companions' => $mainGuest->companions->map(function ($companion) {
                return [
                    'id' => $companion->id,
                    'name' => $companion->name,
                    'rsvp_status' => $companion->rsvp_status,
                    'rsvp_status_date' => $companion->rsvp_status_date?->format('Y-m-d H:i:s'),
                    'notes' => $companion->notes,
                ];
            })->toArray(),
        ];
    }

    /**
     * Apply bulk status to all companions
     *
     * @throws \Exception
     * @throws \Throwable
     */
    public function bulkApplyToCompanions(Guest $mainGuest, string $rsvpStatus, ?string $notes = null): array
    {
        DB::beginTransaction();

        try {
            $mainGuest->load('companions');
            $organizerId = Auth::id();
            $updatedCompanions = [];

            foreach ($mainGuest->companions as $companion) {
                $previousStatus = $companion->rsvp_status;

                // Update companion
                $companion->update([
                    'rsvp_status' => $rsvpStatus,
                    'rsvp_status_date' => now(),
                    'notes' => $notes ?: $companion->notes,
                ]);

                // Log the change
                $this->createRsvpLog(
                    $companion->id,
                    $rsvpStatus,
                    $organizerId,
                    'bulk_companion'
                );

                $updatedCompanions[] = [
                    'id' => $companion->id,
                    'name' => $companion->name,
                    'previous_status' => $previousStatus,
                    'new_status' => $rsvpStatus,
                ];
            }

            // Clear cache
            $this->clearEventCache($mainGuest->event_id);

            DB::commit();

            return [
                'success' => true,
                'message' => "Applied '{$rsvpStatus}' to " . count($updatedCompanions) . ' companions',
                'updated_companions' => $updatedCompanions,
                'main_guest' => $mainGuest->fresh('companions'),
            ];

        } catch (\Exception $e) {
            DB::rollback();
            throw new \Exception('Failed to bulk update companions: ' . $e->getMessage());
        }
    }

    /**
     * Update main guest RSVP status
     */
    private function updateMainGuest(Guest $mainGuest, array $guestData, int $organizerId): array
    {
        $newStatus = $guestData['rsvp_status'];

        // Update main guest
        $mainGuest->update([
            'rsvp_status' => $newStatus,
            'rsvp_status_date' => now(),
            'notes' => $guestData['notes'] ?? $mainGuest->notes,
        ]);

        // Log the change
        $this->createRsvpLog($mainGuest->id, $newStatus, $organizerId, 'main_guest');

        return [
            'id' => $mainGuest->id,
            'name' => $mainGuest->name,
            'type' => 'main_guest',
            'new_status' => $newStatus,
        ];
    }

    /**
     * Update companion RSVP status
     *
     * @throws \Exception
     */
    private function updateCompanion(Guest $mainGuest, array $companionData, int $organizerId): array
    {
        $companion = Guest::findOrFail($companionData['id']);

        // Verify companion belongs to main guest
        if ($companion->parent_id !== $mainGuest->id) {
            throw new \Exception("Companion {$companion->id} does not belong to main guest {$mainGuest->id}");
        }

        $previousStatus = $companion->rsvp_status;
        $newStatus = $companionData['rsvp_status'];

        // Update companion
        $companion->update([
            'rsvp_status' => $newStatus,
            'rsvp_status_date' => now(),
            'notes' => $companionData['notes'] ?? $companion->notes,
        ]);

        // Log the change
        $this->createRsvpLog($companion->id, $newStatus, $organizerId, 'companion');

        return [
            'id' => $companion->id,
            'name' => $companion->name,
            'type' => 'companion',
            'new_status' => $newStatus,
        ];
    }

    /**
     * Create RSVP log entry for audit trail
     */
    private function createRsvpLog(int $guestId, string $newStatus, int $organizerId, string $changeType): void
    {
        GuestRsvpLog::create([
            'guest_id' => $guestId,
            'status' => $newStatus,
            'changed_by' => $organizerId,
            'notes' => "Organizer {$changeType} RSVP update via confirmation modal",
            'changed_at' => now(),
        ]);
    }

    /**
     * Get RSVP statistics for event
     */
    private function getEventStatistics(int $eventId): object
    {
        return Guest::where('event_id', $eventId)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN rsvp_status = ? THEN 1 ELSE 0 END) as attending,
                SUM(CASE WHEN rsvp_status = ? THEN 1 ELSE 0 END) as not_attending,
                SUM(CASE WHEN rsvp_status = ? THEN 1 ELSE 0 END) as pending
            ', [self::STATUS_ATTENDING, self::STATUS_NOT_ATTENDING, self::STATUS_PENDING])
            ->first();
    }

    /**
     * Clear event-related cache
     */
    private function clearEventCache(int $eventId): void
    {
        Cache::forget("event.{$eventId}.rsvp.stats");
        // Add other cache keys if needed
        Cache::forget("event.{$eventId}.rsvp.summary");
    }

    /**
     * Validate RSVP status
     */
    public static function isValidRsvpStatus(string $status): bool
    {
        return in_array($status, [
            self::STATUS_ATTENDING,
            self::STATUS_NOT_ATTENDING,
            self::STATUS_PENDING,
        ]);
    }

    /**
     * Get all valid RSVP statuses
     */
    public static function getValidRsvpStatuses(): array
    {
        return [
            self::STATUS_ATTENDING,
            self::STATUS_NOT_ATTENDING,
            self::STATUS_PENDING,
        ];
    }
}
