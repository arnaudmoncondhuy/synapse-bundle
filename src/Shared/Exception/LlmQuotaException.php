<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Shared\Exception;

/**
 * Levée quand le quota (financier ou limite de projet) est dépassé.
 */
class LlmQuotaException extends LlmException {}
