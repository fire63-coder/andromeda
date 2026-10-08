<?php

namespace App\Actions\Assignments;

use App\Models\Assignment;
use App\Models\User;
use App\Notifications\AssignmentDueSoon;
use App\Notifications\AssignmentPublished;
use App\Services\Learning\AssignmentProgress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * E-mails des devoirs, chacun envoyé une seule fois :
 * - à la publication, à tous les élèves de l'organisation ;
 * - la veille de l'échéance, aux élèves qui n'ont pas terminé.
 * Seuls les comptes actifs qui n'ont pas désactivé ces e-mails sont concernés.
 */
class SendAssignmentNotifications
{
    public function __construct(private readonly AssignmentProgress $progress) {}

    public function published(Assignment $assignment): int
    {
        // Verrou logique : un seul envoi, même si deux enregistrements arrivent en même temps.
        $claimed = DB::table('assignments')->where('id', $assignment->id)->whereNotNull('published_at')->whereNull('notified_at')
            ->update(['notified_at' => now()]);

        if (! $claimed) {
            return 0;
        }

        $recipients = $this->recipients($assignment);
        Notification::send($recipients, new AssignmentPublished($assignment));

        return $recipients->count();
    }

    /**
     * Devoirs dont l'échéance tombe dans les prochaines 24 heures, pas encore rappelés.
     */
    public function dueSoon(): int
    {
        $sent = 0;

        $assignments = Assignment::query()
            ->published()
            ->whereNull('reminded_at')
            ->whereBetween('due_at', [now(), now()->addDay()])
            ->with('exercises:id')
            ->get();

        foreach ($assignments as $assignment) {
            $claimed = DB::table('assignments')->where('id', $assignment->id)->whereNull('reminded_at')->update(['reminded_at' => now()]);

            if (! $claimed) {
                continue;
            }

            foreach ($this->recipients($assignment) as $student) {
                ['done' => $done, 'total' => $total] = $this->progress->forUser($assignment, $student);

                if ($done < $total) {
                    $student->notify(new AssignmentDueSoon($assignment, $done, $total));
                    $sent++;
                }
            }
        }

        return $sent;
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(Assignment $assignment): Collection
    {
        return $assignment->organization->members()
            ->wherePivot('role', 'member')
            ->where('users.is_active', true)
            ->where('users.assignment_emails', true)
            ->get();
    }
}
