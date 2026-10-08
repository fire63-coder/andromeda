<?php

namespace App\Actions\Certifications;

use App\Enums\AttemptStatus;
use App\Enums\ContentStatus;
use App\Models\Certification;
use App\Models\CertificationAttempt;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Démarre (ou reprend) une tentative : sujet tiré au sort dans le pool (difficulté équilibrée), figé, chronométré.
 */
class StartCertificationAttempt
{
    public function __construct(private readonly FinishCertificationAttempt $finish) {}

    public function handle(User $user, Certification $certification): CertificationAttempt
    {
        if ($certification->status !== ContentStatus::Published) {
            throw new CertificationException('Cette certification n\'est pas ouverte.');
        }

        return DB::transaction(function () use ($user, $certification) {
            $current = $certification->attempts()
                ->where('user_id', $user->id)
                ->where('status', AttemptStatus::InProgress)
                ->lockForUpdate()
                ->first();

            if ($current && ! $current->isExpired()) {
                return $current;
            }

            if ($current) {
                $this->finish->handle($current);
            }

            $this->ensureEligible($user, $certification);

            $exerciseIds = $this->draw($certification);

            if ($exerciseIds->count() < $certification->exercises_count) {
                throw new CertificationException('Le sujet de cette certification n\'est pas encore prêt. Réessayez plus tard.');
            }

            return $certification->attempts()->create([
                'user_id' => $user->id,
                'status' => AttemptStatus::InProgress,
                'exercise_ids' => $exerciseIds->all(),
                'started_at' => now(),
                'expires_at' => now()->addMinutes($certification->duration_minutes),
            ]);
        });
    }

    /**
     * Tirage du sujet : au hasard, mais à difficulté comparable d'un candidat à l'autre.
     * Chaque niveau de difficulté du pool reçoit une part des questions proportionnelle à sa taille
     * (méthode du plus fort reste), les questions sont tirées dans chaque part puis mélangées.
     *
     * @return Collection<int, int>
     */
    public function draw(Certification $certification): Collection
    {
        $pool = $certification->exercisePool()->published()->pluck('exercises.difficulty', 'exercises.id');
        $wanted = $certification->exercises_count;

        if ($pool->count() <= $wanted) {
            return $pool->keys()->shuffle()->values();
        }

        $groups = $pool->keys()->groupBy(fn (int $id) => (int) $pool[$id]);
        $quotas = $groups->map(fn (Collection $ids) => $wanted * $ids->count() / $pool->count());
        $taken = $quotas->map(fn (float $quota) => (int) floor($quota))->all();

        // Questions restantes : aux niveaux dont la part a la plus forte partie décimale (ex æquo : au hasard).
        $remainders = $quotas->sortByDesc(fn (float $quota) => ($quota - floor($quota)) + random_int(0, 999) / 1e6)->keys();

        foreach ($remainders->take($wanted - array_sum($taken)) as $difficulty) {
            $taken[$difficulty]++;
        }

        return $groups->flatMap(fn (Collection $ids, int $difficulty) => $ids->shuffle()->take($taken[$difficulty]))
            ->shuffle()
            ->values();
    }

    /**
     * Raison pour laquelle l'utilisateur ne peut pas commencer, ou null.
     */
    public function ineligibility(User $user, Certification $certification): ?string
    {
        try {
            $this->ensureEligible($user, $certification);

            return null;
        } catch (CertificationException $e) {
            return $e->getMessage();
        }
    }

    private function ensureEligible(User $user, Certification $certification): void
    {
        $attempts = $certification->attempts()->where('user_id', $user->id);

        if ((clone $attempts)->where('status', AttemptStatus::Passed)->exists()) {
            throw new CertificationException('Vous avez déjà obtenu cette certification.');
        }

        if ($certification->max_attempts !== null && (clone $attempts)->count() >= $certification->max_attempts) {
            throw new CertificationException('Vous avez utilisé toutes vos tentatives pour cette certification.');
        }

        $lastFinished = (clone $attempts)->whereNotNull('completed_at')->latest('completed_at')->value('completed_at');
        $nextAllowed = $lastFinished ? Carbon::parse($lastFinished)->addHours($certification->cooldown_hours) : null;

        if ($nextAllowed?->isFuture()) {
            throw new CertificationException('Prochaine tentative possible '.$nextAllowed->diffForHumans().'.');
        }
    }
}
