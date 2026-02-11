<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BudgetItemReminder extends Model
{
    
    protected $table = 'budget_item_reminders';
    
    protected $fillable = [
        'budget_item_id',
        'user_id',
        'threshold_days',
        'sent_at',
    ];
    
    protected $casts = [
        'sent_at' => 'datetime',
    ];
    
    /**
     * Define a relationship to the BudgetItem model.
     *
     * @return BelongsTo
     */
    public function budgetItem(): BelongsTo
    {
        return $this->belongsTo(BudgetItem::class, 'budget_item_id');
    }
    
    /**
     * Define a relationship to the User model.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    
}
