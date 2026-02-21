<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Controller\Admin;

use ArnaudMoncondhuy\SynapseBundle\Entity\SynapseProvider;
use ArnaudMoncondhuy\SynapseBundle\Repository\SynapseProviderRepository;
use ArnaudMoncondhuy\SynapseBundle\Repository\SynapseConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Gestion des providers LLM (Gemini, OVH, etc.)
 *
 * Permet de configurer les credentials de chaque provider sans toucher au YAML.
 */
#[Route('/synapse/admin/providers')]
class ProvidersController extends AbstractController
{
    public function __construct(
        private SynapseProviderRepository $providerRepo,
        private SynapseConfigRepository $configRepo,
        private EntityManagerInterface $em,
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Liste des providers
     */
    #[Route('', name: 'synapse_admin_providers', methods: ['GET'])]
    public function index(): Response
    {
        $providers = $this->providerRepo->findAllOrdered();

        // S'assurer que les providers par défaut existent
        $existingNames = array_map(fn ($p) => $p->getName(), $providers);
        $defaultProviders = ['gemini' => 'Google Vertex AI', 'ovh' => 'OVH AI Endpoints'];
        $changed = false;

        foreach ($defaultProviders as $name => $label) {
            if (!in_array($name, $existingNames, true)) {
                $provider = new SynapseProvider();
                $provider->setName($name)->setLabel($label)->setIsEnabled(false);
                $this->em->persist($provider);
                $providers[] = $provider;
                $changed = true;
            }
        }

        if ($changed) {
            $this->em->flush();
            return $this->redirectToRoute('synapse_admin_providers');
        }

        // Count presets per provider
        $allConfigs = $this->configRepo->findAll();
        $presetCountByProvider = [];
        foreach ($providers as $provider) {
            $presetCountByProvider[$provider->getId()] = 0;
        }
        foreach ($allConfigs as $config) {
            if ($config->getModel() && $config->getModel()->getProvider()) {
                $providerId = $config->getModel()->getProvider()->getId();
                if (isset($presetCountByProvider[$providerId])) {
                    $presetCountByProvider[$providerId]++;
                }
            }
        }

        return $this->render('@Synapse/admin/providers.html.twig', [
            'providers' => $providers,
            'preset_count_by_provider' => $presetCountByProvider,
        ]);
    }

    /**
     * Édition des credentials d'un provider
     */
    #[Route('/{id}/edit', name: 'synapse_admin_providers_edit', methods: ['GET', 'POST'])]
    public function edit(SynapseProvider $provider, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            $provider->setLabel($data['label'] ?? $provider->getLabel());
            $provider->setIsEnabled((bool) ($data['is_enabled'] ?? false));

            // Credentials selon le type de provider
            $credentials = $provider->getCredentials();

            match ($provider->getName()) {
                'gemini' => $credentials = [
                    'project_id'           => trim($data['project_id'] ?? ''),
                    'region'               => trim($data['region'] ?? 'europe-west1'),
                    'service_account_json' => trim($data['service_account_json'] ?? ''),
                ],
                'ovh' => $credentials = [
                    'api_key'  => trim($data['api_key'] ?? ''),
                    'endpoint' => trim($data['endpoint'] ?? 'https://oai.endpoints.kepler.ai.cloud.ovh.net/v1'),
                ],
                default => $credentials = json_decode($data['credentials_raw'] ?? '{}', true) ?? [],
            };

            $provider->setCredentials($credentials);

            $this->em->flush();

            $this->addFlash('success', 'Provider "' . $provider->getLabel() . '" mis à jour.');

            return $this->redirectToRoute('synapse_admin_providers');
        }

        return $this->render('@Synapse/admin/provider_edit.html.twig', [
            'provider' => $provider,
            'gemini_regions' => $this->getGeminiRegions(),
        ]);
    }

    /**
     * Test des credentials d'un provider
     */
    #[Route('/{id}/test', name: 'synapse_admin_providers_test', methods: ['POST'])]
    public function test(SynapseProvider $provider): JsonResponse
    {
        if (!$provider->isConfigured()) {
            return new JsonResponse(['success' => false, 'error' => 'Provider non configuré']);
        }

        try {
            match ($provider->getName()) {
                'gemini' => $this->testGemini($provider),
                'ovh' => $this->testOvh($provider),
                default => throw new \Exception('Provider type non supporté'),
            };

            return new JsonResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function testGemini(SynapseProvider $provider): void
    {
        $creds = $provider->getCredentials();
        $projectId = $creds['project_id'] ?? '';
        $region = $creds['region'] ?? 'europe-west1';

        if (empty($projectId)) {
            throw new \Exception('Project ID manquant');
        }

        // Simple test: vérifier que le service account JSON est valide
        $jsonStr = $creds['service_account_json'] ?? '';
        if (empty($jsonStr)) {
            throw new \Exception('Service Account JSON manquant');
        }

        $json = json_decode($jsonStr, true);
        if (!is_array($json) || empty($json['project_id'])) {
            throw new \Exception('Service Account JSON invalide');
        }

        // Vérifier que les IDs correspondent
        if ($json['project_id'] !== $projectId) {
            throw new \Exception('Project ID ne correspond pas au JSON');
        }
    }

    private function testOvh(SynapseProvider $provider): void
    {
        $creds = $provider->getCredentials();
        $apiKey = $creds['api_key'] ?? '';
        $endpoint = $creds['endpoint'] ?? 'https://oai.endpoints.kepler.ai.cloud.ovh.net/v1';

        if (empty($apiKey)) {
            throw new \Exception('API Key manquante');
        }

        // Test de connexion: faire un appel à la liste des modèles (gratuit)
        try {
            $response = $this->httpClient->request('GET', $endpoint . '/models', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Accept' => 'application/json',
                ],
                'timeout' => 10,
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new \Exception('Erreur HTTP ' . $response->getStatusCode() . ': ' . $response->getContent());
            }
        } catch (\Exception $e) {
            throw new \Exception('Impossible de se connecter à OVH: ' . $e->getMessage());
        }
    }

    private function getGeminiRegions(): array
    {
        return [
            'europe-west1'  => 'Europe West 1 (Belgique)',
            'europe-west4'  => 'Europe West 4 (Pays-Bas)',
            'us-central1'   => 'US Central 1 (Iowa)',
            'us-east1'      => 'US East 1 (Caroline du Sud)',
            'asia-east1'    => 'Asia East 1 (Taiwan)',
            'asia-northeast1' => 'Asia Northeast 1 (Tokyo)',
        ];
    }
}
