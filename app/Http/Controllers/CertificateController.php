<?php

namespace App\Http\Controllers;

use App\Enums\AttemptStatus;
use App\Models\CertificationAttempt;
use Illuminate\View\View;

/**
 * Page publique de vérification d'un certificat (lien partageable, imprimable).
 */
class CertificateController extends Controller
{
    public function __invoke(string $code): View
    {
        $attempt = CertificationAttempt::query()
            ->where('certificate_code', $code)
            ->where('status', AttemptStatus::Passed)
            ->with(['user:id,name', 'certification.level', 'certification.dialect'])
            ->firstOrFail();

        return view('certificates.show', ['attempt' => $attempt]);
    }
}
