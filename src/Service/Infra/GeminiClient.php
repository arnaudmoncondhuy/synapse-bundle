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
 * - L'authentification par clé API.
 * - La communication HTTP (POST).
 * - La sérialisation des requêtes (Payload).
 * - La gestion sécurisée des erreurs (masquage de l'API Key dans les logs).
 *
 * Elle n'a PAS de logique métier "Synapse" (pas d'historique, pas de persona),
 * elle ne fait que passer les plats à Google.
 */
class GeminiClient
{
    private const API_URL_TEMPLATE = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $model = 'gemini-2.5-flash-lite',
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
     * @param string      $apiKey                 clé API OBLIGATOIRE pour cette requête
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

        if (!empty($tools)) {
            // Auto-detect: If flat list of functions, wrap them
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

        $url = sprintf(self::API_URL_TEMPLATE, $effectiveModel);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'query' => ['key' => $apiKey],
                'json' => TextUtil::sanitizeArrayUtf8($payload),
            ]);

            $data = $response->toArray();

            return $data['candidates'][0]['content'] ?? [];
        } catch (\Throwable $e) {
            // SECURITY: Never expose the API key in error messages
            $message = $e->getMessage();
            if (str_contains($message, $apiKey)) {
                $message = str_replace($apiKey, '***API_KEY_HIDDEN***', $message);
            }

            // Try to extract detailed error from Google
            if ($e instanceof HttpExceptionInterface) {
                try {
                    $errorBody = $e->getResponse()->getContent(false);
                    $message .= ' || Google Error: '.$errorBody;
                } catch (\Throwable) {
                    // Ignore if can't read body
                }
            }

            throw new \RuntimeException('Gemini API Error: '.$message, 0, $e);
        }
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
