<?php

namespace App\Livewire\Admin\Certifications;

use App\Enums\AttemptStatus;
use App\Models\Certification;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Certifications — administration')]
class CertificationIndex extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', Certification::class);
    }

    public function render()
    {
        return view('livewire.admin.certifications.index', [
            'certifications' => Certification::query()
                ->with('level')
                ->withCount([
                    'exercisePool',
                    'attempts',
                    'attempts as passed_count' => fn ($q) => $q->where('status', AttemptStatus::Passed),
                ])
                ->withAvg(['attempts as average_score' => fn ($q) => $q->whereNotNull('score')], 'score')
                ->orderBy('level_id')
                ->get(),
        ]);
    }
}
