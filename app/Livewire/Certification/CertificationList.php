<?php

namespace App\Livewire\Certification;

use App\Actions\Certifications\CertificationException;
use App\Actions\Certifications\StartCertificationAttempt;
use App\Enums\AttemptStatus;
use App\Enums\ContentStatus;
use App\Models\Certification;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Certifications')]
class CertificationList extends Component
{
    public ?string $error = null;

    public function start(int $certificationId, StartCertificationAttempt $start): void
    {
        $certification = Certification::where('status', ContentStatus::Published)->findOrFail($certificationId);

        try {
            $attempt = $start->handle(auth()->user(), $certification);
        } catch (CertificationException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->redirectRoute('certifications.attempt', $attempt, navigate: true);
    }

    public function render(StartCertificationAttempt $start)
    {
        $user = auth()->user();

        $certifications = Certification::query()
            ->where('status', ContentStatus::Published)
            ->with(['level', 'dialect'])
            ->withCount('exercisePool')
            ->join('levels', 'levels.id', '=', 'certifications.level_id')
            ->orderBy('levels.position')
            ->select('certifications.*')
            ->get()
            ->map(fn (Certification $certification) => [
                'certification' => $certification,
                'attempts' => $attempts = $certification->attempts()->where('user_id', $user->id)->latest('id')->get(),
                'current' => $attempts->first(fn ($a) => $a->status === AttemptStatus::InProgress && ! $a->isExpired()),
                'passed' => $attempts->first(fn ($a) => $a->status === AttemptStatus::Passed),
                'blocked' => $start->ineligibility($user, $certification),
            ]);

        return view('livewire.certification.list', ['items' => $certifications]);
    }
}
