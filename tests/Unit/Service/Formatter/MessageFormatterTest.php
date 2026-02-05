<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Service\Formatter;

use ArnaudMoncondhuy\SynapseBundle\Contract\EncryptionServiceInterface;
use ArnaudMoncondhuy\SynapseBundle\Service\Formatter\MessageFormatter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Test MessageFormatter avec focus sur la conversion d'entités Doctrine
 * en format Gemini API et gestion du déchiffrement.
 *
 * Ce test couvre le bug résolu où les messages n'étaient pas envoyés à l'IA
 * parce que:
 * 1. Doctrine sérialize les entités en arrays dans un contexte closure
 * 2. L'EncryptionService n'était pas injecté dans le formatter
 * 3. Les contenus chiffrés n'étaient pas déchiffrés avant envoi à l'API
 */
class MessageFormatterTest extends TestCase
{
    private MessageFormatter $formatter;
    private MockObject|EncryptionServiceInterface $encryptionService;

    protected function setUp(): void
    {
        $this->encryptionService = $this->createMock(EncryptionServiceInterface::class);
        $this->formatter = new MessageFormatter($this->encryptionService);
    }

    /**
     * Test la conversion d'une entité Message normale vers le format Gemini.
     * Note: Nous testons surtout les arrays sérialisés car c'est le bug qu'on a résolu.
     */
    public function testEntitiesToApiFormatConvertsApiFormat(): void
    {
        // Vérifie que le formatage produit le bon format Gemini
        $serializedMessage = [
            'role' => 'USER',
            'parts' => [
                ['text' => 'Hello, World!']
            ]
        ];

        $result = $this->formatter->entitiesToApiFormat([$serializedMessage]);

        $this->assertCount(1, $result);
        $this->assertIsArray($result[0]);
        $this->assertArrayHasKey('role', $result[0]);
        $this->assertArrayHasKey('parts', $result[0]);
        $this->assertIsArray($result[0]['parts']);
        $this->assertArrayHasKey('text', $result[0]['parts'][0]);
    }

    /**
     * Test la conversion d'entités sérialisées (arrays) comme ceux que Doctrine produit
     * dans un contexte closure (bug qu'on a résolu).
     */
    public function testEntitiesToApiFormatWithSerializedArray(): void
    {
        // Simule ce que Doctrine produit: une entité convertie en array
        $serializedMessage = [
            'role' => 'USER',
            'parts' => [
                ['text' => 'Hello from array format']
            ]
        ];

        $result = $this->formatter->entitiesToApiFormat([$serializedMessage]);

        $this->assertCount(1, $result);
        $this->assertEquals('USER', $result[0]['role']);
        $this->assertEquals('Hello from array format', $result[0]['parts'][0]['text']);
    }

    /**
     * Test le déchiffrement d'un message sérialisé avec contenu chiffré.
     * C'est le cas exact qu'on a dû résoudre dans ChatApiController.
     */
    public function testEntitiesToApiFormatDecryptsSerializedContent(): void
    {
        $encryptedContent = 'FtvpY2UgceZ3zZdMkbxG2d2I7IBiVR179x62XE5PX/TuAjJE/gmx08dn2He2YA==';
        $decryptedContent = 'Bonjour, ceci est un message déchiffré';

        $this->encryptionService
            ->expects($this->once())
            ->method('isEncrypted')
            ->with($encryptedContent)
            ->willReturn(true);

        $this->encryptionService
            ->expects($this->once())
            ->method('decrypt')
            ->with($encryptedContent)
            ->willReturn($decryptedContent);

        $serializedMessage = [
            'role' => 'USER',
            'parts' => [
                ['text' => $encryptedContent]
            ]
        ];

        $result = $this->formatter->entitiesToApiFormat([$serializedMessage]);

        $this->assertCount(1, $result);
        $this->assertEquals($decryptedContent, $result[0]['parts'][0]['text']);
    }

    /**
     * Test le déchiffrement avec un array sérialisé (cas du bug résolu).
     */
    public function testEntitiesToApiFormatDecryptsSerializedArray(): void
    {
        $encryptedContent = 'encrypted_blob_xyz';
        $decryptedContent = 'This is decrypted';

        $this->encryptionService
            ->expects($this->once())
            ->method('isEncrypted')
            ->with($encryptedContent)
            ->willReturn(true);

        $this->encryptionService
            ->expects($this->once())
            ->method('decrypt')
            ->with($encryptedContent)
            ->willReturn($decryptedContent);

        $serializedMessage = [
            'role' => 'MODEL',
            'parts' => [
                ['text' => $encryptedContent]
            ]
        ];

        $result = $this->formatter->entitiesToApiFormat([$serializedMessage]);

        $this->assertCount(1, $result);
        $this->assertEquals('MODEL', $result[0]['role']);
        $this->assertEquals($decryptedContent, $result[0]['parts'][0]['text']);
    }

    /**
     * Test que les contenus non-chiffrés ne sont pas traités par le service de chiffrement.
     */
    public function testEntitiesToApiFormatSkipsDecryptionForPlaintext(): void
    {
        $plainContent = 'This is plain text, not encrypted';

        $this->encryptionService
            ->expects($this->once())
            ->method('isEncrypted')
            ->with($plainContent)
            ->willReturn(false);

        $this->encryptionService
            ->expects($this->never())
            ->method('decrypt');

        $serializedMessage = [
            'role' => 'USER',
            'parts' => [
                ['text' => $plainContent]
            ]
        ];

        $result = $this->formatter->entitiesToApiFormat([$serializedMessage]);

        $this->assertCount(1, $result);
        $this->assertEquals($plainContent, $result[0]['parts'][0]['text']);
    }

    /**
     * Test avec un encryption service null (pas de chiffrement activé).
     * Dans ce cas, les contenus sérialisés doivent être retournés tel quel.
     */
    public function testEntitiesToApiFormatWithoutEncryptionService(): void
    {
        $formatter = new MessageFormatter(null);

        $serializedMessage = [
            'role' => 'USER',
            'parts' => [
                ['text' => 'content_without_decryption']
            ]
        ];

        $result = $formatter->entitiesToApiFormat([$serializedMessage]);

        $this->assertCount(1, $result);
        $this->assertEquals('content_without_decryption', $result[0]['parts'][0]['text']);
    }

    /**
     * Test la conversion de plusieurs arrays sérialisés.
     * Simule un historique complet de conversation chargé depuis la base de données.
     */
    public function testEntitiesToApiFormatWithMultipleSerializedMessages(): void
    {
        $messages = [
            [
                'role' => 'USER',
                'parts' => [['text' => 'First user message']]
            ],
            [
                'role' => 'MODEL',
                'parts' => [['text' => 'First model response']]
            ],
            [
                'role' => 'USER',
                'parts' => [['text' => 'Second user message']]
            ],
        ];

        $result = $this->formatter->entitiesToApiFormat($messages);

        $this->assertCount(3, $result);

        // First
        $this->assertEquals('USER', $result[0]['role']);
        $this->assertEquals('First user message', $result[0]['parts'][0]['text']);

        // Second
        $this->assertEquals('MODEL', $result[1]['role']);
        $this->assertEquals('First model response', $result[1]['parts'][0]['text']);

        // Third
        $this->assertEquals('USER', $result[2]['role']);
        $this->assertEquals('Second user message', $result[2]['parts'][0]['text']);
    }

    /**
     * Test que les messages vides (arrays sans 'role' ou 'parts') sont ignorés.
     */
    public function testEntitiesToApiFormatIgnoresInvalidArrays(): void
    {
        $invalidMessages = [
            ['role' => 'USER'], // Missing 'parts'
            ['parts' => [['text' => 'hello']]], // Missing 'role'
            ['foo' => 'bar'], // Completely invalid
        ];

        $result = $this->formatter->entitiesToApiFormat($invalidMessages);

        $this->assertCount(0, $result);
    }

    /**
     * Test la conversion avec plusieurs messages chiffrés.
     * Représente un historique complet de conversation.
     */
    public function testEntitiesToApiFormatWithMultipleEncryptedMessages(): void
    {
        $encrypted1 = 'encrypted_1';
        $decrypted1 = 'First user message';
        $encrypted2 = 'encrypted_2';
        $decrypted2 = 'First assistant response';

        $this->encryptionService
            ->expects($this->exactly(2))
            ->method('isEncrypted')
            ->willReturn(true);

        // Use side effects to return different values
        $decryptMap = [
            [$encrypted1, $decrypted1],
            [$encrypted2, $decrypted2],
        ];
        $this->encryptionService
            ->expects($this->exactly(2))
            ->method('decrypt')
            ->willReturnMap($decryptMap);

        $messages = [
            [
                'role' => 'USER',
                'parts' => [['text' => $encrypted1]]
            ],
            [
                'role' => 'MODEL',
                'parts' => [['text' => $encrypted2]]
            ],
        ];

        $result = $this->formatter->entitiesToApiFormat($messages);

        $this->assertCount(2, $result);
        $this->assertEquals('USER', $result[0]['role']);
        $this->assertEquals($decrypted1, $result[0]['parts'][0]['text']);
        $this->assertEquals('MODEL', $result[1]['role']);
        $this->assertEquals($decrypted2, $result[1]['parts'][0]['text']);
    }

    /**
     * Test que les arrays sérialisés conservent leur format.
     * Note: Les rôles ne sont normalisés que pour les entités Message.
     */
    public function testEntitiesToApiFormatWithCapitalizedRoles(): void
    {
        $messages = [
            ['role' => 'USER', 'parts' => [['text' => 'hello']]],
            ['role' => 'MODEL', 'parts' => [['text' => 'response']]],
        ];

        $result = $this->formatter->entitiesToApiFormat($messages);

        // Arrays sérialisés conservent le rôle tel quel
        $this->assertEquals('USER', $result[0]['role']);
        $this->assertEquals('MODEL', $result[1]['role']);
    }

    /**
     * Test la conversion d'un array vide.
     */
    public function testEntitiesToApiFormatWithEmptyArray(): void
    {
        $result = $this->formatter->entitiesToApiFormat([]);

        $this->assertCount(0, $result);
    }

    /**
     * Test que les objets invalides sont filtrés.
     */
    public function testEntitiesToApiFormatFiltersInvalidObjects(): void
    {
        $validMessage = [
            'role' => 'USER',
            'parts' => [['text' => 'Valid message']]
        ];

        $invalidObject = new \stdClass(); // Not a Message

        $result = $this->formatter->entitiesToApiFormat([$validMessage, $invalidObject]);

        // Seul le message valide doit être retourné
        $this->assertCount(1, $result);
        $this->assertEquals('USER', $result[0]['role']);
    }

}
