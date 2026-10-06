<?php

namespace App\Actions\Challenges;

use App\Exceptions\ContextClosed;
use App\Models\Challenge;
use App\Models\ChallengeParticipation;
use App\Models\User;

/**
 * Inscrit un utilisateur à un défi en cours (ou renvoie sa participation existante).
 * Pour un contre-la-montre, le chronomètre personnel démarre à ce moment.
 */
class JoinChallenge
{
    public function handle(User $user, Challenge $challenge): ChallengeParticipation
    {
        $existing = $challenge->participations()->where('user_id', $user->id)->first();

        if ($existing) {
            return $existing;
        }

        if (! $challenge->isRunning()) {
            throw new ContextClosed('Ce défi n\'est pas ouvert.');
        }

        if ($challenge->organization_id && ! $user->organizations()->whereKey($challenge->organization_id)->exists()) {
            throw new ContextClosed('Ce défi est réservé aux membres de '.$challenge->organization->name.'.');
        }

        return $challenge->participations()->firstOrCreate(
            ['user_id' => $user->id],
            ['started_at' => now()],
        );
    }
}
