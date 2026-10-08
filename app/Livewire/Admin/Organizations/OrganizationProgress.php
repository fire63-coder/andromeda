<?php

namespace App\Livewire\Admin\Organizations;

use App\Models\Organization;
use App\Services\Analytics\GroupAnalytics;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Suivi pédagogique d'une organisation : activité, élèves, exercices qui bloquent, compétences.
 */
#[Title('Suivi pédagogique')]
class OrganizationProgress extends Component
{
    public const SORTS = [
        'name' => 'Nom',
        'xp' => 'XP',
        'solved' => 'Exercices résolus',
        'activity' => 'Dernière activité',
        'struggling' => 'En difficulté d\'abord',
    ];

    #[Locked]
    public Organization $organization;

    #[Url]
    public string $sort = 'name';

    public string $search = '';

    public function mount(Organization $organization): void
    {
        $this->authorize('update', $organization);
        $this->organization = $organization;
    }

    public function render()
    {
        $analytics = GroupAnalytics::for($this->organization);
        $activity = $analytics->activity(30);

        return view('livewire.admin.organizations.progress', [
            'summary' => $analytics->summary(),
            'activity' => $activity,
            'activityMax' => max(1, max(array_column($activity, 'submissions'))),
            'members' => $analytics->members(array_key_exists($this->sort, self::SORTS) ? $this->sort : 'name', trim($this->search)),
            'struggles' => $analytics->struggles(),
            'skills' => $analytics->skills(),
            'sorts' => self::SORTS,
        ]);
    }
}
