<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Controller\Api;

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
        private CacheInterface $cache,
    ) {
    }

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
        // Try to fetch from cache
        $data = $this->cache->get("synapse_debug_{$id}", function (ItemInterface $item) {
            // If not found, it means it expired or doesn't exist
            return null;
        });

        if (null === $data) {
            return new Response('Debug data expired or not found.', 404);
        }

        return $this->render('@Synapse/debug/show.html.twig', [
            'id' => $id,
            'debug' => $data,
        ]);
    }
}
