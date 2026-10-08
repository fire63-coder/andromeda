<?php

namespace App\Notifications;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Pour les administrateurs : un formateur a soumis (ou modifié) un exercice à relire.
 */
class ExerciseAwaitingReview extends Notification
{
    use Queueable;

    public function __construct(public readonly Exercise $exercise, public readonly User $author) {}

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
            'kind' => 'review',
            'icon' => '🔎',
            'title' => "Exercice à relire : {$this->exercise->title}",
            'body' => "Soumis par {$this->author->name}.",
            'url' => route('admin.exercises.edit', $this->exercise, absolute: false),
        ];
    }
}
