<?php

namespace App\Livewire\Notifications;

use App\Livewire\Notifications\Concerns\OpensNotifications;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Cloche de la barre de navigation : nombre de notifications non lues et les plus récentes.
 */
class NotificationBell extends Component
{
    use OpensNotifications;

    #[On('notifications-read')]
    public function refreshCount(): void
    {
        unset($this->unreadCount, $this->latest);
    }

    #[Computed]
    public function unreadCount(): int
    {
        return auth()->user()->unreadNotifications()->count();
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    #[Computed]
    public function latest(): Collection
    {
        return auth()->user()->notifications()->latest()->limit(6)->get();
    }

    public function render()
    {
        return view('livewire.notifications.bell');
    }
}
