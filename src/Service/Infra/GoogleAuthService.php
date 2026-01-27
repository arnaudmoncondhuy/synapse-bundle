<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Service\Infra;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service d'authentification OAuth2 pour Google Cloud / Vertex AI.
 *
 * Gère la génération et le refresh automatique des access tokens
 * à partir d'un fichier de credentials Service Account.
 */
class GoogleAuthService
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE = 'https://www.googleapis.com/auth/cloud-platform';

    private ?string $cachedToken = null;
    private ?int $tokenExpiry = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        private ?string $serviceAccountJsonPath,
    ) {
    }

    /**
     * Obtient un access token valide (avec refresh automatique).
     */
    public function getAccessToken(): string
    {
        // Check if token is still valid (with 5 min buffer)
        if ($this->cachedToken && $this->tokenExpiry && time() < ($this->tokenExpiry - 300)) {
            return $this->cachedToken;
        }

        return $this->refreshToken();
    }

    private function refreshToken(): string
    {
        if (!$this->serviceAccountJsonPath || !file_exists($this->serviceAccountJsonPath)) {
            throw new \RuntimeException('Service Account JSON file not found: ' . $this->serviceAccountJsonPath);
        }

        $credentials = json_decode(file_get_contents($this->serviceAccountJsonPath), true);

        if (!$credentials) {
            throw new \RuntimeException('Invalid Service Account JSON file');
        }

        // Create JWT assertion
        $jwt = $this->createJwtAssertion($credentials);

        // Exchange JWT for access token
        $response = $this->httpClient->request('POST', self::TOKEN_URL, [
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ],
        ]);

        $data = $response->toArray();

        $this->cachedToken = $data['access_token'];
        $this->tokenExpiry = time() + ($data['expires_in'] ?? 3600);

        return $this->cachedToken;
    }

    private function createJwtAssertion(array $credentials): string
    {
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];

        $now = time();
        $payload = [
            'iss' => $credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));

        $signatureInput = $headerEncoded . '.' . $payloadEncoded;

        openssl_sign(
            $signatureInput,
            $signature,
            $credentials['private_key'],
            OPENSSL_ALGO_SHA256
        );

        $signatureEncoded = $this->base64UrlEncode($signature);

        return $signatureInput . '.' . $signatureEncoded;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
