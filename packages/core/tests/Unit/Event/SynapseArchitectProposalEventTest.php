<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Event;

use ArnaudMoncondhuy\SynapseCore\Event\SynapseArchitectProposalEvent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Event\SynapseArchitectProposalEvent
 */
final class SynapseArchitectProposalEventTest extends TestCase
{
    public function testConstructorAndGettersForAgentProposal(): void
    {
        $event = new SynapseArchitectProposalEvent(
            action: 'create_agent',
            entityId: 42,
            entityKey: 'translator_emoji',
            entityName: 'Traducteur Emoji',
            entityDescription: 'Convertit un texte en emoji.',
            preview: 'Tu es un traducteur...',
            inspectUrl: '/admin/intelligence/agents/42/editer',
            promoteUrl: '/admin/intelligence/agents/42/promouvoir',
            rejectUrl: '/admin/intelligence/agents/42/rejeter',
            promoteCsrfToken: 'tok_promote',
            rejectCsrfToken: 'tok_reject',
            reasoning: 'Agent minimal, prompt court.',
        );

        $this->assertSame('create_agent', $event->action);
        $this->assertSame(42, $event->entityId);
        $this->assertSame('translator_emoji', $event->entityKey);
        $this->assertSame('Traducteur Emoji', $event->entityName);
        $this->assertSame('Agent minimal, prompt court.', $event->reasoning);
    }

    public function testConstructorWorksForWorkflowProposal(): void
    {
        $event = new SynapseArchitectProposalEvent(
            action: 'create_workflow',
            entityId: 7,
            entityKey: 'doc_analysis',
            entityName: 'Analyse de doc',
            entityDescription: 'Pipeline 3 étapes.',
            preview: 'step1, step2, step3',
            inspectUrl: '/admin/intelligence/workflows/7/editer',
            promoteUrl: '/admin/intelligence/workflows/7/promouvoir',
            rejectUrl: '/admin/intelligence/workflows/7/rejeter',
            promoteCsrfToken: 'p',
            rejectCsrfToken: 'r',
        );

        $this->assertSame('create_workflow', $event->action);
        $this->assertNull($event->reasoning);
    }

    public function testToArraySerialization(): void
    {
        $event = new SynapseArchitectProposalEvent(
            action: 'create_agent',
            entityId: 1,
            entityKey: 'k',
            entityName: 'n',
            entityDescription: 'd',
            preview: 'p',
            inspectUrl: '/i',
            promoteUrl: '/p',
            rejectUrl: '/r',
            promoteCsrfToken: 'tp',
            rejectCsrfToken: 'tr',
            reasoning: 'why',
        );

        $arr = $event->toArray();
        $this->assertSame('create_agent', $arr['action']);
        $this->assertSame(1, $arr['entity_id']);
        $this->assertSame('k', $arr['entity_key']);
        $this->assertSame('n', $arr['entity_name']);
        $this->assertSame('d', $arr['entity_description']);
        $this->assertSame('p', $arr['preview']);
        $this->assertSame('/i', $arr['inspect_url']);
        $this->assertSame('/p', $arr['promote_url']);
        $this->assertSame('/r', $arr['reject_url']);
        $this->assertSame('tp', $arr['promote_csrf_token']);
        $this->assertSame('tr', $arr['reject_csrf_token']);
        $this->assertSame('why', $arr['reasoning']);
    }

    public function testImmutability(): void
    {
        $event = new SynapseArchitectProposalEvent(
            action: 'create_agent',
            entityId: 1,
            entityKey: 'k',
            entityName: 'n',
            entityDescription: '',
            preview: '',
            inspectUrl: '',
            promoteUrl: '',
            rejectUrl: '',
            promoteCsrfToken: '',
            rejectCsrfToken: '',
        );

        // Les propriétés sont readonly — toute tentative de mutation échoue.
        $this->assertIsObject($event);
        $reflection = new \ReflectionClass($event);
        foreach ($reflection->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly(), sprintf('Property %s should be readonly', $prop->getName()));
        }
    }
}
