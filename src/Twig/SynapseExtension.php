<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class SynapseExtension extends AbstractExtension
{
    public function __construct(
        private \ArnaudMoncondhuy\SynapseBundle\Service\PersonaRegistry $personaRegistry,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('synapse_chat_widget', [SynapseRuntime::class, 'renderWidget'], ['is_safe' => ['html']]),
            new TwigFunction('synapse_get_personas', [$this->personaRegistry, 'getAll']),
        ];
    }
}
