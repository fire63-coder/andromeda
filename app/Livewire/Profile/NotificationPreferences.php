<?php

namespace App\Livewire\Profile;

use Livewire\Component;

/**
 * Préférences de notification de l'utilisateur (profil).
 */
class NotificationPreferences extends Component
{
    public bool $assignmentEmails = true;

    public ?string $saved = null;

    public function mount(): void
    {
        $this->assignmentEmails = (bool) auth()->user()->assignment_emails;
    }

    public function updatedAssignmentEmails(): void
    {
        auth()->user()->forceFill(['assignment_emails' => $this->assignmentEmails])->save();
        $this->saved = $this->assignmentEmails ? 'E-mails des devoirs activés.' : 'E-mails des devoirs désactivés.';
    }

    public function render()
    {
        return view('livewire.profile.notification-preferences');
    }
}
