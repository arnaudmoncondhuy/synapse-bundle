<?php

declare(strict_types=1);

/**
 * Service d'auth Google OAuth2 minimal pour les outils de bench Brain v3.
 *
 * Reprend la logique de `GoogleVertexAiAuthService` du bundle (sans dépendances
 * Symfony), à des fins de scripting standalone. Charge un service account JSON,
 * génère un JWT RS256, l'échange contre un access token.
 *
 * Cache le token en mémoire pour la durée du script.
 */
final class VertexAuth
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE = 'https://www.googleapis.com/auth/cloud-platform';

    private ?string $cachedToken = null;
    private ?int $tokenExpiry = null;

    /** @var array<string, mixed> */
    private array $credentials;

    public function __construct(string $serviceAccountJsonPath)
    {
        if (!is_file($serviceAccountJsonPath)) {
            throw new RuntimeException("Service account JSON introuvable : {$serviceAccountJsonPath}");
        }
        $raw = file_get_contents($serviceAccountJsonPath);
        if (false === $raw) {
            throw new RuntimeException("Lecture impossible : {$serviceAccountJsonPath}");
        }
        $creds = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($creds)) {
            throw new RuntimeException("JSON invalide : {$serviceAccountJsonPath}");
        }
        /* @var array<string, mixed> $creds */
        $this->credentials = $creds;
    }

    public function getAccessToken(): string
    {
        if (null !== $this->cachedToken && null !== $this->tokenExpiry && time() < ($this->tokenExpiry - 300)) {
            return $this->cachedToken;
        }

        $jwt = $this->createJwt();

        $ch = curl_init(self::TOKEN_URL);
        if (false === $ch) {
            throw new RuntimeException('curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]),
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($response) || 200 !== $code) {
            throw new RuntimeException("OAuth2 token endpoint failed (HTTP {$code}) : ".(is_string($response) ? $response : 'no body'));
        }

        $data = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || !isset($data['access_token'])) {
            throw new RuntimeException('Invalid token response');
        }

        $this->cachedToken = (string) $data['access_token'];
        $this->tokenExpiry = time() + (int) ($data['expires_in'] ?? 3600);

        return $this->cachedToken;
    }

    /**
     * @return array{project_id: string}
     */
    public function getProjectInfo(): array
    {
        return [
            'project_id' => (string) ($this->credentials['project_id'] ?? ''),
        ];
    }

    private function createJwt(): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $now = time();
        $payload = [
            'iss' => $this->credentials['client_email'] ?? '',
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $h64 = self::b64url((string) json_encode($header, JSON_THROW_ON_ERROR));
        $p64 = self::b64url((string) json_encode($payload, JSON_THROW_ON_ERROR));
        $signatureInput = $h64.'.'.$p64;

        $signature = '';
        $ok = openssl_sign(
            $signatureInput,
            $signature,
            (string) ($this->credentials['private_key'] ?? ''),
            OPENSSL_ALGO_SHA256,
        );
        if (!$ok) {
            throw new RuntimeException('JWT signature failed');
        }

        return $signatureInput.'.'.self::b64url($signature);
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
