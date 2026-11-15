<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A step of the smart assistant's catalog (docs/plans/assistant-contract.md). */
class AssistantTask extends Model
{
    protected $table = 'assistant_tasks';

    protected $fillable = [
        'key', 'event_type', 'phase', 'sort_order', 'start_months', 'late_months', 'prerequisites', 'detector', 'module_key',
        'route', 'title_es', 'title_en', 'why_es', 'why_en', 'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'start_months' => 'float',
        'late_months' => 'float',
        'prerequisites' => 'array',
        'is_active' => 'boolean',
    ];
}
