<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use ArnaudMoncondhuy\SynapseBundle\Service\PromptBuilder;
use ArnaudMoncondhuy\SynapseBundle\Service\Impl\DefaultContextProvider;

try {
    echo "Initializing services...\n";
    $provider = new DefaultContextProvider();
    $builder = new PromptBuilder($provider);

    echo "Building system instruction...\n";
    $result = $builder->buildSystemInstruction();

    echo "--- DEBUT PROMPT ---\n";
    echo $result . "\n";
    echo "--- FIN PROMPT ---\n";

    // Simple asserting
    $hasThinking = str_contains($result, '<thinking>');
    $hasSystem = str_contains($result, 'Tu es un assistant IA');
    $hasDate = str_contains($result, 'Date et heure actuelles');

    if ($hasThinking && $hasSystem && $hasDate) {
        echo "✅ TEST SUCCESS: Technical and System prompts are present.\n";
    } else {
        echo "❌ TEST FAILED: Missing components.\n";
        if (!$hasThinking)
            echo "- Missing Technical Prompt (<thinking>)\n";
        if (!$hasSystem)
            echo "- Missing System Prompt (Assistant role)\n";
        if (!$hasDate)
            echo "- Missing Date\n";
        exit(1);
    }

} catch (\Throwable $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
