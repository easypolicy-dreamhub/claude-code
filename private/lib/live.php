<?php
declare(strict_types=1);

require_once __DIR__ . '/candidates.php';

/**
 * Paper-test scores keyed in by an advisor: per type, the part A / B / C counts and the part D total.
 * Rules mirror the questionnaire: A and B have 11 items per type, C has 14, and part D is two sets of
 * ratings 1-7 used once each across the six types, so each type's D is 2-14 and the six D's add up to 42-54.
 * The D-sum check is a soft one (a paper test can legitimately be mis-added), so it needs confirm_d to pass.
 * @return array<string, array{a:int,b:int,c:int,d:int,abc:int,total:int}>
 */
function manual_scores(mixed $raw, bool $confirmD): array
{
    if (!is_array($raw)) {
        throw new HttpError(422, 'Enter the scores for every type.');
    }
    $limits = ['a' => [0, ITEM_COUNTS['A']], 'b' => [0, ITEM_COUNTS['B']], 'c' => [0, ITEM_COUNTS['C']], 'd' => [2, 14]];
    $scores = [];
    $dSum = 0;
    foreach (HOLLAND_TYPES as $t) {
        $row = $raw[$t] ?? null;
        if (!is_array($row)) {
            throw new HttpError(422, "Enter the scores for type $t.");
        }
        $s = [];
        foreach ($limits as $k => [$lo, $hi]) {
            $v = $row[$k] ?? null;
            if (is_string($v) && preg_match('/^\d{1,2}$/', trim($v)) === 1) {
                $v = (int)trim($v);
            }
            if (!is_int($v) || $v < $lo || $v > $hi) {
                throw new HttpError(422, "Type $t, part " . strtoupper($k) . ": enter a whole number from $lo to $hi.");
            }
            $s[$k] = $v;
        }
        $s['abc'] = $s['a'] + $s['b'] + $s['c'];
        $s['total'] = $s['abc'] + $s['d'];
        $dSum += $s['d'];
        $scores[$t] = $s;
    }
    if (($dSum < 42 || $dSum > 54) && !$confirmD) {
        throw new HttpError(422, "Part D should add up to between 42 and 54 across the six types (yours adds up to $dSum). Check the paper, or confirm to save as entered.",
            ['needs_confirm' => 'd_sum', 'd_sum' => $dSum]);
    }
    return $scores;
}

/** Live test step 1: the advisor has asked the person and ticks that consent was given. */
function live_start(PDO $pdo, array $config, array $me, array $in): array
{
    return start_candidate($pdo, $config, $in, null, ['member' => $me, 'client_id' => isset($in['client_id']) ? (string)$in['client_id'] : null]);
}

/**
 * Paper-test entry: creates the candidate (safe to retry via client_id) and saves the scores in one go.
 * @return array{candidate_id:int, code:string, scores:array, already:bool, cand:array}
 */
function live_manual(PDO $pdo, array $config, array $me, array $in): array
{
    $scores = manual_scores($in['scores'] ?? null, ($in['confirm_d'] ?? false) === true);
    $r = live_start($pdo, $config, $me, $in);
    $stmt = $pdo->prepare('SELECT * FROM candidates WHERE id = ?');
    $stmt->execute([$r['candidate_id']]);
    $cand = $stmt->fetch();
    if ($r['completed']) {
        $a = $pdo->prepare('SELECT scores_json, code FROM assessments WHERE candidate_id = ? ORDER BY id DESC LIMIT 1');
        $a->execute([$r['candidate_id']]);
        $row = $a->fetch();
        return ['candidate_id' => $r['candidate_id'], 'code' => $row['code'], 'scores' => json_decode($row['scores_json'], true), 'already' => true, 'cand' => $cand];
    }
    $code = holland_code($scores);
    store_assessment($pdo, $r['candidate_id'], 'manual', null, $scores, $code, (int)$me['id']);
    return ['candidate_id' => $r['candidate_id'], 'code' => $code, 'scores' => $scores, 'already' => false, 'cand' => $cand];
}
