<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Admin\Controller;

use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseDebugLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Contrôleur pour l'interface de débogage interne.
 *
 * Permet d'afficher les détails complets d'une session de chat (prompts, réponses brutes, usage des outils)
 * via un lien généré lorque le mode 'debug' est actif.
 */
#[Route('/synapse')]
class DebugController extends AbstractController
{
    public function __construct(
        private SynapseDebugLogRepository $debugLogRepo,
        private CacheInterface $cache,
    ) {}

    /**
     * Affiche le rapport de debug pour un échange spécifique.
     *
     * Les données sont récupérées depuis le cache Symfony (TTL 1h).
     *
     * @param string $id L'identifiant unique de la trace de debug
     *
     * @return Response la page HTML de rapport
     */
    #[Route('/_debug/{id}', name: 'synapse_debug_show', methods: ['GET'])]
    public function show(string $id): Response
    {
        // 1. Try to fetch from cache first (has fresh, complete data)
        $data = null;
        try {
            $data = $this->cache->get("synapse_debug_{$id}", function (ItemInterface $item) {
                // Cache miss - return placeholder to indicate cache was empty
                $item->expiresAfter(86400);
                return null;
            });
        } catch (\Exception $e) {
            // Cache error, will fallback to DB
        }

        // 2. Fallback to DB if cache missed
        if (null === $data) {
            $debugLog = $this->debugLogRepo->findByDebugId($id);
            if ($debugLog !== null) {
                $data = $debugLog->getData();
                // Re-populate cache for next time
                try {
                    $this->cache->get("synapse_debug_{$id}", function (ItemInterface $item) use ($data) {
                        $item->expiresAfter(86400);
                        return $data;
                    });
                } catch (\Exception $e) {
                    // Cache write error, but data is still available
                }
            }
        }

        if (null === $data) {
            return new Response('Debug data expired or not found.', 404);
        }

        // Debug: log what we're about to render
        error_log("DebugController: Rendering with data keys: " . implode(', ', array_keys($data)));
        error_log("DebugController: Turns count: " . count($data['turns'] ?? []));

        return $this->render('@Synapse/debug/show.html.twig', [
            'id' => $id,
            'debug' => $data,
        ]);
    }
}
