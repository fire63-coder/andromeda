<?php

namespace App\Notifications;

use App\Enums\AttemptStatus;
use App\Models\CertificationAttempt;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Résultat d'une épreuve de certification, consultable depuis le centre de notifications.
 */
class CertificationCompleted extends Notification
{
    use Queueable;

    public function __construct(public readonly CertificationAttempt $attempt) {}

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
        $attempt = $this->attempt->loadMissing('certification');
        $passed = $attempt->status === AttemptStatus::Passed;
        $title = $attempt->certification->title;

        return [
            'kind' => 'certification',
            'icon' => $passed ? '🏆' : '📋',
            'title' => $passed ? "Certification obtenue : {$title}" : "Certification non obtenue : {$title}",
            'body' => "Score : {$attempt->score} % (seuil {$attempt->certification->passing_score} %)."
                .($attempt->closed_reason === 'incidents' ? ' Épreuve close après trop d\'incidents de surveillance.' : ''),
            'url' => route('certifications.attempt', $attempt, absolute: false),
        ];
    }
}
