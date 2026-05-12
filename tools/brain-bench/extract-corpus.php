<?php

declare(strict_types=1);

/**
 * Adaptateur côté hôte : lit les exports JSON d'un dump tiers (Pipedrive)
 * et produit des MemorySource JSON normalisées sur stdout (JSON Lines).
 *
 * Vit dans `tools/brain-bench/` du bundle, mais c'est volontairement un
 * adaptateur côté hôte (charte §2.1). Le vocabulaire métier ("deal", "note",
 * "person") apparaît dans ce script uniquement ; il ne traverse PAS vers
 * src/Brain/.
 *
 * Lecture seule absolue sur le path source.
 *
 * Usage :
 *   php tools/brain-bench/extract-corpus.php \
 *       --path=/home/ubuntu/stacks/weecom/.tmp/pipedrive/data \
 *       --type=notes \
 *       --limit=10
 *
 * Sortie : 1 ligne par MemorySource au format JSON (JSON Lines), sur stdout.
 *
 * Cf. docs/brain/06-phases/jalon-2-ingestion-mono-aire.md étape 11
 */
$options = getopt('', ['path:', 'type:', 'limit::']);

if (!isset($options['path']) || !isset($options['type'])) {
    fwrite(STDERR, "Usage: extract-corpus.php --path=<dir> --type=<notes|activities|deals> [--limit=N]\n");
    exit(1);
}

/** @var string $path */
$path = $options['path'];
/** @var string $type */
$type = $options['type'];
$limit = isset($options['limit']) ? (int) $options['limit'] : 0;

if (!is_dir($path)) {
    fwrite(STDERR, "Path introuvable: {$path}\n");
    exit(1);
}

$filename = match ($type) {
    'notes' => 'notes.json',
    'activities' => 'activities.json',
    'deals' => 'deals.json',
    default => null,
};

if (null === $filename) {
    fwrite(STDERR, "Type inconnu '{$type}'. Valeurs : notes, activities, deals.\n");
    exit(1);
}

$file = rtrim($path, '/').'/'.$filename;
if (!is_file($file)) {
    fwrite(STDERR, "Fichier introuvable : {$file}\n");
    exit(1);
}

$raw = file_get_contents($file);
if (false === $raw) {
    fwrite(STDERR, "Lecture impossible : {$file}\n");
    exit(1);
}

/** @var list<array<string, mixed>> $items */
$items = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
if (!is_array($items)) {
    fwrite(STDERR, "Format JSON inattendu (attendu : tableau racine).\n");
    exit(1);
}

$count = 0;

foreach ($items as $item) {
    if (!is_array($item)) {
        continue;
    }
    if ($limit > 0 && $count >= $limit) {
        break;
    }

    $source = match ($type) {
        'notes' => adaptNote($item),
        'activities' => adaptActivity($item),
        'deals' => adaptDeal($item),
        default => null,
    };

    if (null === $source) {
        continue;
    }

    fwrite(STDOUT, json_encode($source, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    ++$count;
}

fwrite(STDERR, "Émis : {$count} sources de type '{$type}'.\n");
exit(0);

// ─────────────────────────────────────────────────────────────────────────────
// Adaptateurs métier → MemorySource agnostique
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @param array<string, mixed> $note
 *
 * @return array<string, mixed>|null
 */
function adaptNote(array $note): ?array
{
    $content = $note['content'] ?? null;
    if (!is_string($content) || '' === trim($content)) {
        return null;
    }

    $id = $note['id'] ?? null;
    $externalId = is_scalar($id) ? 'pipedrive-note-'.$id : null;

    return [
        'provider' => 'webhook_pipedrive_note',
        'external_id' => $externalId,
        'raw_payload' => [
            'text' => stripHtml($content),
            'timestamp' => $note['add_time'] ?? null,
            'updated' => $note['update_time'] ?? null,
            'pinned' => (bool) ($note['pinned_to_deal_flag'] ?? false),
        ],
    ];
}

/**
 * @param array<string, mixed> $activity
 *
 * @return array<string, mixed>|null
 */
function adaptActivity(array $activity): ?array
{
    $subject = $activity['subject'] ?? null;
    if (!is_string($subject) || '' === trim($subject)) {
        return null;
    }

    $id = $activity['id'] ?? null;
    $externalId = is_scalar($id) ? 'pipedrive-activity-'.$id : null;

    $note = $activity['note'] ?? null;
    $text = $subject;
    if (is_string($note) && '' !== trim($note)) {
        $text .= "\n\n".stripHtml($note);
    }

    $occurredAt = null;
    if (isset($activity['due_date']) && is_string($activity['due_date']) && '' !== $activity['due_date']) {
        $occurredAt = $activity['due_date'];
        if (isset($activity['due_time']) && is_string($activity['due_time']) && '' !== $activity['due_time']) {
            $occurredAt .= 'T'.$activity['due_time'].':00Z';
        }
    }

    return [
        'provider' => 'webhook_pipedrive_activity',
        'external_id' => $externalId,
        'raw_payload' => [
            'text' => $text,
            'occurred_at' => $occurredAt,
            'activity_type' => $activity['type'] ?? null,
            'done' => (bool) ($activity['done'] ?? false),
        ],
    ];
}

/**
 * @param array<string, mixed> $deal
 *
 * @return array<string, mixed>|null
 */
function adaptDeal(array $deal): ?array
{
    $title = $deal['title'] ?? null;
    if (!is_string($title) || '' === trim($title)) {
        return null;
    }

    $id = $deal['id'] ?? null;
    $externalId = is_scalar($id) ? 'pipedrive-deal-'.$id : null;

    return [
        'provider' => 'webhook_pipedrive_deal',
        'external_id' => $externalId,
        'raw_payload' => [
            'text' => $title,
            'status' => $deal['status'] ?? null,
            'created' => $deal['add_time'] ?? null,
            'updated' => $deal['update_time'] ?? null,
            'value' => $deal['value'] ?? null,
            'currency' => $deal['currency'] ?? null,
        ],
    ];
}

function stripHtml(string $html): string
{
    // Nettoyage agressif : tags + entités courantes + espaces redondants
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    return trim($text);
}
