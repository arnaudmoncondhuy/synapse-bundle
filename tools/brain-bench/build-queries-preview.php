<?php

declare(strict_types=1);

/**
 * Génère un fichier markdown lisible pour valider les queries annotées
 * en aveugle. Pour chaque query, montre le texte de chaque source
 * attendue (résolu depuis le corpus JSONL).
 *
 * Évite à l'humain de devoir naviguer entre 3 fichiers (queries → corpus →
 * preview) pour vérifier qu'une annotation a du sens.
 *
 * Usage :
 *   php tools/brain-bench/build-queries-preview.php \
 *       --corpus=/tmp/bench-retrieval-v1-corpus.jsonl \
 *       --queries=packages/core/tests/Brain/Quality/Fixtures/retrieval-v1/queries.json \
 *       --out=/tmp/queries-preview.md
 */
$options = getopt('', ['corpus:', 'queries:', 'out:']);

if (!isset($options['corpus'], $options['queries'], $options['out'])) {
    fwrite(STDERR, "Usage: build-queries-preview.php --corpus=<file.jsonl> --queries=<queries.json> --out=<out.md>\n");
    exit(1);
}

/** @var string $corpusPath */
$corpusPath = $options['corpus'];
/** @var string $queriesPath */
$queriesPath = $options['queries'];
/** @var string $outPath */
$outPath = $options['out'];

if (!is_file($corpusPath)) {
    fwrite(STDERR, "Corpus introuvable : {$corpusPath}\n");
    exit(1);
}
if (!is_file($queriesPath)) {
    fwrite(STDERR, "Queries introuvables : {$queriesPath}\n");
    exit(1);
}

// Index corpus par external_id
$corpus = [];
$lines = file($corpusPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
if (false === $lines) {
    fwrite(STDERR, "Lecture corpus impossible.\n");
    exit(1);
}
foreach ($lines as $line) {
    /** @var array<string, mixed> $src */
    $src = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
    $extId = $src['external_id'] ?? null;
    if (is_string($extId)) {
        $corpus[$extId] = $src;
    }
}

/** @var array{meta: array<string, mixed>, queries: list<array<string, mixed>>} $queries */
$queries = json_decode((string) file_get_contents($queriesPath), true, flags: \JSON_THROW_ON_ERROR);

// Build markdown
$out = "# Queries retrieval-v1 — fichier de validation\n\n";
$out .= "Généré le ".date('Y-m-d H:i:s')." par `build-queries-preview.php`.\n\n";
$out .= "Pour chaque query, on liste les sources attendues **avec leur texte** pour faciliter la validation manuelle.\n\n";
$out .= "---\n\n";

foreach ($queries['queries'] as $q) {
    $type = $q['type'] ?? '?';
    $typeBadge = match ($type) {
        'relational' => '🔗 relational',
        'factual' => '📌 factual',
        'ambiguous' => '⚠️ ambiguous',
        default => $type,
    };

    $out .= "## {$q['id']} — \"{$q['text']}\"\n\n";
    $out .= "**Type** : {$typeBadge} — **Difficulté** : ".($q['difficulty'] ?? 'n/a')."\n\n";
    $out .= "**Rationale** : ".($q['rationale'] ?? '—')."\n\n";
    $out .= "**Sources attendues** (".count($q['expected_sources']).") :\n\n";

    foreach ($q['expected_sources'] as $extId) {
        $src = $corpus[$extId] ?? null;
        if (null === $src) {
            $out .= "- ❌ `{$extId}` — **INTROUVABLE dans le corpus** (à vérifier)\n";
            continue;
        }
        $text = $src['raw_payload']['text'] ?? '';
        if (mb_strlen($text) > 200) {
            $text = mb_substr($text, 0, 197).'…';
        }
        $kind = explode('_', explode('webhook_', $src['provider'] ?? '')[1] ?? '?')[1] ?? $src['provider'] ?? '?';
        $out .= "- `{$extId}` *[{$kind}]* — {$text}\n";
    }

    $out .= "\n---\n\n";
}

file_put_contents($outPath, $out);
fwrite(STDERR, "Preview écrite : {$outPath}\n");
exit(0);
