<?php

namespace App\Services\Sandbox\Exceptions;

use RuntimeException;

/**
 * Requête refusée avant exécution. Le message est destiné à l'élève.
 */
class QueryRejected extends RuntimeException {}
