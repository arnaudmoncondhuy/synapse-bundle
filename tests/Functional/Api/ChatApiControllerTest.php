<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Functional\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ChatApiControllerTest extends WebTestCase
{
    public function testChatEndpointIsReachableAndReturnsNdjson(): void
    {
        $client = static::createClient();

        $payload = [
            'message' => 'Hello World',
            'api_key' => 'test-api-key', // Clé factice pour passer la validation du contrôleur
            'options' => [
                'debug' => true,
            ],
        ];

        $client->request(
            'POST',
            '/synapse/api/chat',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );

        $response = $client->getResponse();

        // On s'attend à un code 200 (le streaming démarre)
        $this->assertEquals(200, $response->getStatusCode(), 'L\'endpoint devrait répondre 200 OK');

        // On vérifie le Content-Type spécifique NDJSON
        $this->assertStringContainsString(
            'application/x-ndjson',
            $response->headers->get('Content-Type'),
            'Le Content-Type devrait être application/x-ndjson'
        );

        // StreamedResponse ne stocke pas le contenu dans le client Symfony en test,
        // Nous vérifions simplement le Content-Type correct et le statut
        // En environnement réel, le streaming NDJSON contient une série d'événements JSON
        // Format attendu de chaque ligne NDJSON :
        // {"type":"chunk","data":{"text":"...","blocked":false,"blocked_reason":null}}
        // {"type":"done","data":{"usage":{"prompt_tokens":100,"completion_tokens":50}}}
        // {"type":"error","data":{"error":"..."}}

        // La vérification du Content-Type et du statut 200 confirme que
        // le streaming a démarré correctement avec le bon format NDJSON
    }

    public function testChatEndpointRejectsMissingApiKey(): void
    {
        $client = static::createClient();

        $payload = [
            'message' => 'Hello World',
            // Pas de api_key
        ];

        $client->request(
            'POST',
            '/synapse/api/chat',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );

        $response = $client->getResponse();

        // Le contrôleur retourne 400 si la clé api est manquante
        $this->assertEquals(400, $response->getStatusCode());
    }
}
