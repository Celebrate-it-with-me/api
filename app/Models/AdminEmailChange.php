<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminEmailChange extends Model
{
    protected $table = 'admin_email_changes';

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected $casts = ['expires_at' => 'datetime'];
}
