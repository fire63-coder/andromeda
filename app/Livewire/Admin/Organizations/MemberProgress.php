<?php

namespace App\Livewire\Admin\Organizations;

use App\Models\Course;
use App\Models\Organization;
use App\Models\User;
use App\Services\Learning\CourseProgress;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Fiche d'un élève vue par le responsable de son organisation : cours, soumissions récentes, certifications.
 */
#[Title('Fiche élève')]
class MemberProgress extends Component
{
    #[Locked]
    public Organization $organization;

    #[Locked]
    public User $member;

    public function mount(Organization $organization, User $member): void
    {
        $this->authorize('update', $organization);
        abort_unless($organization->members()->whereKey($member->id)->exists(), 404);

        $this->organization = $organization;
        $this->member = $member;
    }

    public function render(CourseProgress $progress)
    {
        $courses = Course::query()->published()->with('level:id,position,name')->orderBy('level_id')->orderBy('position')->get()
            ->map(fn (Course $course) => ['course' => $course, 'percent' => $progress->percent($this->member, $course)])
            ->filter(fn (array $row) => $row['percent'] > 0)
            ->values();

        return view('livewire.admin.organizations.member', [
            'courses' => $courses,
            'submissions' => $this->member->submissions()->with(['exercise:id,title,slug', 'dialect:id,name'])->latest('id')->limit(25)->get(),
            'attempts' => $this->member->certificationAttempts()->with('certification:id,title')->latest('id')->limit(10)->get(),
            'badges' => $this->member->badges()->orderByPivot('awarded_at', 'desc')->limit(12)->get(),
        ]);
    }
}
