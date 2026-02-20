<?php

namespace App\Http\Requests\app;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertSaveTheDateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    
    protected function prepareForValidation(): void
    {
        // Accept camelCase OR snake_case, normalize to snake_case.
        $showCountdown = $this->input('show_countdown', $this->input('showCountdown'));
        $useAddToCalendar = $this->input('use_add_to_calendar', $this->input('useAddToCalendar'));
        
        $dateSource = $this->input('date_source', $this->input('dateSource'));
        $customStartsAt = $this->input('custom_starts_at', $this->input('customStartsAt'));
        
        $countdownUnits = $this->input('countdown_units', $this->input('countdownUnits'));
        $finishBehavior = $this->input('countdown_finish_behavior', $this->input('countdownFinishBehavior'));
        
        $providers = $this->input('calendar_providers', $this->input('calendarProviders'));
        $calendarMode = $this->input('calendar_mode', $this->input('calendarMode'));
        
        $locationOverride = $this->input('calendar_location_override', $this->input('calendarLocationOverride'));
        $descriptionOverride = $this->input('calendar_description_override', $this->input('calendarDescriptionOverride'));
        
        $showCountdownBool = filter_var($showCountdown, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $useAddToCalendarBool = filter_var($useAddToCalendar, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        
        // Normalize arrays coming as JSON-string or csv.
        $providersNorm = $this->normalizeArray($providers);
        $countdownUnitsNorm = $this->normalizeArray($countdownUnits);
        
        // If add-to-calendar is off, ignore calendar config.
        if ($useAddToCalendarBool === false) {
            $providersNorm = null;
            $calendarMode = null;
            $locationOverride = null;
            $descriptionOverride = null;
        }
        
        // If countdown is off, ignore countdown config.
        if ($showCountdownBool === false) {
            $countdownUnitsNorm = null;
            $finishBehavior = null;
        }
        
        $this->merge([
            'show_countdown' => $showCountdownBool,
            'use_add_to_calendar' => $useAddToCalendarBool,
            
            'date_source' => $dateSource,
            'custom_starts_at' => $customStartsAt,
            
            'countdown_units' => $countdownUnitsNorm,
            'countdown_finish_behavior' => $finishBehavior,
            
            'calendar_providers' => $providersNorm,
            'calendar_mode' => $calendarMode,
            
            'calendar_location_override' => $locationOverride,
            'calendar_description_override' => $descriptionOverride,
        ]);
    }
    
    public function rules(): array
    {
        return [
            'show_countdown' => ['required', 'boolean'],
            'use_add_to_calendar' => ['required', 'boolean'],
            
            'date_source' => ['required', 'string', Rule::in(['event', 'custom'])],
            
            // Required only when date_source=custom
            'custom_starts_at' => ['nullable', 'date'],
            // Countdown config
            'countdown_units' => ['nullable', 'array'],
            'countdown_units.*' => ['string', Rule::in(['days', 'hours', 'minutes', 'seconds'])],
            'countdown_finish_behavior' => ['nullable', 'string', Rule::in(['hide', 'message'])],
            
            // Calendar config
            'calendar_providers' => ['nullable', 'array'],
            'calendar_providers.*' => ['string', Rule::in(['google', 'apple', 'outlook'])],
            'calendar_mode' => ['nullable', 'string', Rule::in(['modal', 'ics'])],
            
            'calendar_location_override' => ['nullable', 'string', 'max:255'],
            'calendar_description_override' => ['nullable', 'string'],
        ];
    }
    
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $dateSource = $this->input('date_source');
            $customStartsAt = $this->input('custom_starts_at');
            
            if ($dateSource === 'custom' && empty($customStartsAt)) {
                $validator->errors()->add('custom_starts_at', 'custom_starts_at is required when date_source is custom.');
            }
            
            if ($this->boolean('show_countdown') && empty($this->input('countdown_finish_behavior'))) {
                // Default if not provided (optional, but helps)
                // You can remove this rule if you prefer strict input.
            }
            
            if ($this->boolean('use_add_to_calendar') && empty($this->input('calendar_mode'))) {
                // Same as above.
            }
        });
    }
    
    private function normalizeArray($value): ?array
    {
        if ($value === null || $value === '') return null;
        
        // If already array, keep it.
        if (is_array($value)) return $value;
        
        // If JSON string, decode.
        if (is_string($value)) {
            $trimmed = trim($value);
            
            if (str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{')) {
                $decoded = json_decode($trimmed, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) return $decoded;
            }
            
            // Fallback: comma separated "google,apple,outlook"
            $parts = array_filter(array_map('trim', explode(',', $trimmed)));
            return $parts ? array_values($parts) : null;
        }
        
        return null;
    }
}
