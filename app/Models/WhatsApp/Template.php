<?php

namespace App\Models\WhatsApp;

use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    protected $table = 'whatsapp_templates';

    protected $guarded = ['id'];

    protected $casts = ['buttons' => 'array', 'variables' => 'array', 'submitted_at' => 'datetime', 'approved_at' => 'datetime'];

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
