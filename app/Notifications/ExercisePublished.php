<?php

namespace App\Notifications;

use App\Models\Exercise;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Pour l'auteur : son exercice a été relu et publié.
 */
class ExercisePublished extends Notification
{
    use Queueable;

    public function __construct(public readonly Exercise $exercise) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'published',
            'icon' => '✅',
            'title' => "Exercice publié : {$this->exercise->title}",
            'body' => 'Il est désormais proposé aux élèves.',
            'url' => route('admin.exercises.edit', $this->exercise, absolute: false),
        ];
    }
}
