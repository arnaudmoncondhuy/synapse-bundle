<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Message;

/**
 * SynapseMessage to trigger an asynchronous preset test.
 */
class TestPresetMessage
{
    public function __construct(
        private int $presetId,
    ) {}

    public function getPresetId(): int
    {
        return $this->presetId;
    }
}
