<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Controller\Systeme;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\SynapseDebugLoggerInterface;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseDebugLog;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseLlmCall;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseDebugLogRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseLlmCallRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DebugDetailController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private readonly SynapseDebugLoggerInterface $debugLogger,
        private readonly PermissionCheckerInterface $permissionChecker,
        private readonly SynapseDebugLogRepository $debugLogRepo,
        private readonly SynapseLlmCallRepository $llmCallRepo,
    ) {
    }

    #[Route('/synapse/_debug/{id}', name: 'synapse_debug_show', methods: ['GET'])]
    public function show(string $id): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $debug = $this->debugLogger->findByDebugId($id);

        if (!$debug) {
            return new Response('Debug data expired or not found. It might have been cleared or the cache expired.', 404);
        }

        // Cherche les appels enfants dans l'arborescence d'exécution d'agents.
        $children = [];
        $entity = $this->debugLogRepo->findByDebugId($id);
        if (null !== $entity && null !== $entity->getAgentRunId()) {
            $children = $this->debugLogRepo->findChildrenOfRun($entity->getAgentRunId());
        }

        // Charge le SynapseLlmCall correspondant (source unique pour tokens/coûts).
        // Stratégie : (1) lien direct via call_id, (2) fallback par created_at ± 2s + model.
        $llmCall = null;
        $data = $debug['data'] ?? [];
        $callId = is_string($data['call_id'] ?? null) ? $data['call_id'] : null;
        if (null !== $callId) {
            $llmCall = $this->llmCallRepo->findOneBy(['callId' => $callId]);
        }
        if (null === $llmCall && null !== $entity) {
            $llmCall = $this->findLlmCallByProximity($entity);
        }

        return $this->render('@Synapse/admin/systeme/debug_detail.html.twig', [
            'id' => $id,
            'debug' => $debug,
            'children' => $children,
            'llm_call' => $llmCall,
        ]);
    }

    /**
     * Fallback : retrouve le SynapseLlmCall le plus proche par timestamp + model
     * pour les debug logs créés avant l'ajout de call_id dans le payload.
     */
    private function findLlmCallByProximity(SynapseDebugLog $debugLog): ?SynapseLlmCall
    {
        $createdAt = $debugLog->getCreatedAt();
        $model = $debugLog->getModel();
        if (null === $createdAt || null === $model) {
            return null;
        }

        $start = \DateTimeImmutable::createFromMutable(
            (clone \DateTime::createFromInterface($createdAt))->modify('-3 seconds')
        );
        $end = \DateTimeImmutable::createFromMutable(
            (clone \DateTime::createFromInterface($createdAt))->modify('+3 seconds')
        );

        $results = $this->llmCallRepo->createQueryBuilder('c')
            ->where('c.model = :model')
            ->andWhere('c.createdAt >= :start')
            ->andWhere('c.createdAt <= :end')
            ->setParameter('model', $model)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $results instanceof SynapseLlmCall ? $results : null;
    }
}
