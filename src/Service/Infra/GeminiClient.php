<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Service\Infra;

use ArnaudMoncondhuy\SynapseBundle\Util\TextUtil;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Low-level client for the Google Gemini API.
 */
class GeminiClient
{
    private const API_URL_TEMPLATE = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiKey,
        private string $model = 'gemini-2.0-flash',
    ) {
    }

    /**
     * Generates content using Gemini.
     *
     * @param string $systemInstruction The system prompt.
     * @param array $contents The conversation history (messages).
     * @param array $tools The tool definitions for function calling.
     * @return array The response content from Gemini.
     */
    public function generateContent(
        string $systemInstruction,
        array $contents,
        array $tools = [],
    ): array {
        $payload = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemInstruction]
                ]
            ],
            'contents' => $contents,
        ];

        if (!empty($tools)) {
            // Auto-detect: If flat list of functions, wrap them
            $firstTool = reset($tools);
            $isFlatFunctionList = is_array($firstTool)
                && isset($firstTool['name'])
                && !isset($firstTool['function_declarations']);

            if ($isFlatFunctionList) {
                $payload['tools'] = [
                    ['function_declarations' => $tools]
                ];
            } else {
                $payload['tools'] = $tools;
            }
        }

        $url = sprintf(self::API_URL_TEMPLATE, $this->model);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'query' => ['key' => $this->apiKey],
                'json' => TextUtil::sanitizeArrayUtf8($payload),
            ]);

            $data = $response->toArray();

            return $data['candidates'][0]['content'] ?? [];
        } catch (\Throwable $e) {
            // SECURITY: Never expose the API key in error messages
            $message = $e->getMessage();
            if (str_contains($message, $this->apiKey)) {
                $message = str_replace($this->apiKey, '***API_KEY_HIDDEN***', $message);
            }

            // Try to extract detailed error from Google
            if ($e instanceof \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface) {
                try {
                    $errorBody = $e->getResponse()->getContent(false);
                    $message .= ' || Google Error: ' . $errorBody;
                } catch (\Throwable) {
                    // Ignore if can't read body
                }
            }

            throw new \RuntimeException('Gemini API Error: ' . $message, 0, $e);
        }
    }
}
