<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Admin\Controller\Intelligence;

use ArnaudMoncondhuy\SynapseCore\Contract\EncryptionServiceInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Core\Chat\LlmClientRegistry;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseProvider;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapsePresetRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Gestion des fournisseurs LLM (Intelligence > Fournisseurs) - Administration Synapse
 */
#[Route('/synapse/admin/intelligence', name: 'synapse_admin_')]
class ProviderController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private SynapseProviderRepository $providerRepo,
        private SynapsePresetRepository $presetRepo,
        private EntityManagerInterface $em,
        private LlmClientRegistry $clientRegistry,
        private PermissionCheckerInterface $permissionChecker,
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
        private ?EncryptionServiceInterface $encryptionService = null,
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

        return $this->render('@Synapse/admin/intelligence/providers.html.twig', [
            'providers' => $providers,
            'preset_count_by_provider' => $presetCountByProvider,
        ]);
    }

    /**
     * Édition des credentials d'un provider (Administration Synapse)
     */
    #[Route('/providers/{id}/edit', name: 'providers_edit', methods: ['GET', 'POST'])]
    public function edit(SynapseProvider $provider, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_provider_edit_' . $provider->getId());
            $data = $request->request->all();

            $provider->setLabel($data['label'] ?? $provider->getLabel());
            $provider->setIsEnabled((bool) ($data['is_enabled'] ?? false));

            // Credentials dynamiques selon le client LLM
            $client = $this->clientRegistry->getClientByProvider($provider->getName());
            $fields = $client->getCredentialFields();
            $currentCredentials = $provider->getCredentials();
            $credentials = [];

            if ($provider->getName() === 'custom' || empty($fields)) {
                $credentials = json_decode($data['credentials_raw'] ?? '{}', true) ?? [];
            } else {
                foreach ($fields as $fieldName => $fieldConfig) {
                    $value = trim($data[$fieldName] ?? '');
                    if (empty($value) && !empty($currentCredentials[$fieldName])) {
                        // Conserver la valeur existante (utile pour les passwords/fichiers volumineux)
                        $value = $currentCredentials[$fieldName];
                    }
                    $credentials[$fieldName] = $value;
                }
            }

            $credentials = $this->encryptCredentials($credentials);
            $provider->setCredentials($credentials);

            $this->em->flush();

            $this->addFlash('success', 'Provider "' . $provider->getLabel() . '" mis à jour.');

            return $this->redirectToRoute('synapse_admin_configuration_llm', ['tab' => 'fournisseurs']);
        }

        $client = $this->clientRegistry->getClientByProvider($provider->getName());

        return $this->render('@Synapse/admin/intelligence/provider_edit.html.twig', [
            'provider' => $provider,
            'fields'   => $client->getCredentialFields(),
        ]);
    }

    /**
     * Test des credentials d'un provider (Administration Synapse)
     */
    #[Route('/providers/{id}/test', name: 'providers_test', methods: ['POST'])]
    public function test(SynapseProvider $provider, Request $request): JsonResponse
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_provider_test_' . $provider->getId());

        if (!$provider->isConfigured()) {
            return new JsonResponse(['success' => false, 'error' => 'Provider non configuré']);
        }

        try {
            $client = $this->clientRegistry->getClientByProvider($provider->getName());
            $creds = $this->decryptCredentials($provider->getCredentials());
            $client->validateCredentials($creds);

            return new JsonResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Chiffre les champs sensibles des credentials avant la sauvegarde en base.
     */
    private function encryptCredentials(array $credentials): array
    {
        if ($this->encryptionService === null) {
            return $credentials;
        }

        foreach (['api_key', 'service_account_json', 'private_key', 'token'] as $key) {
            if (!empty($credentials[$key]) && !$this->encryptionService->isEncrypted($credentials[$key])) {
                $credentials[$key] = $this->encryptionService->encrypt($credentials[$key]);
            }
        }

        return $credentials;
    }

    /**
     * Déchiffre les champs sensibles des credentials avant usage.
     */
    private function decryptCredentials(array $credentials): array
    {
        if ($this->encryptionService === null) {
            return $credentials;
        }

        foreach (['api_key', 'service_account_json', 'private_key', 'token'] as $key) {
            if (!empty($credentials[$key]) && $this->encryptionService->isEncrypted($credentials[$key])) {
                $credentials[$key] = $this->encryptionService->decrypt($credentials[$key]);
            }
        }

        return $credentials;
    }
}
