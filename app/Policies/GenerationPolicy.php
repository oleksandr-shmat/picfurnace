<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Generation;
use App\Models\User;

class GenerationPolicy
{
    /**
     * A generation, and its image, belongs to its author. Admins can see all of them.
     */
    public function view(User $user, Generation $generation): bool
    {
        return $user->isAdmin() || $generation->user_id === $user->id;
    }
}
