<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired BEFORE sending the prompt to the LLM.
 *
 * Allows subscribers to:
 * - Modify the system prompt
 * - Inject context
 * - Add/remove messages from history
 * - Adjust generation config
 */
class SynapsePrePromptEvent extends Event
{
    private array $prompt;
    private array $config;

    public function __construct(
        private string $message,
        private array $options,
        array $prompt = [],
        array $config = [],
    ) {
        $this->prompt = $prompt;
        $this->config = $config;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getPrompt(): array
    {
        return $this->prompt;
    }

    public function setPrompt(array $prompt): self
    {
        $this->prompt = $prompt;
        return $this;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function setConfig(array $config): self
    {
        $this->config = $config;
        return $this;
    }
}
