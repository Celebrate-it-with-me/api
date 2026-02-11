<?php
    
    namespace App\Http\Services\AppServices;
    
    use App\Models\Events;
    use App\Models\Guest;
    use Illuminate\Support\Str;
    use Random\RandomException;
    
    class CalculateAccessCodeService
    {
        /**
         * If the event has <= this amount of guests, we load all codes once
         * and check uniqueness in memory (fast, no loop queries).
         */
        private const IN_MEMORY_THRESHOLD = 800;
        
        /**
         * Hard limit to avoid infinite loops in the extremely unlikely case
         * of a near-exhausted namespace for a given prefix.
         */
        private const MAX_ATTEMPTS = 2000;
        
        /**
         * Generate a unique access code for the given event.
         *
         * Format: AA#### (2 uppercase letters + 4 digits)
         * @throws RandomException
         */
        public function generateForEvent(Events $event): string
        {
            $eventId = (int) $event->id;
            
            if ($eventId <= 0) {
                throw new \InvalidArgumentException('Invalid event id.');
            }
            
            $prefix = Str::upper(Str::random(2));
            
            $guestCount = $event->guests()->count();
            
            if ($guestCount <= self::IN_MEMORY_THRESHOLD) {
                return $this->generateUsingInMemoryCodes($eventId, $prefix);
            }
            
            return $this->generateUsingDbExists($eventId, $prefix);
        }
        
        /**
         * Load all codes once, then do uniqueness checks in memory.
         *
         * @throws RandomException
         */
        private function generateUsingInMemoryCodes(int $eventId, string $prefix): string
        {
            $existing = Guest::query()
                ->where('event_id', $eventId)
                ->pluck('code')
                ->all();
            
            for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
                $candidate = $prefix . random_int(1000, 9999);
                
                if (!in_array($candidate, $existing, true)) {
                    return $candidate;
                }
            }
            
            throw new \RuntimeException('Failed to generate a unique access code (in-memory).');
        }
        
        /**
         * Check uniqueness via DB exists() per attempt (lower memory usage).
         *
         * @throws RandomException
         */
        private function generateUsingDbExists(int $eventId, string $prefix): string
        {
            for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
                $candidate = $prefix . random_int(1000, 9999);
                
                $exists = Guest::query()
                    ->where('event_id', $eventId)
                    ->where('code', $candidate)
                    ->exists();
                
                if (!$exists) {
                    return $candidate;
                }
            }
            
            throw new \RuntimeException('Failed to generate a unique access code (db-exists).');
        }
    }
