<?php

namespace App\Services\Sandbox\Exceptions;

use RuntimeException;

/**
 * Le moteur demandé n'est pas exécutable (dialecte désactivé, build absent, serveur injoignable).
 */
class SandboxUnavailable extends RuntimeException {}
