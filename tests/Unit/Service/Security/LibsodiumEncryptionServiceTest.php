<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Service\Security;

use ArnaudMoncondhuy\SynapseBundle\Service\Security\LibsodiumEncryptionService;
use PHPUnit\Framework\TestCase;

class LibsodiumEncryptionServiceTest extends TestCase
{
    private LibsodiumEncryptionService $service;
    private string $key;

    protected function setUp(): void
    {
        $this->key = sodium_crypto_secretbox_keygen();
        $this->service = new LibsodiumEncryptionService($this->key);
    }

    public function testEncryptAndDecrypt(): void
    {
        $plaintext = 'Hello, World!';

        $encrypted = $this->service->encrypt($plaintext);
        $decrypted = $this->service->decrypt($encrypted);

        $this->assertEquals($plaintext, $decrypted);
        $this->assertNotEquals($plaintext, $encrypted);
    }

    public function testEncryptedDataIsDifferentEachTime(): void
    {
        $plaintext = 'Same message';

        $encrypted1 = $this->service->encrypt($plaintext);
        $encrypted2 = $this->service->encrypt($plaintext);

        $this->assertNotEquals($encrypted1, $encrypted2);
        $this->assertEquals($plaintext, $this->service->decrypt($encrypted1));
        $this->assertEquals($plaintext, $this->service->decrypt($encrypted2));
    }

    public function testIsEncrypted(): void
    {
        $plaintext = 'Test message';
        $encrypted = $this->service->encrypt($plaintext);

        $this->assertTrue($this->service->isEncrypted($encrypted));
        $this->assertFalse($this->service->isEncrypted($plaintext));
        $this->assertFalse($this->service->isEncrypted(''));
        $this->assertFalse($this->service->isEncrypted('short'));
    }

    public function testEncryptEmptyStringThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->encrypt('');
    }

    public function testEncryptLongText(): void
    {
        $plaintext = str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 100);

        $encrypted = $this->service->encrypt($plaintext);
        $decrypted = $this->service->decrypt($encrypted);

        $this->assertEquals($plaintext, $decrypted);
    }

    public function testEncryptUnicodeText(): void
    {
        $plaintext = 'Héllo 世界 🌍 émojis';

        $encrypted = $this->service->encrypt($plaintext);
        $decrypted = $this->service->decrypt($encrypted);

        $this->assertEquals($plaintext, $decrypted);
    }

    public function testDecryptWithWrongKeyFails(): void
    {
        $plaintext = 'Secret message';
        $encrypted = $this->service->encrypt($plaintext);

        $wrongKeyService = new LibsodiumEncryptionService(sodium_crypto_secretbox_keygen());

        $this->expectException(\RuntimeException::class);

        $wrongKeyService->decrypt($encrypted);
    }

    public function testDecryptInvalidDataFails(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->decrypt('invalid_base64_data_that_is_not_encrypted');
    }

    public function testDecryptTamperedDataFails(): void
    {
        $plaintext = 'Original message';
        $encrypted = $this->service->encrypt($plaintext);

        $tampered = substr($encrypted, 0, -4) . 'XXXX';

        $this->expectException(\RuntimeException::class);

        $this->service->decrypt($tampered);
    }

    public function testDecryptEmptyStringThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->decrypt('');
    }
}
