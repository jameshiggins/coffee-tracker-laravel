<?php

namespace App\Policies;

use App\Models\Tasting;
use App\Models\User;

class TastingPolicy
{
    public function update(User $user, Tasting $tasting): bool
    {
        return $user->id === $tasting->user_id;
    }

    public function delete(User $user, Tasting $tasting): bool
    {
        return $user->id === $tasting->user_id;
    }
}
