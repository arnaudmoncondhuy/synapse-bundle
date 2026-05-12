<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Bridge;

use ArnaudMoncondhuy\SynapseCore\Brain\Bridge\Doctrine\TablePrefixSubscriber;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Synapse;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;

class TablePrefixSubscriberTest extends TestCase
{
    /**
     * Forge un ClassMetadata Doctrine pour une classe réellement existante
     * + un nom de table de notre choix. On utilise des classes réelles
     * (pas des noms en string) pour que les tests soient sensibles aux
     * renommages et refactorings (audit jalon 1 — point D).
     *
     * @param class-string $className
     *
     * @return array{0: ClassMetadata<object>, 1: LoadClassMetadataEventArgs}
     */
    private function buildMetadata(string $className, string $tableName): array
    {
        /** @var ClassMetadata<object> $metadata */
        $metadata = new ClassMetadata($className);
        $metadata->setPrimaryTable(['name' => $tableName]);

        $em = $this->createStub(EntityManagerInterface::class);
        $event = new LoadClassMetadataEventArgs($metadata, $em);

        return [$metadata, $event];
    }

    public function testHasAsDoctrineListenerAttributeForLoadClassMetadata(): void
    {
        $reflection = new \ReflectionClass(TablePrefixSubscriber::class);
        $attributes = $reflection->getAttributes(AsDoctrineListener::class);

        $this->assertCount(1, $attributes, 'TablePrefixSubscriber doit déclarer #[AsDoctrineListener]');

        $attr = $attributes[0]->newInstance();
        $this->assertSame(Events::loadClassMetadata, $attr->event);
    }

    public function testPrefixesBrainTable(): void
    {
        [$metadata, $event] = $this->buildMetadata(MemorySource::class, 'brain_memory_source');

        $subscriber = new TablePrefixSubscriber('syn_');
        $subscriber->loadClassMetadata($event);

        $this->assertSame('syn_brain_memory_source', $metadata->getTableName());
    }

    public function testPrefixesCoreTable(): void
    {
        // Au jalon 1 il n'existe pas encore d'entité Brain v3 préfixée 'core_'
        // (le renommage des entités legacy synapse_* → core_* est prévu au
        // jalon 8). On utilise donc une classe SynapseCore existante avec un
        // tableName forgé en 'core_simulated' pour valider le comportement
        // du subscriber sur ce préfixe.
        [$metadata, $event] = $this->buildMetadata(SynapseAgent::class, 'core_simulated');

        $subscriber = new TablePrefixSubscriber('syn_');
        $subscriber->loadClassMetadata($event);

        $this->assertSame('syn_core_simulated', $metadata->getTableName());
    }

    public function testCustomPrefixIsApplied(): void
    {
        [$metadata, $event] = $this->buildMetadata(Synapse::class, 'brain_synapse');

        $subscriber = new TablePrefixSubscriber('acme_');
        $subscriber->loadClassMetadata($event);

        $this->assertSame('acme_brain_synapse', $metadata->getTableName());
    }

    public function testLegacySynapseTableIsNotPrefixed(): void
    {
        // SynapseAgent réelle (table actuelle 'synapse_agent') — les tables
        // legacy sont laissées telles quelles, renommage prévu au jalon 8.
        [$metadata, $event] = $this->buildMetadata(SynapseAgent::class, 'synapse_agent');

        $subscriber = new TablePrefixSubscriber('syn_');
        $subscriber->loadClassMetadata($event);

        $this->assertSame('synapse_agent', $metadata->getTableName());
    }

    public function testNonSynapseEntityIsIgnored(): void
    {
        // Classe d'app hôte fictive (string OK ici car on teste justement
        // l'isolation hors namespace SynapseCore).
        [$metadata, $event] = $this->buildMetadata(\stdClass::class, 'brain_custom');

        $subscriber = new TablePrefixSubscriber('syn_');
        $subscriber->loadClassMetadata($event);

        // Hors namespace SynapseCore → on ne touche pas même si le nom
        // de table commence par 'brain_' (l'app hôte est souveraine)
        $this->assertSame('brain_custom', $metadata->getTableName());
    }

    public function testIdempotenceOnAlreadyPrefixedTable(): void
    {
        [$metadata, $event] = $this->buildMetadata(MemorySource::class, 'syn_brain_memory_source');

        $subscriber = new TablePrefixSubscriber('syn_');
        $subscriber->loadClassMetadata($event);

        // Pas de double-préfixage
        $this->assertSame('syn_brain_memory_source', $metadata->getTableName());
    }
}
