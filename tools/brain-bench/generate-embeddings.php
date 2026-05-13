<?php

declare(strict_types=1);

/**
 * Étape 10 du jalon 3 — génération du corpus de fixtures avec embeddings réels.
 *
 * 1. Extrait N sources weecom (lecture seule) via tools/brain-bench/extract-corpus.php
 * 2. Pour chaque source, génère un embedding via Vertex AI (text-embedding-004)
 *    avec les credentials weecom (autorisation user 2026-05-13)
 * 3. Sauvegarde en JSON versionnable dans tests/Brain/Quality/Fixtures/convergence-v1/
 *
 * Usage :
 *   php tools/brain-bench/generate-embeddings.php \
 *       --corpus=/home/ubuntu/stacks/weecom/.tmp/pipedrive/data \
 *       --type=notes \
 *       --limit=20 \
 *       --output=packages/core/tests/Brain/Quality/Fixtures/convergence-v1/corpus.json \
 *       --service-account=/home/ubuntu/stacks/weecom/credentials/google-service-account.json \
 *       --project=bray-numerique \
 *       --location=europe-west1
 *
 * Modèle embedding par défaut : text-multilingual-embedding-002 (768 dim,
 * supporte le français correctement).
 */

require_once __DIR__ . '/vertex-auth.php';

$options = getopt('', [
    'corpus:',
    'type:',
    'limit::',
    'output:',
    'service-account:',
    'project:',
    'location::',
    'model::',
]);

$required = ['corpus', 'type', 'output', 'service-account', 'project'];
foreach ($required as $opt) {
    if (!isset($options[$opt])) {
        fwrite(STDERR, "Option --{$opt} requise.\n");
        fwrite(STDERR, "Usage: generate-embeddings.php --corpus=<path> --type=<notes|activities|deals> --output=<file.json> --service-account=<file> --project=<id> [--limit=N] [--location=europe-west1] [--model=text-multilingual-embedding-002]\n");
        exit(1);
    }
}

/** @var array<string, string> $options */
$corpus = $options['corpus'];
$type = $options['type'];
$limit = isset($options['limit']) ? (int) $options['limit'] : 20;
$outputFile = $options['output'];
$serviceAccount = $options['service-account'];
$projectId = $options['project'];
$location = $options['location'] ?? 'europe-west1';
$model = $options['model'] ?? 'text-multilingual-embedding-002';

fwrite(STDERR, "→ Étape 1/3 : extraction du corpus weecom ({$type}, limit={$limit})\n");

// Lance extract-corpus.php en sous-processus
$extractCmd = sprintf(
    'php %s --path=%s --type=%s --limit=%d',
    escapeshellarg(__DIR__ . '/extract-corpus.php'),
    escapeshellarg($corpus),
    escapeshellarg($type),
    $limit,
);

$extractedJsonl = shell_exec($extractCmd);
if (!is_string($extractedJsonl) || '' === trim($extractedJsonl)) {
    fwrite(STDERR, "Extraction du corpus a échoué ou retourné vide.\n");
    exit(1);
}

/** @var list<array{provider: string, external_id: ?string, raw_payload: array<string, mixed>}> $sources */
$sources = [];
foreach (explode("\n", trim($extractedJsonl)) as $line) {
    $line = trim($line);
    if ('' === $line) {
        continue;
    }
    $decoded = json_decode($line, true);
    if (!is_array($decoded)) {
        continue;
    }
    /** @var array{provider: string, external_id: ?string, raw_payload: array<string, mixed>} $decoded */
    $sources[] = $decoded;
}

$count = count($sources);
fwrite(STDERR, "  → {$count} sources extraites.\n");

if ([] === $sources) {
    fwrite(STDERR, "Pas de source à embedder.\n");
    exit(1);
}

fwrite(STDERR, "→ Étape 2/3 : génération des embeddings via Vertex AI ({$model} @ {$location})\n");

$auth = new VertexAuth($serviceAccount);
$token = $auth->getAccessToken();

$embeddingUrl = sprintf(
    'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:predict',
    $location,
    $projectId,
    $location,
    $model,
);

// Batching : Vertex accepte plusieurs instances par appel, on en regroupe 5
$batchSize = 5;
$fixtures = [];

for ($i = 0; $i < count($sources); $i += $batchSize) {
    $batch = array_slice($sources, $i, $batchSize);

    $instances = [];
    foreach ($batch as $src) {
        /** @var array<string, mixed> $payload */
        $payload = $src['raw_payload'];
        $text = (string) ($payload['text'] ?? '');
        $instances[] = ['content' => $text];
    }

    $body = json_encode(['instances' => $instances], JSON_THROW_ON_ERROR);

    $ch = curl_init($embeddingUrl);
    if (false === $ch) {
        throw new \RuntimeException('curl_init failed');
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (200 !== $code || !is_string($response)) {
        fwrite(STDERR, "Erreur Vertex AI (HTTP {$code}) sur le batch #" . (int) ($i / $batchSize + 1) . " : " . (string) $response . "\n");
        exit(1);
    }

    $data = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($data) || !isset($data['predictions']) || !is_array($data['predictions'])) {
        fwrite(STDERR, "Format réponse Vertex inattendu : " . substr((string) $response, 0, 200) . "\n");
        exit(1);
    }

    foreach ($data['predictions'] as $idx => $prediction) {
        if (!is_array($prediction)) {
            continue;
        }
        $embData = $prediction['embeddings'] ?? null;
        if (!is_array($embData) || !isset($embData['values']) || !is_array($embData['values'])) {
            continue;
        }

        $src = $batch[$idx];
        /** @var array<string, mixed> $payload */
        $payload = $src['raw_payload'];
        $fixtures[] = [
            'id' => sprintf('src-%03d', $i + (int) $idx + 1),
            'external_id' => $src['external_id'],
            'provider' => $src['provider'],
            'text' => (string) ($payload['text'] ?? ''),
            'embedding' => $embData['values'],
        ];
    }

    fwrite(STDERR, sprintf("  → batch %d/%d (%d sources cumulées)\n", (int) ($i / $batchSize + 1), (int) ceil(count($sources) / $batchSize), count($fixtures)));
}

fwrite(STDERR, "→ Étape 3/3 : écriture du fichier fixtures\n");

$outputDir = dirname($outputFile);
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0o755, true);
}

$payload = [
    'meta' => [
        'corpus_path' => $corpus,
        'type' => $type,
        'limit' => $limit,
        'embedding_model' => $model,
        'embedding_location' => $location,
        'embedding_project' => $projectId,
        'generated_at' => (new \DateTimeImmutable())->format('c'),
        'count' => count($fixtures),
    ],
    'sources' => $fixtures,
];

file_put_contents($outputFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

fwrite(STDERR, "  → écrit dans {$outputFile} (" . count($fixtures) . " sources avec embeddings)\n");
fwrite(STDERR, "✅ Done.\n");
