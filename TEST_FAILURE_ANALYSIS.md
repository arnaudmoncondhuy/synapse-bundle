# PHPUnit Test Failure Analysis - 36 Failures

## Summary
- **Total Errors**: 19
- **Total Failures**: 17
- **Tests Passing**: 225
- **Total Tests**: 261

---

## CATEGORY 1: TokenAccountingServiceTest (10 Errors)
**Root Cause**: Missing Doctrine dependency in test environment
**Error Type**: `Error: Class "Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository" not found`
**Location**: `/src/Storage/Repository/SynapseModelRepository.php:14`
**Affected Tests**:
1. testLogUsageWithInternalFormat (line 21)
2. testLogUsageWithVertexFormat (line 21)
3. testLogUsageCalculatesTotalTokens (line 21)
4. testLogUsageWithUserAndConversationId (line 21)
5. testLogUsageStoresCostInMetadata (line 21)
6. testLogFromGeminiResponse (line 21)
7. testLogUsageWithZeroTokens (line 21)
8. testLogUsageWithUnknownModel (line 21)
9. testLogUsageConvertsIntUserIdToString (line 21)
10. testLogUsagePreservesProvidedMetadata (line 21)

**Issue**: 
- SynapseModelRepository extends ServiceEntityRepository from Doctrine
- Tests don't have proper Doctrine mock/setup
- The class doesn't exist in the test environment

**Fix Strategy**:
- Need to mock ServiceEntityRepository or extend test setup with Doctrine fixtures
- Or create a test-specific base repository class without Doctrine dependency

---

## CATEGORY 2: Functional Tests (2 Errors)
**Root Cause**: Same as TokenAccountingServiceTest - missing service definition
**Error Type**: `InvalidArgumentException: Service id "...TokenUsageRepository" looks like a FQCN but no corresponding class exists`
**Affected Tests**:
1. `ChatApiControllerTest::testChatEndpointIsReachableAndReturnsNdjson`
2. `ChatApiControllerTest::testChatEndpointRejectsMissingApiKey`
**Location**: `/src/Storage/Repository/TokenUsageRepository.php` (referenced in services.yaml)

**Issue**: 
- Services.yaml defines repositories as FQCN service IDs
- Symfony Kernel compilation fails because these classes can't be found
- Affects entire functional test bootstrap

**Fix**: Same as TokenAccountingServiceTest

---

## CATEGORY 3: ChatServiceTest (4 Errors/Failures)

### Error 1: testAskAccumulatesChunksIntoAnswer
**Type**: TypeError
**Line**: 235
**Error Message**: `assertStringContainsString(): Argument #2 ($haystack) must be of type string, array given`
**Issue**: Test expects accumulated answer to be a string, but it's an array
**Likely Cause**: ChatService returns chunk data structure instead of plain text

### Error 2: testAskMaintainsOpenAiCanonicalFormat  
**Type**: TypeError
**Line**: 348
**Error Message**: `Argument #1 ($event) must be of type SynapsePrePromptEvent, SynapseChunkReceivedEvent given`
**Issue**: Event dispatcher receiving wrong event type
**Root Cause**: Event listener callback has incorrect type hint or event is dispatched incorrectly

### Failure 1: testAskDispatchesSynapsePrePromptEvent
**Type**: Mock expectation failure
**Line**: 148
**Error Message**: `dispatch(...SynapseChunkReceivedEvent...) was not expected to be called more than once`
**Issue**: Event being dispatched multiple times when test expects single dispatch
**Root Cause**: Event loop firing events multiple times in implementation

### Failure 2: testAskWithDebugModeEnabled
**Type**: Assertion failure
**Line**: 282
**Error Message**: `Failed asserting that an array has the key 'debug'`
**Issue**: Debug info not included in response when debug mode enabled
**Root Cause**: Debug logging not working or response structure changed

---

## CATEGORY 4: MessageFormatterTest (9 Failures)
**Root Cause**: Formatter expects OpenAI format (`content` key) but tests provide Gemini format (`parts` key)
**Error Type**: `Failed asserting that actual size 0 matches expected size [1-3]`
**Affected Tests**:
1. testEntitiesToApiFormatConvertsApiFormat (line 49) - expects 1, got 0
2. testEntitiesToApiFormatWithSerializedArray (line 73) - expects 1, got 0
3. testEntitiesToApiFormatDecryptsSerializedContent (line 108) - expects 1, got 0
4. testEntitiesToApiFormatDecryptsSerializedArray (line 141) - expects 1, got 0
5. testEntitiesToApiFormatSkipsDecryptionForPlaintext (line 172) - expects 1, got 0
6. testEntitiesToApiFormatWithoutEncryptionService (line 193) - expects 1, got 0
7. testEntitiesToApiFormatWithMultipleSerializedMessages (line 220) - expects 3, got 0
8. testEntitiesToApiFormatWithMultipleEncryptedMessages (line 290) - expects 2, got 0
9. testEntitiesToApiFormatWithCapitalizedRoles (line 311) - `null matches expected 'USER'`

### Root Cause Analysis
**File**: `/src/Core/Formatter/MessageFormatter.php`
**Method**: `entitiesToApiFormat()` (lines 33-68)

Current logic:
```php
if (isset($entity['role']) && isset($entity['content'])) {
    // Only processes OpenAI format with 'content' key
    $messages[] = $decrypted;
}
```

Test data format (Gemini):
```php
[
    'role' => 'USER',
    'parts' => [['text' => 'Hello']]  // <- Key is 'parts', not 'content'
]
```

**Fix**: Update formatter to handle both OpenAI (`content`) and Gemini (`parts`) formats

---

## CATEGORY 5: OvhAiClientTest (6 Errors/Failures)

### Error 1: testStreamGenerateContentAcceptsOpenAiFormat
**Type**: Uninitialized typed property
**Line**: 87 (in OvhAiClient)
**Error Message**: `Typed property ModelCapabilities::$systemPrompt must not be accessed before initialization`
**Issue**: Test doesn't initialize all required properties on ModelCapabilities mock

### Error 2: testOvhSupportsToolsIfCapabilityEnabled
**Type**: Uninitialized typed property
**Line**: 385 (in OvhAiClient) 
**Error Message**: `Typed property ModelCapabilities::$functionCalling must not be accessed before initialization`
**Issue**: Same as Error 1 - ModelCapabilities properties not initialized

### Error 3: testStreamGenerateContentWithCustomModel
**Type**: Uninitialized typed property
**Line**: 87
**Error Message**: `Typed property ModelCapabilities::$systemPrompt must not be accessed before initialization`
**Issue**: Same as Error 1

### Error 4: testOvhAcceptsOpenAiParameters
**Type**: TypeError
**Line**: 239
**Error Message**: `assertArrayHasKey(): Argument #2 ($array) must be of type ArrayAccess|array, null given`
**Issue**: Return value is null instead of array

### Failure 1: testOvhIsOpenAiPassthrough
**Type**: Assertion failure
**Line**: 112
**Error Message**: `Failed asserting that an array has the key 'raw_request_body'`
**Issue**: Response doesn't include 'raw_request_body' key

### Failure 2: testOvhSafetyDisabledByDefault
**Type**: Assertion failure
**Line**: 136
**Error Message**: `Failed asserting that null is false`
**Issue**: Safety setting is null instead of false

### Failure 3: testDefaultModelIsGptOss20b
**Type**: Assertion failure
**Line**: 192
**Error Message**: `Failed asserting that null is of type array`
**Issue**: Model info is null instead of array

**Root Cause**: OvhAiClient code accesses uninitialized ModelCapabilities properties or returns null values

---

## CATEGORY 6: GeminiClientTest (1 Error)

### Error: testGenerateContentHandlesHttpException
**Type**: Unexpected exception
**Line**: 284
**Error Message**: `RuntimeException: Gemini API Error: Network error`
**Issue**: Exception is being thrown when test expects it to be caught and handled
**Root Cause**: Mock exception not being properly caught in error handling

---

## CATEGORY 7: LlmClientRegistryTest (2 Failures)

### Failure 1: testExceptionMessageListsAvailableProviders
**Type**: Exception type mismatch
**Line**: Not specified (test setup)
**Error Message**: `Failed asserting that exception of type "RuntimeException" is thrown`
**Issue**: Expected exception not thrown or wrong exception type

### Failure 2: testRegistryStoresUniqueClientInstances
**Type**: Object reference assertion
**Line**: 239
**Error Message**: `Failed asserting that two variables reference the same object`
**Issue**: Registry not returning same instance for repeated calls

---

## FIX PRIORITY ORDER

### High Priority (Foundation Issues - 12 errors):
1. **TokenAccountingServiceTest (10) + Functional Tests (2)** - Doctrine setup
   - Affects test bootstrap and all inheritance chains

### Medium Priority (Core Logic - 9 failures):
2. **MessageFormatterTest (9)** - Format handling
   - Update `entitiesToApiFormat()` to handle Gemini `parts` format
   - Pattern: Check for both `content` and `parts` keys

3. **OvhAiClientTest (3 errors)** - ModelCapabilities initialization
   - Initialize all typed properties in test mocks
   - Pattern: Mock objects need complete property initialization

### Medium Priority (Business Logic - 8 failures/errors):
4. **ChatServiceTest (4)** - Event dispatching and debug mode
5. **OvhAiClientTest (3 failures)** - Response structure
6. **GeminiClientTest (1)** - Error handling

### Low Priority (Edge Cases - 2 failures):
7. **LlmClientRegistryTest (2)** - Registry behavior

---

## BULK FIX PATTERNS IDENTIFIED

### Pattern 1: Uninitialized Mock Properties
```php
// Current (fails):
$capabilities = $this->createMock(ModelCapabilities::class);

// Fixed:
$capabilities = $this->createMock(ModelCapabilities::class);
$capabilities->method('getSystemPrompt')->willReturn(true);
$capabilities->method('getFunctionCalling')->willReturn(true);
```

### Pattern 2: Message Format Detection
```php
// Current (only works for OpenAI):
if (isset($entity['role']) && isset($entity['content']))

// Fixed (handles both OpenAI and Gemini):
if (isset($entity['role']) && (isset($entity['content']) || isset($entity['parts'])))
```

### Pattern 3: Event Type Checking
```php
// Current (specific event type):
->expects($this->once())
->method('dispatch')
->with($this->isInstanceOf(SynapsePrePromptEvent::class))

// Fixed (allow all event types or mock correctly):
->expects($this->atLeastOnce())  // Or change to SynapseChunkReceivedEvent
```

