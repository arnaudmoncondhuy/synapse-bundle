<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\AdminV2\Controller\Intelligence;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Core\Chat\LlmClientRegistry;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseProvider;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapsePresetRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gestion des fournisseurs LLM (Intelligence > Fournisseurs) - Admin V2
 */
#[Route('/synapse/admin-v2/intelligence', name: 'synapse_v2_admin_')]
class ProviderController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private SynapseProviderRepository $providerRepo,
        private SynapsePresetRepository $presetRepo,
        private EntityManagerInterface $em,
        private LlmClientRegistry $clientRegistry,
        private PermissionCheckerInterface $permissionChecker,
    ) {}

    /**
     * Liste des fournisseurs
     */
    #[Route('/fournisseurs', name: 'providers', methods: ['GET'])]
    public function providers(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $providers = $this->providerRepo->findAllOrdered();

        // Synchronisation avec le registre LLM (identique à V1 pour assurer la continuité)
        $existingNames = array_map(fn($p) => $p->getName(), $providers);
        $availableProviders = $this->clientRegistry->getAvailableProviders();
        $changed = false;

        foreach ($availableProviders as $name) {
            if (!in_array($name, $existingNames, true)) {
                $client = $this->clientRegistry->getClientByProvider($name);
                $provider = new SynapseProvider();
                $provider->setName($name)
                    ->setLabel($client->getDefaultLabel())
                    ->setIsEnabled(false);
                $this->em->persist($provider);
                $providers[] = $provider;
                $changed = true;
            }
        }

        if ($changed) {
            $this->em->flush();
        }

        // Comptage des presets par provider pour l'affichage des badges
        $allPresets = $this->presetRepo->findAllPresets();
        $presetCountByProvider = [];
        $providersByName = [];
        foreach ($providers as $provider) {
            $presetCountByProvider[$provider->getId()] = 0;
            $providersByName[$provider->getName()] = $provider->getId();
        }
        foreach ($allPresets as $preset) {
            $providerName = $preset->getProviderName();
            if ($providerName && isset($providersByName[$providerName])) {
                $providerId = $providersByName[$providerName];
                $presetCountByProvider[$providerId]++;
            }
        }

        return $this->render('@Synapse/admin_v2/intelligence/providers.html.twig', [
            'providers' => $providers,
            'preset_count_by_provider' => $presetCountByProvider,
        ]);
    }
}
