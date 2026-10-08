<?php

namespace App\Notifications;

use App\Models\Assignment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AssignmentDueSoon extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Assignment $assignment,
        public readonly int $done,
        public readonly int $total,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        // Toujours dans l'application ; par e-mail sauf si l'élève l'a désactivé dans son profil.
        return ($notifiable->assignment_emails ?? true) ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $remaining = $this->total - $this->done;

        return (new MailMessage)
            ->subject("Rappel : « {$this->assignment->title} » est à rendre demain")
            ->greeting("Bonjour {$notifiable->name},")
            ->line("Le devoir **{$this->assignment->title}** est à rendre le ".$this->assignment->due_at->isoFormat('dddd D MMMM à HH:mm').'.')
            ->line("Il vous reste {$remaining} exercice(s) sur {$this->total} à réussir.")
            ->action('Terminer le devoir', route('assignments.show', $this->assignment))
            ->line('Vous pouvez désactiver ces e-mails depuis votre profil, rubrique « Notifications ».');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'assignment_due',
            'icon' => '⏰',
            'title' => "« {$this->assignment->title} » est à rendre demain",
            'body' => 'Il vous reste '.($this->total - $this->done)." exercice(s) sur {$this->total} à réussir.",
            'url' => route('assignments.show', $this->assignment, absolute: false),
        ];
    }
}
