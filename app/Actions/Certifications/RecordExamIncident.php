<?php

namespace App\Actions\Certifications;

use App\Models\CertificationAttempt;
use Illuminate\Support\Facades\DB;

/**
 * Journalise un incident signalé par le navigateur pendant une épreuve en mode examen.
 *
 * Les sorties (plein écran, onglet, fenêtre) sont comptées ; une rafale d'événements liés au même
 * geste (quitter le plein écran déclenche souvent aussi un changement d'onglet) ne compte qu'une fois.
 * Au-delà du nombre toléré par la certification, l'épreuve est close avec les réponses déjà données.
 */
class RecordExamIncident
{
    /** Deux incidents comptés à moins de cet intervalle n'en font qu'un. */
    private const BURST_SECONDS = 3;

    /** Taille maximale du journal conservé par tentative. */
    private const MAX_LOGGED = 200;

    public function __construct(private readonly FinishCertificationAttempt $finish) {}

    /**
     * @return array{counted: bool, count: int, limit: ?int, closed: bool}
     */
    public function handle(CertificationAttempt $attempt, string $type): array
    {
        $counted = in_array($type, CertificationAttempt::COUNTED_INCIDENTS, true);

        if (! $counted && ! in_array($type, CertificationAttempt::LOGGED_INCIDENTS, true)) {
            throw new CertificationException('Incident inconnu.');
        }

        $result = DB::transaction(function () use ($attempt, $type, $counted) {
            $attempt = $attempt->newQuery()->with('certification')->lockForUpdate()->findOrFail($attempt->id);
            $limit = $attempt->certification->max_incidents;

            if (! $attempt->isSecureExam()) {
                return ['attempt' => $attempt, 'counted' => false, 'count' => $attempt->incidents_count, 'limit' => $limit, 'close' => false];
            }

            $incidents = $attempt->incidents ?? [];
            $lastCounted = collect($incidents)->last(fn (array $incident) => $incident['counted'] ?? false);
            $counted = $counted && (! $lastCounted || now()->diffInSeconds($lastCounted['at'], true) >= self::BURST_SECONDS);

            if (count($incidents) < self::MAX_LOGGED) {
                $incidents[] = ['type' => $type, 'at' => now()->toIso8601String(), 'counted' => $counted];
            }

            $count = $attempt->incidents_count + ($counted ? 1 : 0);
            $attempt->update(['incidents' => $incidents, 'incidents_count' => $count]);

            return ['attempt' => $attempt, 'counted' => $counted, 'count' => $count, 'limit' => $limit, 'close' => $limit !== null && $count > $limit];
        });

        if ($result['close']) {
            $this->finish->handle($result['attempt'], 'incidents');
        }

        return ['counted' => $result['counted'], 'count' => $result['count'], 'limit' => $result['limit'], 'closed' => $result['close']];
    }
}
