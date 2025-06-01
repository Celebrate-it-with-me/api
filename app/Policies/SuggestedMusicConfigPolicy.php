<?php

namespace App\Policies;

use App\Models\SuggestedMusicConfig;
use App\Models\User;

class SuggestedMusicConfigPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, SuggestedMusicConfig $suggestedMusicConfig): bool
    {
        return false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, SuggestedMusicConfig $suggestedMusicConfig): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, SuggestedMusicConfig $suggestedMusicConfig): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, SuggestedMusicConfig $suggestedMusicConfig): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, SuggestedMusicConfig $suggestedMusicConfig): bool
    {
        return false;
    }
}
