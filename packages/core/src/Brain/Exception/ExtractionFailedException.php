<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Exception;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;

/**
 * Levée par un extracteur Brain v3 quand l'extraction échoue.
 *
 * Inclut le contexte (source UUID, aire visée, raison) pour faciliter le
 * debug et permettre une éventuelle reprise via `RevectorizeCommand` plus
 * tard (pattern Prisma : la source brute reste immutable, l'extraction
 * peut être rejouée).
 */
final class ExtractionFailedException extends \RuntimeException
{
    public function __construct(
        public readonly MemorySource $source,
        public readonly BrainArea $area,
        string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Brain extraction failed (source=%s, area=%s): %s',
                $source->getId()->toRfc4122(),
                $area->value,
                $reason,
            ),
            0,
            $previous,
        );
    }
}
