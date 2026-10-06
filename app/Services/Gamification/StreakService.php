<?php

namespace App\Services\Gamification;

use App\Models\User;

/**
 * Série de jours consécutifs avec au moins une activité.
 */
class StreakService
{
    public function touch(User $user): void
    {
        $today = today();
        $last = $user->last_activity_on;

        if ($last?->isSameDay($today)) {
            return;
        }

        $current = $last?->isSameDay($today->copy()->subDay()) ? $user->current_streak + 1 : 1;

        $user->forceFill([
            'current_streak' => $current,
            'longest_streak' => max($current, $user->longest_streak),
            'last_activity_on' => $today,
        ])->save();
    }
}
