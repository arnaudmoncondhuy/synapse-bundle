<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\EventSubscriber;

use ArnaudMoncondhuy\SynapseBundle\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseBundle\Event\SynapsePrePromptEvent;
use ArnaudMoncondhuy\SynapseBundle\Service\PromptBuilder;
use ArnaudMoncondhuy\SynapseBundle\Util\TextUtil;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Builds the complete prompt (system instruction + history) for the LLM.
 *
 * Listens to SynapsePrePromptEvent and populates:
 * - System instruction (persona-based)
 * - Message history (from options or loaded via handler)
 * - Generation config (from active preset)
 */
class ContextBuilderSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private PromptBuilder $promptBuilder,
        private ConfigProviderInterface $configProvider,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SynapsePrePromptEvent::class => ['onPrePrompt', 100], // High priority
        ];
    }

    public function onPrePrompt(SynapsePrePromptEvent $event): void
    {
        $message = $event->getMessage();
        $options = $event->getOptions();

        // ── Build system instruction ──
        $personaKey = $options['persona'] ?? null;
        $systemInstruction = $this->promptBuilder->buildSystemInstruction($personaKey);

        // ── Load history ──
        $isStateless = $options['stateless'] ?? false;
        $rawHistory = [];
        $contents = [];

        if ($isStateless) {
            // Stateless mode: only current message
            if (!empty($message)) {
                $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];
            }
        } else {
            // Stateful mode: load history + add current message
            $rawHistory = $options['history'] ?? [];
            $contents = $this->sanitizeHistoryForNewTurn($rawHistory);
            if (!empty($message)) {
                $contents[] = ['role' => 'user', 'parts' => [['text' => TextUtil::sanitizeUtf8($message)]]];
            }
        }

        // ── Get config ──
        $config = $this->configProvider->getConfig();

        // Support preset override (for testing)
        if (isset($options['preset'])) {
            $config = $this->configProvider->getConfigForPreset($options['preset']);
        }

        // ── Build complete prompt ──
        $prompt = [
            'systemInstruction' => $systemInstruction,  // String, not wrapped
            'contents'          => $contents,
            'toolDefinitions'   => $options['tools'] ?? [],
        ];

        // Set on event
        $event->setPrompt($prompt);
        $event->setConfig($config);
    }

    /**
     * Sanitize history before sending to LLM.
     *
     * Removes empty messages and ensures UTF-8 validity.
     */
    private function sanitizeHistoryForNewTurn(array $history): array
    {
        $sanitized = [];

        foreach ($history as $message) {
            $role = $message['role'] ?? '';
            $parts = $message['parts'] ?? [];

            if (empty($parts)) {
                continue;
            }

            $cleanParts = [];
            foreach ($parts as $part) {
                if (isset($part['text'])) {
                    $part['text'] = TextUtil::sanitizeUtf8($part['text']);
                    $cleanParts[] = $part;
                } elseif (isset($part['functionCall']) || isset($part['functionResponse'])) {
                    $cleanParts[] = $part;
                }
            }

            if (!empty($cleanParts)) {
                $sanitized[] = [
                    'role'  => $role,
                    'parts' => $cleanParts,
                ];
            }
        }

        return $sanitized;
    }
}
