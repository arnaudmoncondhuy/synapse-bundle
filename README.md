# SynapseBundle

A reusable Symfony bundle for AI chatbot integration with Google Gemini.

## Features

- 🤖 Google Gemini API integration (gemini-2.0-flash)
- 🔧 Function Calling / Tools support
- 📡 Streaming responses (NDJSON)
- 💾 Conversation history (Session-based, extensible)
- 🎨 Ready-to-use Twig component + Stimulus.js controller
- 🔌 Fully extensible via interfaces

## Requirements

- PHP 8.4+
- Symfony 7.0+

## Installation

```bash
composer require arnaudmoncondhuy/synapse-bundle
```

## Configuration

```yaml
# config/packages/synapse.yaml
synapse:
    gemini_api_key: '%env(GEMINI_API_KEY)%'
    model: 'gemini-2.0-flash'  # Optional, this is the default
```

## Usage

### Include the chat component in your Twig template:

```twig
{{ include('@Synapse/chat/component.html.twig') }}
```

### Create custom AI tools:

```php
use ArnaudMoncondhuy\SynapseBundle\Contract\AiToolInterface;

class DateTool implements AiToolInterface
{
    public function getName(): string { return 'get_current_date'; }
    public function getDescription(): string { return 'Returns the current date.'; }
    public function getInputSchema(): array { return ['type' => 'object', 'properties' => []]; }
    public function execute(array $parameters): string { return (new \DateTime())->format('Y-m-d H:i:s'); }
}
```

Tools are auto-discovered via Symfony's autoconfiguration.

## License

MIT
