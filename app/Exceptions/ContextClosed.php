<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * L'épreuve (certification, défi) n'accepte plus de réponse. Message destiné à l'élève.
 */
class ContextClosed extends RuntimeException {}
