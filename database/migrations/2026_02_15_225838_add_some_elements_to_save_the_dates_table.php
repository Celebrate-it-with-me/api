<?php
    
    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;
    
    return new class extends Migration
    {
        public function up(): void
        {
            // No data to preserve: drop and recreate from scratch.
            Schema::dropIfExists('save_the_dates');
            
            Schema::create('save_the_dates', function (Blueprint $table) {
                $table->id();
                
                $table->foreignId('event_id')
                    ->constrained('events')
                    ->cascadeOnDelete();
                
                // Core toggles (no extra theme config here)
                $table->boolean('show_countdown')->default(true);
                $table->boolean('use_add_to_calendar')->default(true);
                
                // Behavior/config fields (keep existing theme fields for now)
                
                // Where the countdown/calendar date comes from:
                // - event: use events.start_date/start_time (or equivalent)
                // - custom: use custom_starts_at
                $table->string('date_source')->default('event');
                
                // Optional override date/time for Save The Date
                $table->dateTime('custom_starts_at')->nullable();
                
                // Countdown units configuration (future-proof)
                $table->json('countdown_units')->nullable();
                
                // What to do when countdown finishes: hide | message
                $table->string('countdown_finish_behavior')->default('hide');
                
                // Which calendars to show in "Add to calendar"
                // Example: ["google","apple","outlook"]
                $table->json('calendar_providers')->nullable();
                
                // Optional: choose UX mode for add-to-calendar
                // modal: show options UI
                // ics: direct download/redirect behavior
                $table->string('calendar_mode')->default('modal');
                
                // Optional overrides (if you want later)
                $table->string('calendar_location_override')->nullable();
                $table->text('calendar_description_override')->nullable();
                
                $table->timestamps();
                
                // One record per event
                $table->unique('event_id');
            });
        }
        
        public function down(): void
        {
            Schema::dropIfExists('save_the_dates');
        }
    };
