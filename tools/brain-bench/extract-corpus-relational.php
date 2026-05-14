<?php

declare(strict_types=1);

/**
 * Extracteur relationnel pour le bench retrieval-v1.
 *
 * Contrairement à `extract-corpus.php` qui extrait un type unique (notes,
 * activities ou deals), ce script extrait **un graphe complet** pour une
 * liste de deals donnés :
 *
 *   deal → notes liées
 *        → activities liées
 *        → person (propriétaire du deal)
 *               → organization (employeur de la person)
 *
 * Sortie :
 *   - stdout : JSONL des MemorySource avec leurs liens cross-types dans `raw_payload`
 *   - --preview=<path>  : markdown lisible groupé par deal pour validation user
 *
 * Lecture seule absolue sur le dump Pipedrive.
 *
 * Usage :
 *   php tools/brain-bench/extract-corpus-relational.php \
 *       --path=/home/ubuntu/stacks/weecom/.tmp/pipedrive/data \
 *       --deal-ids=1157,1153,1147,1162,1156 \
 *       --preview=/tmp/bench-preview.md
 *
 * Cf. ADR-012 (skip Transduction au reinforce), plan jalon 4 étape 11.
 */
$options = getopt('', ['path:', 'deal-ids:', 'preview::']);

if (!isset($options['path']) || !isset($options['deal-ids'])) {
    fwrite(STDERR, "Usage: extract-corpus-relational.php --path=<dir> --deal-ids=1,2,3 [--preview=<file.md>]\n");
    exit(1);
}

/** @var string $path */
$path = $options['path'];
/** @var string $dealIdsStr */
$dealIdsStr = $options['deal-ids'];
$dealIds = array_map('intval', explode(',', $dealIdsStr));
/** @var string|false $previewPath */
$previewPath = $options['preview'] ?? false;

if (!is_dir($path)) {
    fwrite(STDERR, "Path introuvable: {$path}\n");
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────────
// Chargement des dumps
// ─────────────────────────────────────────────────────────────────────────────

/** @var list<array<string, mixed>> $deals */
$deals = jsonLoad($path.'/deals.json');
/** @var list<array<string, mixed>> $notes */
$notes = jsonLoad($path.'/notes.json');
/** @var list<array<string, mixed>> $activities */
$activities = jsonLoad($path.'/activities.json');
/** @var list<array<string, mixed>> $persons */
$persons = jsonLoad($path.'/persons.json');
/** @var list<array<string, mixed>> $organizations */
$organizations = jsonLoad($path.'/organizations.json');

// Index pour lookup rapide
$personById = indexBy($persons, 'id');
$orgById = indexBy($organizations, 'id');

// ─────────────────────────────────────────────────────────────────────────────
// Extraction : pour chaque deal sélectionné, collecter le sous-graphe
// ─────────────────────────────────────────────────────────────────────────────

$emitted = [];                  // external_id => source
$previewBlocks = [];            // markdown par deal

foreach ($dealIds as $dealId) {
    $deal = firstWhere($deals, 'id', $dealId);
    if (null === $deal) {
        fwrite(STDERR, "⚠️  Deal {$dealId} introuvable, skip\n");
        continue;
    }

    // 1. Person + Org liées au deal
    $personId = $deal['person_id']['value'] ?? null;
    $person = is_int($personId) ? ($personById[$personId] ?? null) : null;

    $orgId = $deal['org_id']['value'] ?? null;
    $org = is_int($orgId) ? ($orgById[$orgId] ?? null) : null;

    // 2. Notes + Activities du deal
    $dealNotes = array_filter($notes, fn ($n) => ($n['deal_id'] ?? null) === $dealId);
    $dealActivities = array_filter(
        $activities,
        fn ($a) => ($a['deal_id'] ?? null) === $dealId && !isRingoverNoise($a),
    );

    // ─── Adapter chaque entité en MemorySource ─────────────────────────────

    $dealExt = "pipedrive-deal-{$dealId}";
    $personExt = $person ? 'pipedrive-person-'.$person['id'] : null;
    $orgExt = $org ? 'pipedrive-organization-'.$org['id'] : null;

    // Deal source
    $dealSource = adaptDeal($deal, $personExt, $orgExt);
    $emitted[$dealExt] = $dealSource;

    // Person source (1× par person, déduplique entre deals)
    if (null !== $person && !isset($emitted[$personExt])) {
        $emitted[$personExt] = adaptPerson($person, $orgExt);
    }

    // Org source (1× par org, déduplique entre deals)
    if (null !== $org && !isset($emitted[$orgExt])) {
        $emitted[$orgExt] = adaptOrganization($org);
    }

    // Notes + Activities (uniques au deal)
    $noteExts = [];
    foreach ($dealNotes as $note) {
        $ext = 'pipedrive-note-'.$note['id'];
        $emitted[$ext] = adaptNote($note, $dealExt, $personExt, $orgExt);
        $noteExts[] = $ext;
    }

    $activityExts = [];
    foreach ($dealActivities as $activity) {
        $ext = 'pipedrive-activity-'.$activity['id'];
        $emitted[$ext] = adaptActivity($activity, $dealExt, $personExt, $orgExt);
        $activityExts[] = $ext;
    }

    // ─── Preview markdown ─────────────────────────────────────────────────
    if (false !== $previewPath) {
        $previewBlocks[] = buildPreviewBlock(
            $dealId,
            $deal,
            $person,
            $org,
            $dealNotes,
            $dealActivities,
            $dealExt,
            $personExt,
            $orgExt,
            $noteExts,
            $activityExts,
        );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Sortie JSONL sur stdout
// ─────────────────────────────────────────────────────────────────────────────

foreach ($emitted as $src) {
    fwrite(STDOUT, json_encode($src, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
}

fwrite(STDERR, "Émis : ".count($emitted)." sources cross-type pour ".count($dealIds)." deals\n");

// Stats détaillées
$stats = ['deal' => 0, 'note' => 0, 'activity' => 0, 'person' => 0, 'organization' => 0];
foreach ($emitted as $src) {
    $provider = $src['provider'] ?? '';
    foreach ($stats as $type => $_) {
        if (str_contains($provider, $type)) {
            ++$stats[$type];
            break;
        }
    }
}
foreach ($stats as $type => $count) {
    fwrite(STDERR, "  - {$type} : {$count}\n");
}

// ─────────────────────────────────────────────────────────────────────────────
// Preview markdown (optionnel)
// ─────────────────────────────────────────────────────────────────────────────

if (false !== $previewPath && is_string($previewPath)) {
    $header = "# Bench retrieval-v1 — preview corpus relationnel\n\n";
    $header .= "Généré le ".date('Y-m-d H:i:s')." par `extract-corpus-relational.php`.\n\n";
    $header .= "**Deal IDs ciblés** : ".implode(', ', $dealIds)."\n\n";
    $header .= "**Total** : ".count($emitted)." sources (".implode(', ', array_map(fn ($k, $v) => "{$v} {$k}", array_keys($stats), $stats)).").\n\n";
    $header .= "Pour validation par user : vérifier que chaque deal est représentatif et que la mémoire est fraîche.\n\n";
    $header .= "---\n\n";

    file_put_contents($previewPath, $header.implode("\n---\n\n", $previewBlocks));
    fwrite(STDERR, "Preview écrite dans : {$previewPath}\n");
}

exit(0);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @return list<array<string, mixed>>
 */
function jsonLoad(string $file): array
{
    if (!is_file($file)) {
        fwrite(STDERR, "Fichier introuvable : {$file}\n");
        exit(1);
    }
    $raw = file_get_contents($file);
    /** @var list<array<string, mixed>> $data */
    $data = json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);

    return $data;
}

/**
 * @param list<array<string, mixed>> $items
 *
 * @return array<int, array<string, mixed>>
 */
function indexBy(array $items, string $key): array
{
    $out = [];
    foreach ($items as $item) {
        if (isset($item[$key]) && is_int($item[$key])) {
            $out[$item[$key]] = $item;
        }
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $items
 *
 * @return array<string, mixed>|null
 */
function firstWhere(array $items, string $key, int|string $value): ?array
{
    foreach ($items as $item) {
        if (($item[$key] ?? null) === $value) {
            return $item;
        }
    }

    return null;
}

/**
 * Filtre les activities générées automatiquement par Ringover (logs téléphoniques)
 * qui polluent le corpus sémantique : "Appel manqué", "Appel sortant", "Appel entrant".
 *
 * Pour ce premier bench retrieval-v1 (option B confirmée par user 2026-05-14),
 * on les exclut pour calibrer le moteur sur du bruit minimal. À réintégrer
 * au jalon 8+ (decay + consolidation) pour stress-tester sur du dump réaliste.
 *
 * @param array<string, mixed> $activity
 */
function isRingoverNoise(array $activity): bool
{
    $subject = $activity['subject'] ?? '';
    if (!is_string($subject)) {
        return false;
    }

    return 1 === preg_match('/^Appel (manqu[ée]|sortant|entrant)/i', $subject);
}

function stripHtml(string $s): string
{
    $clean = html_entity_decode(strip_tags($s), \ENT_QUOTES | \ENT_HTML5);

    return trim((string) preg_replace('/\s+/', ' ', $clean));
}

// ─────────────────────────────────────────────────────────────────────────────
// Adaptateurs
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @param array<string, mixed> $deal
 *
 * @return array<string, mixed>
 */
function adaptDeal(array $deal, ?string $personExt, ?string $orgExt): array
{
    return [
        'provider' => 'webhook_pipedrive_deal',
        'external_id' => 'pipedrive-deal-'.$deal['id'],
        'raw_payload' => [
            'text' => sprintf(
                '%s — Statut: %s. Valeur: %s€.',
                $deal['title'] ?? '?',
                $deal['status'] ?? '?',
                $deal['value'] ?? 0,
            ),
            'title' => $deal['title'] ?? null,
            'status' => $deal['status'] ?? null,
            'value' => $deal['value'] ?? 0,
            'add_time' => $deal['add_time'] ?? null,
            'won_time' => $deal['won_time'] ?? null,
            'related' => array_filter([
                'person' => $personExt,
                'organization' => $orgExt,
            ]),
        ],
    ];
}

/**
 * @param array<string, mixed> $note
 *
 * @return array<string, mixed>
 */
function adaptNote(array $note, string $dealExt, ?string $personExt, ?string $orgExt): array
{
    return [
        'provider' => 'webhook_pipedrive_note',
        'external_id' => 'pipedrive-note-'.$note['id'],
        'raw_payload' => [
            'text' => stripHtml((string) ($note['content'] ?? '')),
            'timestamp' => $note['add_time'] ?? null,
            'related' => array_filter([
                'deal' => $dealExt,
                'person' => $personExt,
                'organization' => $orgExt,
            ]),
        ],
    ];
}

/**
 * @param array<string, mixed> $activity
 *
 * @return array<string, mixed>
 */
function adaptActivity(array $activity, string $dealExt, ?string $personExt, ?string $orgExt): array
{
    $subject = (string) ($activity['subject'] ?? '');
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
        'external_id' => 'pipedrive-activity-'.$activity['id'],
        'raw_payload' => [
            'text' => $text,
            'occurred_at' => $occurredAt,
            'activity_type' => $activity['type'] ?? null,
            'done' => (bool) ($activity['done'] ?? false),
            'related' => array_filter([
                'deal' => $dealExt,
                'person' => $personExt,
                'organization' => $orgExt,
            ]),
        ],
    ];
}

/**
 * @param array<string, mixed> $person
 *
 * @return array<string, mixed>
 */
function adaptPerson(array $person, ?string $orgExt): array
{
    $name = $person['name'] ?? '?';
    $emails = array_map(fn ($e) => $e['value'] ?? '', $person['email'] ?? []);
    $phones = array_map(fn ($p) => $p['value'] ?? '', $person['phone'] ?? []);

    $text = $name;
    $contacts = array_filter(array_merge($emails, $phones));
    if ([] !== $contacts) {
        $text .= ' — Contact: '.implode(', ', $contacts);
    }

    return [
        'provider' => 'webhook_pipedrive_person',
        'external_id' => 'pipedrive-person-'.$person['id'],
        'raw_payload' => [
            'text' => $text,
            'name' => $name,
            'emails' => $emails,
            'phones' => $phones,
            'related' => array_filter([
                'organization' => $orgExt,
            ]),
        ],
    ];
}

/**
 * @param array<string, mixed> $org
 *
 * @return array<string, mixed>
 */
function adaptOrganization(array $org): array
{
    return [
        'provider' => 'webhook_pipedrive_organization',
        'external_id' => 'pipedrive-organization-'.$org['id'],
        'raw_payload' => [
            'text' => sprintf('%s — Organisation.', $org['name'] ?? '?'),
            'name' => $org['name'] ?? null,
            'address' => $org['address'] ?? null,
        ],
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Preview markdown
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @param array<string, mixed>       $deal
 * @param array<string, mixed>|null  $person
 * @param array<string, mixed>|null  $org
 * @param array<int, array<string, mixed>> $dealNotes
 * @param array<int, array<string, mixed>> $dealActivities
 * @param list<string>               $noteExts
 * @param list<string>               $activityExts
 */
function buildPreviewBlock(
    int $dealId,
    array $deal,
    ?array $person,
    ?array $org,
    array $dealNotes,
    array $dealActivities,
    string $dealExt,
    ?string $personExt,
    ?string $orgExt,
    array $noteExts,
    array $activityExts,
): string {
    $out = "## Deal #{$dealId} — {$deal['title']}\n\n";
    $out .= "- **external_id** : `{$dealExt}`\n";
    $out .= "- **Date création** : ".($deal['add_time'] ?? '?')."\n";
    $out .= "- **Statut** : ".($deal['status'] ?? '?').($deal['won_time'] ? " (won {$deal['won_time']})" : '')."\n";
    $out .= "- **Valeur** : ".($deal['value'] ?? 0)."€\n";

    if (null !== $person) {
        $out .= "- **Client** : {$person['name']} (`{$personExt}`)\n";
    }
    if (null !== $org) {
        $out .= "- **Organisation** : {$org['name']} (`{$orgExt}`)\n";
    }

    if ([] !== $dealNotes) {
        $out .= "\n### Notes (".count($dealNotes).")\n\n";
        foreach (array_values($dealNotes) as $i => $note) {
            $ext = $noteExts[$i] ?? '?';
            $text = stripHtml((string) ($note['content'] ?? ''));
            if (mb_strlen($text) > 200) {
                $text = mb_substr($text, 0, 197).'…';
            }
            $out .= "- `{$ext}` : {$text}\n";
        }
    }

    if ([] !== $dealActivities) {
        $out .= "\n### Activities (".count($dealActivities).")\n\n";
        foreach (array_values($dealActivities) as $i => $activity) {
            $ext = $activityExts[$i] ?? '?';
            $subject = $activity['subject'] ?? '';
            $note = stripHtml((string) ($activity['note'] ?? ''));
            $done = ($activity['done'] ?? false) ? '✓' : '○';
            $date = $activity['due_date'] ?? '';
            $line = "- `{$ext}` {$done} [{$date}] **{$subject}**";
            if ('' !== $note) {
                if (mb_strlen($note) > 150) {
                    $note = mb_substr($note, 0, 147).'…';
                }
                $line .= " — {$note}";
            }
            $out .= "{$line}\n";
        }
    }

    return $out;
}
