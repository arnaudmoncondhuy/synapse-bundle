<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval;

use ArnaudMoncondhuy\SynapseCore\Brain\Event\SynapseReinforcedEvent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Synapse;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Renforce les poids des synapses co-activées dans un retrieval Hebbien
 * (pattern *fire together, wire together*, design §8).
 *
 * **Saturation soft** (ADR-009) : `newWeight = oldWeight + delta × (1 − oldWeight)`.
 * Asymptote vers 1.0 sans jamais l'atteindre — distinction préservée entre
 * synapses très renforcées et synapses peu renforcées.
 *
 * **Event-driven** (ADR-009 / mémoire feedback-brain-event-driven-synapse-mutations) :
 * dispatche `SynapseReinforcedEvent` après chaque modification. Les sécurités
 * futures (anti-emballement avancé, compétition latérale, audit) s'ajoutent
 * comme listeners sans refactor de ce service.
 *
 * Cf. {@link docs/brain/06-phases/jalon-4-retrieval-hebbien.md} §4.5.
 */
final readonly class HebbianReinforcer implements HebbianReinforcerInterface
{
    /**
     * Taux d'apprentissage par défaut (delta).
     *
     * Conservatif : à δ=0.05, après 20 renforcements w(0.5) ≈ 0.82, après 50
     * w ≈ 0.96. À recalibrer empiriquement (bench retrieval ou test
     * profondeur sur corpus réel).
     */
    public const DEFAULT_DELTA = 0.05;

    public function __construct(
        private EventDispatcherInterface $dispatcher,
        private float $delta = self::DEFAULT_DELTA,
    ) {
    }

    /**
     * Renforce une synapse en place : applique la saturation soft + clamp [0,1]
     * + incrémente evidenceCount + dispatche l'event.
     *
     * Idempotent au sens "appel répété fait avancer asymptotiquement" — pas
     * d'effet runtime si oldWeight déjà à 1.0 (delta × 0 = 0).
     */
    public function reinforce(Synapse $synapse, string $cause = 'hebbian_co_activation'): void
    {
        $oldWeight = $synapse->getWeight();
        $newWeight = $oldWeight + $this->delta * (1.0 - $oldWeight);
        $newWeight = max(0.0, min(1.0, $newWeight));

        // Pas de mutation si la saturation est atteinte (évite des events spurieux)
        if (abs($newWeight - $oldWeight) < 1e-9) {
            return;
        }

        $synapse->setWeight($newWeight);
        $synapse->recordCorroboration();

        $this->dispatcher->dispatch(
            new SynapseReinforcedEvent(
                synapse: $synapse,
                oldWeight: $oldWeight,
                newWeight: $newWeight,
                cause: $cause,
            ),
        );
    }

    /**
     * Renforce toutes les synapses d'une liste — utile pour appliquer après
     * un retrieval (toutes les synapses traversées sont co-activées).
     *
     * @param iterable<Synapse> $synapses
     */
    public function reinforceAll(iterable $synapses, string $cause = 'hebbian_co_activation'): void
    {
        foreach ($synapses as $synapse) {
            $this->reinforce($synapse, $cause);
        }
    }
}
