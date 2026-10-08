<?php

namespace App\Livewire\Notifications\Concerns;

use Illuminate\Notifications\DatabaseNotification;

/**
 * Ouvrir une notification : la marquer comme lue puis suivre son lien (interne uniquement).
 */
trait OpensNotifications
{
    public function open(string $id): void
    {
        $notification = $this->find($id);
        $notification->markAsRead();
        $this->dispatch('notifications-read');

        $url = (string) ($notification->data['url'] ?? '');

        // Uniquement des chemins internes : pas de redirection vers un autre site.
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            $this->redirect($url, navigate: true);
        }
    }

    public function markAllAsRead(): void
    {
        auth()->user()->unreadNotifications()->update(['read_at' => now()]);
        $this->dispatch('notifications-read');
    }

    protected function find(string $id): DatabaseNotification
    {
        return auth()->user()->notifications()->findOrFail($id);
    }
}
