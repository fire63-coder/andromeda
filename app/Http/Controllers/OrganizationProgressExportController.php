<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\Analytics\GroupAnalytics;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export CSV de la progression des membres (séparateur « ; » et BOM UTF-8 : s'ouvre tel quel dans Excel).
 */
class OrganizationProgressExportController extends Controller
{
    public function __invoke(Organization $organization): StreamedResponse
    {
        Gate::authorize('update', $organization);

        $members = GroupAnalytics::for($organization)->members();
        $filename = 'suivi-'.$organization->slug.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($members) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Nom', 'E-mail', 'Rôle', 'XP', 'Rang', 'Exercices résolus', 'Soumissions', 'Taux de réussite (%)', 'Leçons terminées', 'Certifications', 'Dernière activité'], ';');

            foreach ($members as $row) {
                fputcsv($out, [
                    $row['name'], $row['email'], $row['role'] === 'manager' ? 'Responsable' : 'Membre', $row['xp'], $row['rank'],
                    $row['solved'], $row['submissions'], $row['success_rate'], $row['lessons'], $row['certifications'],
                    $row['last_activity']?->format('Y-m-d H:i'),
                ], ';');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
