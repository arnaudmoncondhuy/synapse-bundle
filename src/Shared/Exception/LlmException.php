<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Shared\Exception;

/**
 * Exception de base pour les erreurs liées aux clients LLM.
 */
class LlmException extends \RuntimeException implements SynapseException {}
