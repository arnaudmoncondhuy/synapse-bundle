<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Service\Retrieval;

use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\RetrievalQuery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class RetrievalQueryTest extends TestCase
{
    public function testConstructWithDefaults(): void
    {
        $q = new RetrievalQuery('problème ordinateur');

        $this->assertSame('problème ordinateur', $q->text);
        $this->assertNull($q->ownerId);
        $this->assertSame(10, $q->topN);
        // ADR-007 amendé : maxDepth=5 (sécurité), critère principal = minScore
        $this->assertSame(5, $q->maxDepth);
        $this->assertSame(0.1, $q->minScore);
    }

    public function testConstructWithCustomValues(): void
    {
        $owner = Uuid::v7();
        $q = new RetrievalQuery(
            text: 'requête custom',
            ownerId: $owner,
            topN: 20,
            maxDepth: 3,
            minScore: 0.25,
        );

        $this->assertSame('requête custom', $q->text);
        $this->assertSame($owner, $q->ownerId);
        $this->assertSame(20, $q->topN);
        $this->assertSame(3, $q->maxDepth);
        $this->assertSame(0.25, $q->minScore);
    }

    public function testRejectsEmptyText(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/text cannot be empty/');

        new RetrievalQuery('');
    }

    public function testRejectsWhitespaceOnlyText(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RetrievalQuery('   ');
    }

    public function testRejectsTopNBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/topN must be >= 1/');

        new RetrievalQuery('test', topN: 0);
    }

    public function testRejectsNegativeMaxDepth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/maxDepth must be >= 0/');

        new RetrievalQuery('test', maxDepth: -1);
    }

    public function testRejectsMinScoreOutOfRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RetrievalQuery('test', minScore: 1.5);
    }

    public function testRejectsNegativeMinScore(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RetrievalQuery('test', minScore: -0.1);
    }

    public function testMaxDepthZeroIsAllowedForSeedOnlyRetrieval(): void
    {
        // maxDepth=0 = seuls les seeds, pas de propagation. Cas valide
        $q = new RetrievalQuery('test', maxDepth: 0);

        $this->assertSame(0, $q->maxDepth);
    }
}
