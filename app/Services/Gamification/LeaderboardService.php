<?php

namespace App\Services\Gamification;

use App\Models\LeaderboardSnapshot;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Classements par période (semaine, mois, depuis toujours), globaux ou par organisation.
 *
 * - Semaine / mois : somme des xp_transactions de la période (grand livre).
 * - Depuis toujours : users.xp.
 * Rang « de compétition » : à score égal, même position (1, 2, 2, 4...).
 */
class LeaderboardService
{
    public const PERIODS = ['weekly' => 'Cette semaine', 'monthly' => 'Ce mois-ci', 'all_time' => 'Depuis toujours'];

    /**
     * @return Collection<int, array{position: int, user: User, score: int}>
     */
    public function standings(string $period, ?Organization $organization = null, int $limit = 50): Collection
    {
        $rows = $this->scores($period, $organization)
            ->orderByDesc('score')
            ->orderBy('users.id')
            ->limit($limit)
            ->get();

        $users = User::query()
            ->with('rank')
            ->withCount('badges')
            ->findMany($rows->pluck('id'))
            ->keyBy('id');

        $position = 0;
        $previous = null;

        return $rows->values()->map(function ($row, int $index) use ($users, &$position, &$previous) {
            $score = (int) $row->score;

            if ($score !== $previous) {
                $position = $index + 1;
                $previous = $score;
            }

            return ['position' => $position, 'user' => $users[$row->id], 'score' => $score];
        });
    }

    /**
     * Position et score de l'utilisateur, même hors du top affiché (null s'il n'a rien marqué).
     *
     * @return ?array{position: int, score: int}
     */
    public function positionOf(User $user, string $period, ?Organization $organization = null): ?array
    {
        // first() et non value('score') : value() remplacerait la sélection calculée.
        $score = (int) $this->scores($period, $organization)->where('users.id', $user->id)->first()?->score;

        if ($score <= 0) {
            return null;
        }

        $better = DB::query()
            ->fromSub($this->scores($period, $organization), 'standings')
            ->where('score', '>', $score)
            ->count();

        return ['position' => $better + 1, 'score' => $score];
    }

    /**
     * Fige les classements courants (tâche planifiée quotidienne) : sert à afficher
     * la progression (▲ ▼) d'un jour sur l'autre et à garder l'historique des périodes.
     */
    public function snapshot(int $limit = 500): int
    {
        $count = 0;
        $scopes = Organization::query()->get()->prepend(null);

        foreach (array_keys(self::PERIODS) as $period) {
            $periodStart = $this->periodStart($period)?->toDateString() ?? '1970-01-01';

            foreach ($scopes as $organization) {
                LeaderboardSnapshot::query()
                    ->where('period', $period)
                    ->where('period_start', $periodStart)
                    ->where('organization_id', $organization?->id)
                    ->whereDate('created_at', today())
                    ->delete();

                $rows = $this->standings($period, $organization, $limit)->map(fn (array $row) => [
                    'period' => $period,
                    'period_start' => $periodStart,
                    'organization_id' => $organization?->id,
                    'user_id' => $row['user']->id,
                    'xp' => $row['score'],
                    'position' => $row['position'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                LeaderboardSnapshot::insert($rows->all());
                $count += $rows->count();
            }
        }

        return $count;
    }

    /**
     * Positions de la veille (dernier instantané antérieur à aujourd'hui), par utilisateur.
     *
     * @return Collection<int, int> user_id => position
     */
    public function previousPositions(string $period, ?Organization $organization = null): Collection
    {
        $periodStart = $this->periodStart($period)?->toDateString() ?? '1970-01-01';

        $base = LeaderboardSnapshot::query()
            ->where('period', $period)
            ->where('period_start', $periodStart)
            ->where('organization_id', $organization?->id)
            ->whereDate('created_at', '<', today());

        $lastDay = (clone $base)->max('created_at');

        return $lastDay === null
            ? collect()
            : $base->where('created_at', $lastDay)->pluck('position', 'user_id');
    }

    public function periodStart(string $period): ?Carbon
    {
        return match ($period) {
            'weekly' => now()->startOfWeek(),
            'monthly' => now()->startOfMonth(),
            default => null,
        };
    }

    /**
     * @return Builder requête (id, score) des utilisateurs classables
     */
    private function scores(string $period, ?Organization $organization)
    {
        $start = $this->periodStart($period);

        $query = DB::table('users')
            ->where('users.leaderboard_visible', true)
            ->where('users.is_active', true)
            ->when($organization, fn ($q) => $q->whereIn('users.id', $organization->members()->select('users.id')));

        if ($start === null) {
            return $query->where('users.xp', '>', 0)->select('users.id', 'users.xp as score');
        }

        return $query
            ->join('xp_transactions', 'xp_transactions.user_id', '=', 'users.id')
            ->where('xp_transactions.created_at', '>=', $start)
            ->groupBy('users.id')
            ->havingRaw('SUM(xp_transactions.amount) > 0')
            ->select('users.id', DB::raw('SUM(xp_transactions.amount) as score'));
    }
}
