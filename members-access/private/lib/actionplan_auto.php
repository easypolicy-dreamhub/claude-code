<?php
declare(strict_types=1);

require_once __DIR__ . '/portal.php';

/**
 * Action Plan step 2: numbers counted by themselves from what the toolkit already records.
 *
 * ONLY NUMBERS leave the Client Portal: the portal's notes are encrypted and are never read here. We count rows by type and date
 * (the same rule as the portal itself: a leader sees counts, never names).
 *
 * Counted since the plan's start date, up to today.
 * Where a number cannot be found (the person has no portal, or the portal's tables look different from what we expect),
 * the value is null and the item simply stays a count the agent enters by hand. Nothing breaks.
 */
const AP_AUTO = [
    'prospects'    => ['label' => 'prospects on the Client Portal list', 'source' => 'Client Portal'],
    'open'         => ['label' => 'Client Portal notes of type Open (market surveys)', 'source' => 'Client Portal'],
    'present'      => ['label' => 'Client Portal notes of type Present (presentations)', 'source' => 'Client Portal'],
    'recruit'      => ['label' => 'Client Portal notes of type Recruit', 'source' => 'Client Portal'],
    'closes'       => ['label' => 'cases closed in the Client Portal', 'source' => 'Client Portal'],
    'career_added' => ['label' => 'Career Code candidates added', 'source' => 'Career Code'],
    'career_tests' => ['label' => 'Career Code tests completed', 'source' => 'Career Code'],
];

/** Counted once per request per plan (the same plan is read several times while one page is built). */
final class ApAutoCache
{
    /** @var array<string,array> */
    public static array $c = [];
}

function ap_auto_reset(): void
{
    ApAutoCache::$c = [];
}

/** The note types the portal stores, and what each counts as here (compared without case). */
const AP_NOTE_KINDS = [
    'open' => ['open', 'opening', 'survey', 'market survey'],
    'present' => ['present', 'presentation', 'presenting', 'presented'],
    'recruit' => ['recruit', 'recruiting', 'recruitment'],
];

function ap_auto_options(): array
{
    $out = [];
    foreach (AP_AUTO as $k => $a) {
        $out[] = ['key' => $k, 'label' => ucfirst($a['label']), 'source' => $a['source']];
    }
    return $out;
}

/** Real portal note types that are simply not used for the plan (so they are not "unrecognised"). */
const AP_NOTE_KINDS_OTHER = ['follow', 'follow-up', 'followup', 'follow up', 'servicing', 'service'];

function ap_note_group(string $kind): ?string
{
    $k = strtolower(trim($kind));
    foreach (AP_NOTE_KINDS as $group => $names) {
        if (in_array($k, $names, true)) {
            return $group;
        }
    }
    return null;
}

/** Where this member's portal data lives, or why it cannot be used. @return array{file:?string,why:string,enabled:bool} */
function ap_portal_file(string $email): array
{
    $email = strtolower(trim($email));
    if (!portal_available()) {
        return ['file' => null, 'why' => 'The Client Portal link is not installed on the server.', 'enabled' => false];
    }
    $base = dirname(portal_bridge_file());
    try {
        $owner = function_exists('e3pb_owner') ? e3pb_owner() : '';
        if ($owner !== '' && $owner === $email) {
            $dir = $base;
            $enabled = true;
        } else {
            $rec = e3pb_load()[$email] ?? null;
            if (!$rec || !empty($rec['removed']) || empty($rec['uid'])) {
                return ['file' => null, 'why' => 'This person has no Client Portal yet.', 'enabled' => false];
            }
            $enabled = !empty($rec['enabled']);
            if (!$enabled) {
                return ['file' => null, 'why' => 'The Client Portal is switched off for this person.', 'enabled' => false];
            }
            $dir = $base . '/agents/' . preg_replace('/[^a-f0-9]/', '', (string)$rec['uid']);
        }
    } catch (Throwable $e) {
        return ['file' => null, 'why' => 'Could not read the Client Portal list.', 'enabled' => false];
    }
    $file = $dir . '/clients.sqlite';
    if (!is_file($file)) {
        return ['file' => null, 'why' => 'The Client Portal has no data for this person yet.', 'enabled' => $enabled];
    }
    return ['file' => $file, 'why' => '', 'enabled' => $enabled];
}

/** @return string[] column names of a table in a sqlite file (empty when the table is missing) */
function ap_columns(PDO $db, string $table): array
{
    $cols = [];
    foreach ($db->query('PRAGMA table_info(' . preg_replace('/[^a-z_]/', '', $table) . ')')->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cols[] = (string)$c['name'];
    }
    return $cols;
}

/**
 * The closes table's layout has not been seen, so its date and ANP columns are found by name:
 * a date column named for the close first, then any other "date", then the day it was recorded as a last resort.
 * @return array{0:?string,1:?string}
 */
function ap_pick_close_columns(array $cols): array
{
    $pick = static function (array $patterns, array $exclude = []) use ($cols): ?string {
        foreach ($patterns as $p) {
            foreach ($cols as $c) {
                if (preg_match($p, $c) && !preg_match('/^id$/i', $c) && !array_filter($exclude, static fn (string $x): bool => (bool)preg_match($x, $c))) {
                    return $c;
                }
            }
        }
        return null;
    };
    $date = $pick(['/^(c|close|closed|sale|case)_?(date|on|day)$/i', '/^date$/i', '/date/i', '/^(closed|day)$/i'], ['/updated|modified|created/i']) ?? $pick(['/created/i']);
    $anp = $pick(['/anp/i', '/premium/i', '/amount/i']);
    return [$date, $anp];
}

/**
 * Counts from one portal database.
 * @return array{ok:bool,problems:string[],prospects:?int,open:?int,present:?int,recruit:?int,closes:?int,anp:?float,kinds:array<string,int>,unmatched:string[],closes_cols:array,sample:array}
 */
function ap_portal_counts(string $file, string $since, string $until): array
{
    $out = ['ok' => false, 'problems' => [], 'prospects' => null, 'open' => null, 'present' => null, 'recruit' => null, 'closes' => null, 'anp' => null,
        'kinds' => [], 'unmatched' => [], 'closes_cols' => [], 'sample' => []];
    try {
        $db = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec('PRAGMA query_only = 1');
    } catch (Throwable $e) {
        $out['problems'][] = 'Could not open the Client Portal data.';
        return $out;
    }
    try {
        $out['ok'] = true;
        // prospects: everyone on the list as a prospect right now
        $cc = ap_columns($db, 'clients');
        if (in_array('kind', $cc, true)) {
            $out['prospects'] = (int)$db->query("SELECT COUNT(*) FROM clients WHERE kind = 'prospect'")->fetchColumn();
        } else {
            $out['problems'][] = 'The clients table has no "kind" column, so prospects cannot be counted.';
        }
        // notes by type, within the dates (the appointment date, or the day the note was made)
        $nc = ap_columns($db, 'notes');
        if (in_array('kind', $nc, true)) {
            $when = in_array('appt_date', $nc, true) ? "COALESCE(NULLIF(appt_date, ''), substr(created_at, 1, 10))" : 'substr(created_at, 1, 10)';
            $st = $db->prepare("SELECT kind, COUNT(*) AS c FROM notes WHERE $when >= ? AND $when <= ? GROUP BY kind");
            $st->execute([$since, $until]);
            $sums = ['open' => 0, 'present' => 0, 'recruit' => 0];
            foreach ($st->fetchAll() as $r) {
                $k = (string)($r['kind'] ?? '');
                $out['kinds'][$k === '' ? '(no type)' : $k] = (int)$r['c'];
                $g = ap_note_group($k);
                if ($g === null) {
                    if ($k !== '') {
                        $out['unmatched'][] = $k;
                    }
                } else {
                    $sums[$g] += (int)$r['c'];
                }
            }
            $out['open'] = $sums['open'];
            $out['present'] = $sums['present'];
            $out['recruit'] = $sums['recruit'];
        } else {
            $out['problems'][] = 'The notes table has no "kind" column, so note types cannot be counted.';
        }
        // closed cases: find the date and ANP columns by name, because we have not seen this table's exact layout
        $cl = ap_columns($db, 'closes');
        $out['closes_cols'] = $cl;
        if (!$cl) {
            $out['problems'][] = 'There is no "closes" table, so closed cases cannot be counted.';
        } else {
            [$dateCol, $anpCol] = ap_pick_close_columns($cl);
            if ($dateCol === null) {
                $out['problems'][] = 'The closes table has no date column I can recognise (' . implode(', ', $cl) . '), so closed cases cannot be counted.';
            } else {
                $sample = $db->query('SELECT "' . $dateCol . '" AS d FROM closes ORDER BY 1 DESC LIMIT 1')->fetchColumn();
                $out['sample']['closes_date'] = $sample === false ? null : (string)$sample;
                if ($sample !== false && !preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$sample)) {
                    $out['problems'][] = 'The closes dates look like "' . $sample . '", not year-month-day, so closed cases cannot be counted safely.';
                } else {
                    $sel = 'COUNT(*) AS c' . ($anpCol !== null ? ', SUM(CAST("' . $anpCol . '" AS REAL)) AS a' : '');
                    $st = $db->prepare("SELECT $sel FROM closes WHERE substr(\"$dateCol\", 1, 10) >= ? AND substr(\"$dateCol\", 1, 10) <= ?");
                    $st->execute([$since, $until]);
                    $r = $st->fetch();
                    $out['closes'] = (int)$r['c'];
                    $out['anp'] = $anpCol !== null && $r['a'] !== null ? (float)$r['a'] : null;
                }
            }
        }
    } catch (Throwable $e) {
        $out['problems'][] = 'Reading the Client Portal data failed: ' . $e->getMessage();
        error_log('ap_portal_counts: ' . $e->getMessage());
    }
    return $out;
}

/** Career Code candidates of this member since the start date (numbers only). @return array{added:int,tests:int}|null */
function ap_career_counts(PDO $pdo, int $memberId, string $since): ?array
{
    try {
        $from = $since . ' 00:00:00';
        $a = $pdo->prepare('SELECT COUNT(*) FROM candidates WHERE (owner_member_id = ? OR referred_by_member_id = ?) AND started_at >= ?');
        $a->execute([$memberId, $memberId, $from]);
        $t = $pdo->prepare('SELECT COUNT(*) FROM candidates WHERE (owner_member_id = ? OR referred_by_member_id = ?) AND started_at >= ? AND completed_at IS NOT NULL');
        $t->execute([$memberId, $memberId, $from]);
        return ['added' => (int)$a->fetchColumn(), 'tests' => (int)$t->fetchColumn()];
    } catch (Throwable $e) {
        return null;   // the Career Code tables are not there: nothing to count
    }
}

/** The DISC style a person got when they took the Career Code test as a candidate (matched by email). */
function ap_disc_from_career_code(PDO $pdo, string $email, array $allowed): ?string
{
    try {
        $st = $pdo->prepare('SELECT r.style FROM disc_results r JOIN candidates c ON c.id = r.candidate_id WHERE LOWER(c.email) = ? ORDER BY r.id DESC LIMIT 1');
        $st->execute([strtolower(trim($email))]);
        $style = strtoupper(trim((string)$st->fetchColumn()));
        return $style !== '' && in_array($style, $allowed, true) ? $style : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Everything auto-counted for one plan.
 * @return array{values:array<string,?int>,extra:array<string,string>,portal:array{found:bool,enabled:bool,why:string},problems:string[]}
 */
function ap_auto_context(PDO $pdo, array $plan, string $today): array
{
    $key = (int)$plan['id'] . '|' . $plan['start_date'] . '|' . $today;
    if (isset(ApAutoCache::$c[$key])) {
        return ApAutoCache::$c[$key];
    }
    $values = array_fill_keys(array_keys(AP_AUTO), null);
    $extra = [];
    $problems = [];
    $member = $pdo->prepare('SELECT email FROM members WHERE id = ?');
    $member->execute([(int)$plan['member_id']]);
    $email = (string)$member->fetchColumn();
    $since = (string)$plan['start_date'];

    $pf = ap_portal_file($email);
    if ($pf['file'] !== null) {
        $c = ap_portal_counts($pf['file'], $since, $today);
        foreach (['prospects', 'open', 'present', 'recruit', 'closes'] as $k) {
            $values[$k] = $c[$k];
        }
        if ($c['anp'] !== null) {
            $extra['closes'] = 'RM ' . number_format($c['anp']) . ' ANP so far';
        }
        $problems = $c['problems'];
    }
    $cr = ap_career_counts($pdo, (int)$plan['member_id'], $since);
    if ($cr !== null) {
        $values['career_added'] = $cr['added'];
        $values['career_tests'] = $cr['tests'];
    }
    return ApAutoCache::$c[$key] = ['values' => $values, 'extra' => $extra, 'portal' => ['found' => $pf['file'] !== null, 'enabled' => $pf['enabled'], 'why' => $pf['why']], 'problems' => $problems];
}

/** For the super admin: what was found for one person, in plain terms, so a mismatch is easy to spot. */
function ap_auto_diagnose(PDO $pdo, array $plan, string $today): array
{
    $member = $pdo->prepare('SELECT email, name FROM members WHERE id = ?');
    $member->execute([(int)$plan['member_id']]);
    $m = $member->fetch() ?: ['email' => '', 'name' => ''];
    $pf = ap_portal_file((string)$m['email']);
    $out = ['member' => $m['name'], 'since' => $plan['start_date'], 'until' => $today, 'portal' => ['found' => $pf['file'] !== null, 'why' => $pf['why']], 'lines' => []];
    $out['tech'] = [];
    if ($pf['file'] !== null) {
        $c = ap_portal_counts($pf['file'], (string)$plan['start_date'], $today);
        $n = static fn (?int $v): string => $v === null ? 'not available' : (string)$v;
        $out['lines'][] = 'Prospects on the list now: ' . $n($c['prospects']);
        $out['lines'][] = 'Market surveys (notes of type Open): ' . $n($c['open']);
        $out['lines'][] = 'Presentations (notes of type Present): ' . $n($c['present']);
        $out['lines'][] = 'Recruit notes: ' . $n($c['recruit']);
        if ($c['closes'] === null) {
            $out['lines'][] = 'Closed cases: not available (see the note below)';
        } elseif ($c['closes'] === 0) {
            $out['lines'][] = 'Closed cases since the start date: 0 (none logged in the portal yet)';
        } else {
            $out['lines'][] = 'Closed cases since the start date: ' . $c['closes'] . ($c['anp'] !== null ? ' (RM ' . number_format($c['anp']) . ' ANP)' : '');
        }
        $other = array_values(array_filter($c['unmatched'], static fn (string $k): bool => in_array(strtolower(trim($k)), AP_NOTE_KINDS_OTHER, true)));
        $unknown = array_values(array_diff($c['unmatched'], $other));
        if ($unknown) {
            $out['lines'][] = 'Note types I do not recognise (not counted): ' . implode(', ', $unknown);
        }
        // for troubleshooting only (folded on the screen)
        $out['tech'][] = 'Notes since the start date by type: ' . ($c['kinds'] ? implode(', ', array_map(static fn ($k, $v) => "$k = $v", array_keys($c['kinds']), $c['kinds'])) : 'none');
        if ($other) {
            $out['tech'][] = 'Other note types (real types, not used for the plan): ' . implode(', ', $other);
        }
        $out['tech'][] = 'Closed-cases table columns: ' . ($c['closes_cols'] ? implode(', ', $c['closes_cols']) : 'table not found')
            . ($c['closes_cols'] ? ' · ' . (($c['sample']['closes_date'] ?? null) === null ? 'no cases are logged there yet' : 'latest date looks like ' . $c['sample']['closes_date']) : '');
        $out['problems'] = $c['problems'];
    } else {
        $out['problems'] = [];   // the reason is already in ['portal']['why']
    }
    $cr = ap_career_counts($pdo, (int)$plan['member_id'], (string)$plan['start_date']);
    $out['lines'][] = 'Career Code candidates added since the start date: ' . ($cr === null ? 'not available' : $cr['added'] . ' (' . $cr['tests'] . ' tests completed)');
    return $out;
}
