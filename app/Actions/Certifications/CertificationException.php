<?php

namespace App\Actions\Certifications;

use App\Exceptions\ContextClosed;

/**
 * Action de certification impossible. Le message est destiné au candidat.
 */
class CertificationException extends ContextClosed {}
