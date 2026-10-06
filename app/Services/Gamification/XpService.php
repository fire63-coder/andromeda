<?php

namespace App\Services\Gamification;

use App\Models\Rank;
use App\Models\User;
use App\Models\XpTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Seul point d'écriture de l'XP : ligne immuable dans xp_transactions,
 * cache users.xp incrémenté atomiquement, rang recalculé.
 */
class XpService
{
    /**
     * @param  array<string, mixed>  $meta
     * @return ?Rank le nouveau rang si l'utilisateur vient d'en changer
     */
    public function award(User $user, int $amount, string $reason, ?Model $source = null, array $meta = []): ?Rank
    {
        if ($amount === 0) {
            return null;
        }

        return DB::transaction(function () use ($user, $amount, $reason, $source, $meta) {
            XpTransaction::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'reason' => $reason,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'meta' => $meta ?: null,
            ]);

            $user->newQuery()->whereKey($user->id)->increment('xp', $amount);
            $user->refresh();

            $rank = Rank::forXp($user->xp);

            if ($rank && $rank->id !== $user->rank_id) {
                $promoted = $rank->min_xp > ($user->rank?->min_xp ?? -1);
                $user->forceFill(['rank_id' => $rank->id])->save();
                $user->setRelation('rank', $rank);

                return $promoted ? $rank : null;
            }

            return null;
        });
    }
}
