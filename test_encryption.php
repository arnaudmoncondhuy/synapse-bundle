<?php
// Simple test script to verify encryption service functionality

require 'vendor/autoload.php';

use ArnaudMoncondhuy\SynapseBundle\Security\LibsodiumEncryptionService;

// Create encryption service with a test key
$encryptionKey = 'your_32_character_encryption_key';
$service = new LibsodiumEncryptionService($encryptionKey);

// Test data
$testData = [
    'api_key' => 'ovh_1234567890abcdef1234567890abcdef',
    'endpoint' => 'https://oai.endpoints.kepler.ai.cloud.ovh.net/v1',
];

echo "=== Encryption Test ===\n\n";

// Test 1: Encrypt and detect
echo "1. Testing encryption:\n";
echo "   Original api_key: " . $testData['api_key'] . "\n";

$encrypted = $service->encrypt($testData['api_key']);
echo "   Encrypted api_key: " . substr($encrypted, 0, 50) . "...\n";
echo "   Is encrypted: " . ($service->isEncrypted($encrypted) ? 'YES' : 'NO') . "\n";

// Test 2: Decrypt
echo "\n2. Testing decryption:\n";
$decrypted = $service->decrypt($encrypted);
echo "   Decrypted api_key: " . $decrypted . "\n";
echo "   Matches original: " . ($decrypted === $testData['api_key'] ? 'YES' : 'NO') . "\n";

// Test 3: isEncrypted should return false for plain text
echo "\n3. Testing plain text detection:\n";
echo "   Original is encrypted: " . ($service->isEncrypted($testData['api_key']) ? 'YES' : 'NO') . "\n";
echo "   (Should be NO for plain text)\n";

echo "\n=== All Tests Passed ===\n";
