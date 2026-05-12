<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Service\Convergence;

use ArnaudMoncondhuy\SynapseCore\Brain\Service\Convergence\ConvergenceCandidate;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Convergence\ConvergenceDetector;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\SemanticNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\SynapseRelationType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class ConvergenceDetectorTest extends TestCase
{
    private function buildSemantic(string $subject, array $embedding): SemanticNeuron
    {
        $neuron = new SemanticNeuron(
            firstSource: Uuid::v7(),
            subject: $subject,
            predicate: 'is',
            value: 'val',
            confidence: 0.8,
        );
        $neuron->setEmbedding($embedding);

        return $neuron;
    }

    public function testReturnsEmptyWhenCandidateHasNoEmbedding(): void
    {
        $detector = new ConvergenceDetector();
        $candidate = $this->buildSemantic('X', []);
        $existing = [
            new ConvergenceCandidate($this->buildSemantic('X', [0.1, 0.2]), null),
        ];

        $result = $detector->detectAndLink($candidate, null, $existing);

        $this->assertSame([], $result);
    }

    public function testReturnsEmptyWhenNoCandidatesExist(): void
    {
        $detector = new ConvergenceDetector();
        $candidate = $this->buildSemantic('X', [0.1, 0.2, 0.3]);

        $result = $detector->detectAndLink($candidate, null, []);

        $this->assertSame([], $result);
    }

    public function testCreatesSynapseWhenSimilarityAboveThreshold(): void
    {
        $detector = new ConvergenceDetector();

        $candidate = $this->buildSemantic('X', [1.0, 0.0, 0.0]);
        $existing = [
            new ConvergenceCandidate(
                $this->buildSemantic('Y', [0.95, 0.05, 0.0]), // très proche
                null,
            ),
        ];

        $synapses = $detector->detectAndLink($candidate, null, $existing, threshold: 0.85);

        $this->assertCount(1, $synapses);
        $this->assertSame(SynapseRelationType::Corroborates, $synapses[0]->getRelationType());
        $this->assertGreaterThanOrEqual(0.85, $synapses[0]->getWeight());
    }

    public function testSkipsWhenSimilarityBelowThreshold(): void
    {
        $detector = new ConvergenceDetector();

        $candidate = $this->buildSemantic('X', [1.0, 0.0]);
        $existing = [
            new ConvergenceCandidate(
                $this->buildSemantic('Y', [0.0, 1.0]), // orthogonal
                null,
            ),
        ];

        $synapses = $detector->detectAndLink($candidate, null, $existing, threshold: 0.85);

        $this->assertSame([], $synapses);
    }

    public function testCreatesMultipleSynapsesForMultipleConvergences(): void
    {
        $detector = new ConvergenceDetector();

        $candidate = $this->buildSemantic('X', [1.0, 0.0]);
        $existing = [
            new ConvergenceCandidate($this->buildSemantic('A', [0.99, 0.01]), null), // proche
            new ConvergenceCandidate($this->buildSemantic('B', [0.0, 1.0]), null),   // loin
            new ConvergenceCandidate($this->buildSemantic('C', [0.95, 0.05]), null), // proche
        ];

        $synapses = $detector->detectAndLink($candidate, null, $existing, threshold: 0.85);

        $this->assertCount(2, $synapses);
    }

    public function testSkipsSelfLink(): void
    {
        // Si le caller a inclus le candidat lui-même dans la liste existing
        // par erreur, on skip
        $detector = new ConvergenceDetector();

        $candidate = $this->buildSemantic('X', [1.0, 0.0]);
        $existing = [
            new ConvergenceCandidate($candidate, null),
        ];

        $synapses = $detector->detectAndLink($candidate, null, $existing);

        $this->assertSame([], $synapses);
    }

    public function testSkipsDifferentArea(): void
    {
        // Si le caller a inclus un neurone d'aire différente par erreur,
        // on skip (sanity check)
        $detector = new ConvergenceDetector();

        $candidate = $this->buildSemantic('X', [1.0, 0.0]);

        $episodic = new \ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EpisodicNeuron(
            source: new \ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource('manual', []),
            occurredAt: new \DateTimeImmutable(),
            eventSummary: 'test',
        );
        $episodic->setEmbedding([1.0, 0.0]);

        $existing = [
            new ConvergenceCandidate($episodic, null),
        ];

        $synapses = $detector->detectAndLink($candidate, null, $existing);

        $this->assertSame([], $synapses);
    }

    public function testSkipsCrossUserSilentlyIfCallerForgotToFilter(): void
    {
        // Le caller est censé filtrer par owner_id en amont. Si jamais il a
        // oublié et passe un neurone d'un autre user, Synapse::__construct
        // throw, ConvergenceDetector skip silencieusement.
        $detector = new ConvergenceDetector();

        $alice = Uuid::v7();
        $bob = Uuid::v7();

        $candidate = $this->buildSemantic('X', [1.0, 0.0]);
        $existing = [
            new ConvergenceCandidate(
                $this->buildSemantic('Y', [0.99, 0.01]),
                $bob, // owner différent de candidateOwner (Alice)
            ),
        ];

        $synapses = $detector->detectAndLink($candidate, $alice, $existing);

        // Aucune synapse créée — le garde-fou Synapse a fait son travail
        // et ConvergenceDetector a skip silencieusement
        $this->assertSame([], $synapses);
    }

    public function testCreatesSynapseWhenSameUser(): void
    {
        $detector = new ConvergenceDetector();
        $alice = Uuid::v7();

        $candidate = $this->buildSemantic('X', [1.0, 0.0]);
        $existing = [
            new ConvergenceCandidate($this->buildSemantic('Y', [0.99, 0.01]), $alice),
        ];

        $synapses = $detector->detectAndLink($candidate, $alice, $existing);

        $this->assertCount(1, $synapses);
        $this->assertSame($alice, $synapses[0]->getSourceNeuronOwner());
        $this->assertSame($alice, $synapses[0]->getTargetNeuronOwner());
    }

    public function testCreatesSynapseFromPrivateToOpen(): void
    {
        $detector = new ConvergenceDetector();
        $alice = Uuid::v7();

        $candidate = $this->buildSemantic('X', [1.0, 0.0]);
        $existing = [
            // Neurone open (ownerId null)
            new ConvergenceCandidate($this->buildSemantic('Y', [0.99, 0.01]), null),
        ];

        $synapses = $detector->detectAndLink($candidate, $alice, $existing);

        $this->assertCount(1, $synapses);
        $this->assertSame($alice, $synapses[0]->getSourceNeuronOwner());
        $this->assertNull($synapses[0]->getTargetNeuronOwner());
    }

    public function testSynapseWeightEqualsSimilarity(): void
    {
        $detector = new ConvergenceDetector();

        $candidate = $this->buildSemantic('X', [1.0, 0.0]);
        $existing = [
            new ConvergenceCandidate($this->buildSemantic('Y', [1.0, 0.0]), null),
        ];

        $synapses = $detector->detectAndLink($candidate, null, $existing);

        // Vecteurs identiques → similarité 1.0 → weight 1.0
        $this->assertEqualsWithDelta(1.0, $synapses[0]->getWeight(), 0.0001);
        $this->assertEqualsWithDelta(1.0, $synapses[0]->getConfidence(), 0.0001);
    }
}
