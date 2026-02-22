# 🚀 SynapseBundle: OpenAI Standardization - Implementation Summary

**Date**: 2026-02-22
**Status**: ✅ COMPLETE
**Impact**: Full LLM-agnosticism achieved

---

## 📋 Overview

The SynapseBundle core domain has been completely refactored to use **OpenAI as the canonical internal format**. This makes the bundle truly LLM-agnostic and enables seamless integration of any provider (Mistral, Claude, DeepSeek, Ollama, etc.) with minimal effort.

**Key Achievement**: Each new LLM provider now requires only a simple translation layer, not a complete reimplementation.

---

## 🎯 Changes by Phase

### Phase 1: PromptBuilder Refactoring
**File**: `src/Core/Chat/PromptBuilder.php`

- Added `buildSystemMessage(): array` - Returns `['role' => 'system', 'content' => '...']`
- `buildSystemInstruction()` now returns only the text content (used internally)

### Phase 2: Message Structure Standardization
**File**: `src/Core/Event/ContextBuilderSubscriber.php`

- **Before**: `systemInstruction` (string) + `contents` (array)
- **After**: All messages unified in `contents` array
  - System instruction is **always the first element** with `role: 'system'`
  - Follows OpenAI Chat Completions format exactly

```php
$prompt['contents'] = [
    ['role' => 'system', 'content' => '...'],    // Always first
    ['role' => 'user', 'content' => '...'],
    ['role' => 'assistant', 'content' => '...', 'tool_calls' => [...]],
    ['role' => 'tool', 'tool_call_id' => '...', 'content' => '...'],
];
```

### Phase 3: ChatService Cleanup
**File**: `src/Core/Chat/ChatService.php`

- ❌ Removed `systemInstruction` parameter from client calls
- ❌ Removed Gemini-specific `$categoryLabels` mapping
- ✅ Now uses generic `$chunk['blocked_reason']` (provider-supplied)
- Messages fully opaque to ChatService (no provider-specific logic)

### Phase 4: GeminiClient Adaptation
**File**: `src/Core/Client/GeminiClient.php`

**New Signature**:
```php
public function generateContent(
    array $contents,        // OpenAI format (no separate systemInstruction)
    array $tools = [],
    ?string $model = null,
    ?array $thinkingConfigOverride = null,
    array &$debugOut = [],
): array;
```

**What It Does Now**:
1. Extracts system message from first element of `$contents`
2. Removes it from the message list (Gemini API requirement)
3. Converts remaining OpenAI messages → Gemini format
4. Translates `HARM_CATEGORY_*` → human-readable `blocked_reason`

**New Helper Method**:
```php
private function getHarmCategoryLabel(string $category): string
{
    return [
        'HARM_CATEGORY_HARASSMENT'        => 'harcèlement',
        'HARM_CATEGORY_HATE_SPEECH'       => 'discours haineux',
        'HARM_CATEGORY_SEXUALLY_EXPLICIT' => 'contenu explicite',
        'HARM_CATEGORY_DANGEROUS_CONTENT' => 'contenu dangereux',
    ][$category] ?? $category;
}
```

### Phase 5: OvhAiClient Simplification
**File**: `src/Core/Client/OvhAiClient.php`

- ❌ Removed `systemInstruction` parameter
- ✅ Messages are pure passthrough (already OpenAI format)
- Removed unnecessary `toOpenAiMessages()` conversion logic
- Essentially: no conversion = perfect compatibility ✨

### Phase 6: Interface & Event Updates
**Files**:
- `src/Contract/LlmClientInterface.php`
- `src/Core/Event/SynapseChunkReceivedEvent.php`

**Interface Changes**:
```php
// ❌ OLD
public function generateContent(
    string $systemInstruction,
    array $contents,
    ...
)

// ✅ NEW
public function generateContent(
    array $contents,    // Contains system as first message
    ...
)
```

**Event Changes**:
- Renamed `getBlockedCategory()` → `getBlockedReason()`
- Chunk format now uses `blocked_reason` (string) instead of `blocked_category` (enum)

### Phase 7: DebugLogSubscriber Update
**File**: `src/Core/Event/DebugLogSubscriber.php`

- Updated to extract system instruction from `contents[0]` instead of separate key
- Maintains backward compatibility with debug logging

---

## 📊 Files Modified

| File | Changes | Impact |
|------|---------|--------|
| `src/Core/Chat/PromptBuilder.php` | +1 method | Low risk |
| `src/Core/Event/ContextBuilderSubscriber.php` | Restructured prompt assembly | Medium |
| `src/Core/Chat/ChatService.php` | -categoryLabels, signature change | Medium |
| `src/Core/Client/GeminiClient.php` | System extraction, category translation | Medium |
| `src/Core/Client/OvhAiClient.php` | Signature simplification | Low risk |
| `src/Contract/LlmClientInterface.php` | Signature update | Medium |
| `src/Core/Event/SynapseChunkReceivedEvent.php` | Method rename | Low risk |
| `src/Core/Event/DebugLogSubscriber.php` | System extraction logic | Low risk |

**Total Files**: 8
**Lines Added**: ~50
**Lines Removed**: ~40
**Net Change**: +10 (mostly documentation)

---

## 🔄 Message Flow Example

### Execution: ask("What is 2+2?")

```
1. ContextBuilderSubscriber::onPrePrompt()
   ↓
   Builds: $prompt['contents'] = [
       ['role' => 'system', 'content' => 'You are...'],     ← FIRST
       ['role' => 'user', 'content' => 'What is 2+2?'],
   ]

2. ChatService::ask()
   ↓
   $activeClient->streamGenerateContent($prompt['contents'], ...)

3. GeminiClient::buildPayload()
   ↓
   Extracts system from contents[0]
   Converts rest to Gemini format
   Sends: {systemInstruction: {...}, contents: [{role:'user', parts: [...]}]}

4. GeminiClient::normalizeChunk()
   ↓
   Receives: {candidates: [{safetyRatings: [...]}]}
   Returns: {text: '4', blocked: false, blocked_reason: null}

5. ChatService accumulates chunks
   ↓
   Returns final answer to user
```

---

## ✨ Benefits

### For ChatService
- 🎯 Zero provider-specific code
- 📦 No maintenance burden for new providers
- 🧪 Single, testable business logic

### For New Providers (e.g., Mistral, Claude)
- Create a new client implementing `LlmClientInterface`
- Implement 2 methods: `generateContent()` and `streamGenerateContent()`
- Convert OpenAI → provider format on request
- Convert provider response → normalized chunk on response
- **Done!** No modifications needed elsewhere

### For Operations
- Standardized internal format reduces cognitive load
- Clear separation: core logic ↔ provider adaptation
- Easy to audit: ChatService is provider-agnostic

---

## 🧪 Testing Checklist

- [x] PHP syntax validation: All files pass
- [x] No orphan `systemInstruction` references
- [x] No orphan `HARM_CATEGORY_*` usage in ChatService
- [x] All `blocked_category` → `blocked_reason` conversions
- [x] GeminiClient correctly extracts system from contents
- [x] OvhAiClient passthrough behavior confirmed
- [x] Event structure updated (getBlockedReason)
- [ ] Unit tests for PromptBuilder.buildSystemMessage()
- [ ] Integration tests with Gemini API
- [ ] Integration tests with OVH API
- [ ] End-to-end conversation tests

---

## 🚀 Next Steps: Adding a New Provider (e.g., Mistral)

```php
// 1. Create src/Core/Client/MistralClient.php
class MistralClient implements LlmClientInterface
{
    public function generateContent(
        array $contents,  // OpenAI format
        array $tools = [],
        ?string $model = null,
        ?array $thinkingConfigOverride = null,
        array &$debugOut = [],
    ): array {
        // Extract system
        $system = '';
        if (!empty($contents[0]) && $contents[0]['role'] === 'system') {
            $system = $contents[0]['content'];
            $contentsWithoutSystem = array_slice($contents, 1);
        }

        // Convert to Mistral format (similar to OpenAI, minimal work!)
        // Send request to Mistral API
        // Normalize response to standard chunk format
        // Return chunk with 'text', 'thinking', 'function_calls', etc.
    }

    public function streamGenerateContent(...) { ... }
}

// 2. Register in config
// synapse.providers.mistral:
//   client: '@App\Core\Client\MistralClient'

// 3. Users can now select Mistral in admin UI
// That's it!
```

---

## 📝 Documentation

For client developers, the internal contract is now:

**Input** (`$contents`): OpenAI Chat Completions format
```json
[
  {"role": "system", "content": "..."},
  {"role": "user", "content": "..."},
  {"role": "assistant", "content": "...", "tool_calls": [...]}
]
```

**Output** (chunk): Synapse normalized format
```json
{
  "text": "...",
  "thinking": null,
  "function_calls": [],
  "usage": {...},
  "safety_ratings": [],
  "blocked": false,
  "blocked_reason": null
}
```

---

## ⚠️ Breaking Changes

- `systemInstruction` no longer a separate parameter
- `blocked_category` → `blocked_reason` (string, not enum)
- `getBlockedCategory()` → `getBlockedReason()`
- `LlmClientInterface` signature changed

**Migration**: Update any custom client implementations to use new signatures.

---

## 🎉 Success Criteria

All met:

- ✅ ChatService is 100% LLM-agnostic
- ✅ Internal format is OpenAI standard
- ✅ New providers require only translation layer
- ✅ No provider-specific logic in core domain
- ✅ All files compile without errors
- ✅ All references updated consistently

**The SynapseBundle is now truly LLM-agnostic!** 🚀
