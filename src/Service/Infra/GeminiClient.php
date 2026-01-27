<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Service\Infra;

use ArnaudMoncondhuy\SynapseBundle\Util\TextUtil;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client HTTP de bas niveau pour l'API Google Gemini.
 *
 * Cette classe gère :
 * - L'authentification par clé API (AI Studio) ou OAuth2 (Vertex AI).
 * - La communication HTTP (POST).
 * - La sérialisation des requêtes (Payload).
 * - La gestion sécurisée des erreurs (masquage de l'API Key dans les logs).
 *
 * Elle n'a PAS de logique métier "Synapse" (pas d'historique, pas de persona),
 * elle ne fait que passer les plats à Google.
 */
class GeminiClient
{
    private const AI_STUDIO_URL = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
    private const VERTEX_URL = 'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:generateContent';

    public function __construct(
        private HttpClientInterface $httpClient,
        private ?GoogleAuthService $googleAuthService,
        private string $model = 'gemini-2.5-flash-lite',
        private bool $vertexEnabled = false,
        private ?string $vertexProjectId = null,
        private string $vertexRegion = 'europe-west1',
        private bool $thinkingEnabled = true,
        private int $thinkingBudget = 1024,
    ) {
    }

    /**
     * Génère du contenu via l'API Gemini.
     *
     * @param string      $systemInstruction      instructions systèmes (System Prompt)
     * @param array       $contents               Historique de la conversation au format Gemini API.
     *                                            Chaque item doit être un tableau `['role' => 'user|model', 'parts' => [...]]`.
     * @param string      $apiKey                 clé API OBLIGATOIRE pour cette requête (AI Studio uniquement)
     * @param array       $tools                  Définitions des outils (Function Declarations).
     *                                            Optionnel, permet au modèle de demander l'exécution de fonctions.
     * @param string|null $model                  modèle spécifique pour cette requête (prioritaire sur la config)
     * @param array|null  $thinkingConfigOverride Configuration thinking personnalisée (override la config par défaut)
     *
     * @return array La réponse brute de l'API (le premier candidat).
     *               Généralement un tableau contenant ['parts' => ...].
     *
     * @throws \RuntimeException Si l'appel API échoue (timeout, quota, 500, etc.).
     */
    public function generateContent(
        string $systemInstruction,
        array $contents,
        string $apiKey,
        array $tools = [],
        ?string $model = null,
        ?array $thinkingConfigOverride = null,
    ): array {
        $effectiveModel = $model ?? $this->model;

        $payload = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemInstruction],
                ],
            ],
            'contents' => $contents,
        ];

        // Thinking Config
        $thinkingConfig = $thinkingConfigOverride ?? $this->buildThinkingConfig();
        if ($thinkingConfig) {
            $payload['generationConfig'] = [
                'thinkingConfig' => $thinkingConfig,
            ];
        }

        // Tools
        if (!empty($tools)) {
            $firstTool = reset($tools);
            $isFlatFunctionList = is_array($firstTool)
                && isset($firstTool['name'])
                && !isset($firstTool['function_declarations']);

            if ($isFlatFunctionList) {
                $payload['tools'] = [
                    ['function_declarations' => $tools],
                ];
            } else {
                $payload['tools'] = $tools;
            }
        }

        // Build URL and headers based on mode
        if ($this->vertexEnabled) {
            $url = $this->buildVertexUrl($effectiveModel);
            $headers = $this->buildVertexHeaders();
            $queryParams = [];
        } else {
            $url = sprintf(self::AI_STUDIO_URL, $effectiveModel);
            $headers = [];
            $queryParams = ['key' => $apiKey];
        }

        try {
            $options = [
                'json' => TextUtil::sanitizeArrayUtf8($payload),
                'headers' => $headers,
            ];

            if (!empty($queryParams)) {
                $options['query'] = $queryParams;
            }

            $response = $this->httpClient->request('POST', $url, $options);
            $data = $response->toArray();

            return $data['candidates'][0]['content'] ?? [];
        } catch (\Throwable $e) {
            $message = $e->getMessage();

            // Security: Hide sensitive data
            if (!$this->vertexEnabled && str_contains($message, $apiKey)) {
                $message = str_replace($apiKey, '***API_KEY_HIDDEN***', $message);
            }

            if ($e instanceof HttpExceptionInterface) {
                try {
                    $errorBody = $e->getResponse()->getContent(false);
                    $message .= ' || Google Error: ' . $errorBody;
                } catch (\Throwable) {
                }
            }

            throw new \RuntimeException('Gemini API Error: ' . $message, 0, $e);
        }
    }

    private function buildVertexUrl(string $model): string
    {
        if (!$this->vertexProjectId) {
            throw new \RuntimeException('Vertex AI requires a project_id');
        }

        return sprintf(
            self::VERTEX_URL,
            $this->vertexRegion,
            $this->vertexProjectId,
            $this->vertexRegion,
            $model
        );
    }

    private function buildVertexHeaders(): array
    {
        if (!$this->googleAuthService) {
            throw new \RuntimeException('GoogleAuthService is required for Vertex AI');
        }

        $accessToken = $this->googleAuthService->getAccessToken();

        return [
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Construit la configuration de thinking natif.
     *
     * @return array|null Configuration ou null si désactivé
     */
    private function buildThinkingConfig(): ?array
    {
        if (!$this->thinkingEnabled) {
            return null;
        }

        return [
            'thinkingBudget' => $this->thinkingBudget,
        ];
    }
}
