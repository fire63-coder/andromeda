<?php

namespace App\Actions\Certifications;

use App\Enums\AttemptStatus;
use App\Models\CertificationAttempt;
use App\Notifications\CertificationCompleted;
use App\Services\Gamification\BadgeEvaluator;
use App\Services\Gamification\XpService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Clôt une tentative (bouton « Terminer », fin du temps ou trop d'incidents en mode examen) : score = moyenne des scores
 * de la dernière réponse à chaque question (0 sans réponse), certificat si réussite.
 */
class FinishCertificationAttempt
{
    public function __construct(
        private readonly XpService $xp,
        private readonly BadgeEvaluator $badges,
    ) {}

    /**
     * @param  string  $reason  submitted (bouton « Terminer ») | incidents (mode examen) ; « timeout » est déduit de l'échéance.
     */
    public function handle(CertificationAttempt $attempt, string $reason = 'submitted'): CertificationAttempt
    {
        $passed = DB::transaction(function () use ($attempt, $reason) {
            $attempt = $attempt->newQuery()->lockForUpdate()->findOrFail($attempt->id);

            if ($attempt->status !== AttemptStatus::InProgress) {
                return null;
            }

            $certification = $attempt->certification;
            $score = (int) round($this->scores($attempt)->avg());
            $passed = $score >= $certification->passing_score;
            $timedOut = $attempt->expires_at->isPast();

            $attempt->update([
                'status' => $passed ? AttemptStatus::Passed : AttemptStatus::Failed,
                'score' => $score,
                // Fin du temps : on date la clôture à l'échéance, pas au moment où l'on s'en aperçoit.
                'completed_at' => $timedOut ? $attempt->expires_at : now(),
                'closed_reason' => $timedOut ? 'timeout' : $reason,
                'certificate_code' => $passed ? $this->certificateCode() : null,
                'issued_at' => $passed ? now() : null,
            ]);

            if ($passed) {
                $this->xp->award($attempt->user, $certification->xp_reward, 'certification_passed', $attempt, ['certification' => $certification->slug]);
            }

            return $passed;
        });

        if ($passed === null) {
            return $attempt->refresh(); // déjà close
        }

        if ($passed) {
            $this->badges->evaluate($attempt->user);
        }

        $attempt->refresh();
        $attempt->user->notify(new CertificationCompleted($attempt));

        return $attempt;
    }

    /**
     * Score de la dernière réponse à chaque question du sujet (0 si aucune), dans l'ordre du sujet.
     *
     * @return Collection<int, int> exercise_id => score
     */
    public function scores(CertificationAttempt $attempt): Collection
    {
        $latest = $attempt->submissions()
            ->whereIn('id', fn ($q) => $q->selectRaw('MAX(id)')
                ->from('user_submissions')
                ->where('context_type', $attempt->getMorphClass())
                ->where('context_id', $attempt->id)
                ->groupBy('exercise_id'))
            ->pluck('score', 'exercise_id');

        return collect($attempt->exercise_ids)->mapWithKeys(fn (int $id) => [$id => (int) ($latest[$id] ?? 0)]);
    }

    private function certificateCode(): string
    {
        do {
            $code = 'AND-'.Str::upper(Str::random(4)).'-'.Str::upper(Str::random(4)).'-'.Str::upper(Str::random(4));
        } while (CertificationAttempt::where('certificate_code', $code)->exists());

        return $code;
    }
}
