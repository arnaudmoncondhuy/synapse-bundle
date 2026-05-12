<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Bridge;

use ArnaudMoncondhuy\SynapseCore\Brain\Bridge\Doctrine\TablePrefixSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;

class TablePrefixSubscriberTest extends TestCase
{
    /**
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

    public function testSubscribesToLoadClassMetadata(): void
    {
        $subscriber = new TablePrefixSubscriber('syn_');

        $this->assertContains(Events::loadClassMetadata, $subscriber->getSubscribedEvents());
    }

    public function testPrefixesBrainTable(): void
    {
        [$metadata, $event] = $this->buildMetadata(
            'ArnaudMoncondhuy\\SynapseCore\\Storage\\Entity\\Brain\\MemorySource',
            'brain_memory_source',
        );

        $subscriber = new TablePrefixSubscriber('syn_');
        $subscriber->loadClassMetadata($event);

        $this->assertSame('syn_brain_memory_source', $metadata->getTableName());
    }

    public function testPrefixesCoreTable(): void
    {
        [$metadata, $event] = $this->buildMetadata(
            'ArnaudMoncondhuy\\SynapseCore\\Storage\\Entity\\SynapseAgent',
            'core_agent',
        );

        $subscriber = new TablePrefixSubscriber('syn_');
        $subscriber->loadClassMetadata($event);

        $this->assertSame('syn_core_agent', $metadata->getTableName());
    }

    public function testCustomPrefixIsApplied(): void
    {
        [$metadata, $event] = $this->buildMetadata(
            'ArnaudMoncondhuy\\SynapseCore\\Storage\\Entity\\Brain\\Synapse',
            'brain_synapse',
        );

        $subscriber = new TablePrefixSubscriber('acme_');
        $subscriber->loadClassMetadata($event);

        $this->assertSame('acme_brain_synapse', $metadata->getTableName());
    }

    public function testLegacySynapseTableIsNotPrefixed(): void
    {
        [$metadata, $event] = $this->buildMetadata(
            'ArnaudMoncondhuy\\SynapseCore\\Storage\\Entity\\SynapseAgent',
            'synapse_agent',
        );

        $subscriber = new TablePrefixSubscriber('syn_');
        $subscriber->loadClassMetadata($event);

        // Les tables synapse_* legacy sont laissées telles quelles
        // (transition douce — renommage prévu au jalon 8)
        $this->assertSame('synapse_agent', $metadata->getTableName());
    }

    public function testNonSynapseEntityIsIgnored(): void
    {
        [$metadata, $event] = $this->buildMetadata(
            'App\\Entity\\Brain',
            'brain_custom',
        );

        $subscriber = new TablePrefixSubscriber('syn_');
        $subscriber->loadClassMetadata($event);

        // Hors namespace SynapseCore → on ne touche pas même si le nom
        // de table commence par 'brain_' (l'app hôte est souveraine)
        $this->assertSame('brain_custom', $metadata->getTableName());
    }

    public function testIdempotenceOnAlreadyPrefixedTable(): void
    {
        [$metadata, $event] = $this->buildMetadata(
            'ArnaudMoncondhuy\\SynapseCore\\Storage\\Entity\\Brain\\MemorySource',
            'syn_brain_memory_source',
        );

        $subscriber = new TablePrefixSubscriber('syn_');
        $subscriber->loadClassMetadata($event);

        // Pas de double-préfixage
        $this->assertSame('syn_brain_memory_source', $metadata->getTableName());
    }
}
