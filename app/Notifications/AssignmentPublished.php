<?php

namespace App\Notifications;

use App\Models\Assignment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class AssignmentPublished extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Assignment $assignment) {}

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
        $assignment = $this->assignment->loadMissing('organization:id,name')->loadCount('exercises');

        $message = (new MailMessage)
            ->subject("Nouveau devoir : {$assignment->title}")
            ->greeting("Bonjour {$notifiable->name},")
            ->line("Un nouveau devoir vous a été assigné dans « {$assignment->organization->name} » : **{$assignment->title}**.")
            ->line($assignment->exercises_count.' exercice(s)'.($assignment->due_at ? ', à rendre avant le '.$assignment->due_at->isoFormat('dddd D MMMM YYYY à HH:mm').'.' : ', sans échéance.'));

        if ($assignment->instructions) {
            $message->line('Consignes : '.Str::limit($assignment->instructions, 400));
        }

        return $message
            ->action('Voir le devoir', route('assignments.show', $assignment))
            ->line('Vous pouvez désactiver ces e-mails depuis votre profil, rubrique « Notifications ».');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'assignment',
            'icon' => '📝',
            'title' => "Nouveau devoir : {$this->assignment->title}",
            'body' => $this->assignment->due_at ? 'À rendre avant le '.$this->assignment->due_at->isoFormat('D MMMM YYYY à HH:mm').'.' : 'Sans échéance.',
            'url' => route('assignments.show', $this->assignment, absolute: false),
        ];
    }
}
