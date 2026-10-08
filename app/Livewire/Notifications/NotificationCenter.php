<?php

namespace App\Livewire\Notifications;

use App\Livewire\Notifications\Concerns\OpensNotifications;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Centre de notifications : toutes les notifications de l'utilisateur, filtrables, à marquer lues ou supprimer.
 */
#[Title('Notifications')]
class NotificationCenter extends Component
{
    use OpensNotifications;
    use WithPagination;

    #[Url]
    public bool $unread = false;

    public function updatedUnread(): void
    {
        $this->resetPage();
    }

    public function toggleRead(string $id): void
    {
        $notification = $this->find($id);
        $notification->read() ? $notification->markAsUnread() : $notification->markAsRead();
        $this->dispatch('notifications-read');
    }

    public function delete(string $id): void
    {
        $this->find($id)->delete();
        $this->dispatch('notifications-read');
    }

    public function deleteRead(): void
    {
        auth()->user()->readNotifications()->delete();
        $this->resetPage();
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.notifications.center', [
            'notifications' => ($this->unread ? $user->unreadNotifications() : $user->notifications())->latest()->paginate(20),
            'unreadCount' => $user->unreadNotifications()->count(),
        ]);
    }
}
