<?php

declare(strict_types=1);

/**
 * Étape 11 du jalon 3 — bench convergence calibrée.
 *
 * 1. Charge corpus.json (20 sources weecom + embeddings)
 * 2. Charge expected-convergences.json (annotations en aveugle)
 * 3. Pour chaque seuil cosine candidat (0.75 / 0.85 / 0.95) :
 *    - Calcule la similarité cosine de toutes les paires
 *    - Marque les paires ≥ seuil comme "détectées convergentes"
 *    - Compare aux annotations :
 *      - TP = paire annotée convergente ET détectée
 *      - FP = paire annotée non-convergente ET détectée
 *      - FN = paire annotée convergente ET NON détectée
 *      - TN = paire annotée non-convergente ET NON détectée
 *    - Calcule précision, rappel, F1
 * 4. Sort un rapport markdown comparatif
 *
 * Usage :
 *   php tools/brain-bench/bench-convergence.php \
 *       --corpus=packages/core/tests/Brain/Quality/Fixtures/convergence-v1/corpus.json \
 *       --expected=packages/core/tests/Brain/Quality/Fixtures/convergence-v1/expected-convergences.json \
 *       --thresholds=0.75,0.85,0.95 \
 *       --output=packages/core/tests/Brain/Quality/Fixtures/convergence-v1/bench-report.md
 */
$options = getopt('', ['corpus:', 'expected:', 'thresholds::', 'output::']);

foreach (['corpus', 'expected'] as $opt) {
    if (!isset($options[$opt])) {
        fwrite(STDERR, "Option --{$opt} requise.\n");
        exit(1);
    }
}

/** @var array<string, string> $options */
$corpusFile = $options['corpus'];
$expectedFile = $options['expected'];
$thresholdsRaw = $options['thresholds'] ?? '0.75,0.85,0.95';
$outputFile = $options['output'] ?? null;

$thresholds = array_map(static fn (string $s): float => (float) trim($s), explode(',', $thresholdsRaw));

$corpus = json_decode((string) file_get_contents($corpusFile), true, flags: JSON_THROW_ON_ERROR);
$expected = json_decode((string) file_get_contents($expectedFile), true, flags: JSON_THROW_ON_ERROR);

if (!is_array($corpus) || !isset($corpus['sources']) || !is_array($corpus['sources'])) {
    fwrite(STDERR, "Format corpus invalide.\n");
    exit(1);
}
if (!is_array($expected) || !isset($expected['expected_convergent'], $expected['expected_non_convergent'])) {
    fwrite(STDERR, "Format expected invalide.\n");
    exit(1);
}

/** @var list<array{id: string, text: string, embedding: list<float>}> $sources */
$sources = $corpus['sources'];

// Index par ID
$byId = [];
foreach ($sources as $s) {
    $byId[$s['id']] = $s;
}

// Construction des sets d'annotations (paires triées pour comparaison)
$expectedConvergent = [];
foreach ($expected['expected_convergent'] as $pair) {
    $expectedConvergent[normalizePairKey($pair['pair'])] = $pair['rationale'] ?? '';
}
$expectedNonConvergent = [];
foreach ($expected['expected_non_convergent'] as $pair) {
    $expectedNonConvergent[normalizePairKey($pair['pair'])] = $pair['rationale'] ?? '';
}

fwrite(STDERR, sprintf(
    "Corpus: %d sources, embedding dim=%d\n",
    count($sources),
    count($sources[0]['embedding'] ?? []),
));
fwrite(STDERR, sprintf(
    "Annotations: %d paires convergentes attendues, %d non-convergentes attendues\n",
    count($expectedConvergent),
    count($expectedNonConvergent),
));
fwrite(STDERR, 'Lancement du bench sur '.count($thresholds)." seuils...\n\n");

$report = "# Bench convergence v1 — résultats\n\n";
$report .= sprintf("Date : %s\n\n", (new DateTimeImmutable())->format('c'));
$report .= sprintf("Corpus : `%s` (%d sources)\n", basename($corpusFile), count($sources));
$report .= sprintf("Annotations : `%s` (%d paires convergent + %d non-convergent)\n\n", basename($expectedFile), count($expectedConvergent), count($expectedNonConvergent));

// Calcule toutes les similarités une seule fois
$similarities = [];
$n = count($sources);
for ($i = 0; $i < $n; ++$i) {
    for ($j = $i + 1; $j < $n; ++$j) {
        $key = normalizePairKey([$sources[$i]['id'], $sources[$j]['id']]);
        $similarities[$key] = cosineSimilarity($sources[$i]['embedding'], $sources[$j]['embedding']);
    }
}

// Stats globales sur les distributions de similarité
$convergentScores = [];
foreach ($expectedConvergent as $key => $_) {
    if (isset($similarities[$key])) {
        $convergentScores[] = $similarities[$key];
    }
}
$nonConvergentScores = [];
foreach ($expectedNonConvergent as $key => $_) {
    if (isset($similarities[$key])) {
        $nonConvergentScores[] = $similarities[$key];
    }
}

$report .= "## Distributions de similarité (sur paires annotées)\n\n";
$report .= sprintf(
    "- Paires convergentes attendues : min=%.3f, médiane=%.3f, max=%.3f\n",
    min($convergentScores) ?: 0,
    median($convergentScores),
    max($convergentScores) ?: 0,
);
$report .= sprintf(
    "- Paires non-convergentes attendues : min=%.3f, médiane=%.3f, max=%.3f\n\n",
    min($nonConvergentScores) ?: 0,
    median($nonConvergentScores),
    max($nonConvergentScores) ?: 0,
);

// Bench par seuil
$report .= "## Métriques par seuil\n\n";
$report .= "| Seuil | TP | FP | FN | TN | Précision | Rappel | F1 |\n";
$report .= "|-------|----|----|----|----|-----------|--------|----|\n";

$bestF1 = -1.0;
$bestThreshold = null;

foreach ($thresholds as $threshold) {
    $tp = 0;
    $fp = 0;
    $fn = 0;
    $tn = 0;

    foreach ($expectedConvergent as $key => $_) {
        $sim = $similarities[$key] ?? 0;
        if ($sim >= $threshold) {
            ++$tp;
        } else {
            ++$fn;
        }
    }
    foreach ($expectedNonConvergent as $key => $_) {
        $sim = $similarities[$key] ?? 0;
        if ($sim >= $threshold) {
            ++$fp;
        } else {
            ++$tn;
        }
    }

    $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
    $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;
    $f1 = ($precision + $recall) > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;

    $report .= sprintf(
        "| %.2f  | %d  | %d  | %d  | %d  | %.3f | %.3f | %.3f |\n",
        $threshold,
        $tp,
        $fp,
        $fn,
        $tn,
        $precision,
        $recall,
        $f1,
    );

    if ($f1 > $bestF1) {
        $bestF1 = $f1;
        $bestThreshold = $threshold;
    }
}

$report .= "\n";

// Détail des paires détectées vs annotations (pour le meilleur seuil)
if (null !== $bestThreshold) {
    $report .= sprintf("## Détail au seuil retenu (%.2f, F1=%.3f)\n\n", $bestThreshold, $bestF1);

    $report .= "### Faux positifs (annoté non-convergent, détecté)\n\n";
    $hasFp = false;
    foreach ($expectedNonConvergent as $key => $rationale) {
        $sim = $similarities[$key] ?? 0;
        if ($sim >= $bestThreshold) {
            $hasFp = true;
            $report .= sprintf("- %s (sim=%.3f) — annoté non-convergent : %s\n", $key, $sim, $rationale);
        }
    }
    if (!$hasFp) {
        $report .= "_(aucun)_\n";
    }

    $report .= "\n### Faux négatifs (annoté convergent, non détecté)\n\n";
    $hasFn = false;
    foreach ($expectedConvergent as $key => $rationale) {
        $sim = $similarities[$key] ?? 0;
        if ($sim < $bestThreshold) {
            $hasFn = true;
            $report .= sprintf("- %s (sim=%.3f) — annoté convergent : %s\n", $key, $sim, $rationale);
        }
    }
    if (!$hasFn) {
        $report .= "_(aucun)_\n";
    }
}

$report .= sprintf("\n## Verdict\n\n**Seuil cosine retenu : %.2f** (F1=%.3f)\n\n", $bestThreshold ?? 0.85, $bestF1);

echo $report;

if (null !== $outputFile) {
    file_put_contents($outputFile, $report);
    fwrite(STDERR, "\nRapport écrit dans {$outputFile}\n");
}

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @param list<string> $pair
 */
function normalizePairKey(array $pair): string
{
    $sorted = $pair;
    sort($sorted);

    return $sorted[0].'↔'.$sorted[1];
}

/**
 * @param list<float> $a
 * @param list<float> $b
 */
function cosineSimilarity(array $a, array $b): float
{
    if ([] === $a || [] === $b || count($a) !== count($b)) {
        return 0.0;
    }
    $dot = 0.0;
    $normA = 0.0;
    $normB = 0.0;
    foreach ($a as $i => $va) {
        $vb = $b[$i];
        $dot += $va * $vb;
        $normA += $va * $va;
        $normB += $vb * $vb;
    }
    if (0.0 === $normA || 0.0 === $normB) {
        return 0.0;
    }

    return $dot / (sqrt($normA) * sqrt($normB));
}

/**
 * @param list<float> $values
 */
function median(array $values): float
{
    if ([] === $values) {
        return 0.0;
    }
    sort($values);
    $n = count($values);
    if (0 === $n % 2) {
        return ($values[(int) ($n / 2) - 1] + $values[(int) ($n / 2)]) / 2;
    }

    return $values[(int) ($n / 2)];
}
