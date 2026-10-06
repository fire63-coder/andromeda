<?php

namespace App\Actions\Certifications;

use App\Enums\AttemptStatus;
use App\Models\CertificationAttempt;
use App\Services\Gamification\BadgeEvaluator;
use App\Services\Gamification\XpService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Clôt une tentative (bouton « Terminer » ou fin du temps) : score = moyenne des scores
 * de la dernière réponse à chaque question (0 sans réponse), certificat si réussite.
 */
class FinishCertificationAttempt
{
    public function __construct(
        private readonly XpService $xp,
        private readonly BadgeEvaluator $badges,
    ) {}

    public function handle(CertificationAttempt $attempt): CertificationAttempt
    {
        $passed = DB::transaction(function () use ($attempt) {
            $attempt = $attempt->newQuery()->lockForUpdate()->findOrFail($attempt->id);

            if ($attempt->status !== AttemptStatus::InProgress) {
                return null;
            }

            $certification = $attempt->certification;
            $score = (int) round($this->scores($attempt)->avg());
            $passed = $score >= $certification->passing_score;

            $attempt->update([
                'status' => $passed ? AttemptStatus::Passed : AttemptStatus::Failed,
                'score' => $score,
                // Fin du temps : on date la clôture à l'échéance, pas au moment où l'on s'en aperçoit.
                'completed_at' => $attempt->expires_at->isPast() ? $attempt->expires_at : now(),
                'certificate_code' => $passed ? $this->certificateCode() : null,
                'issued_at' => $passed ? now() : null,
            ]);

            if ($passed) {
                $this->xp->award($attempt->user, $certification->xp_reward, 'certification_passed', $attempt, ['certification' => $certification->slug]);
            }

            return $passed;
        });

        if ($passed) {
            $this->badges->evaluate($attempt->user);
        }

        return $attempt->refresh();
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
