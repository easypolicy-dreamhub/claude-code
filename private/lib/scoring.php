<?php
declare(strict_types=1);

require_once __DIR__ . '/http.php';

const HOLLAND_TYPES = ['R', 'I', 'A', 'S', 'E', 'C'];
// Items per type in Parts A, B, C (must match public_html/career-code/assets/cc-data.js; a test checks it).
const ITEM_COUNTS = ['A' => 11, 'B' => 11, 'C' => 14];

/**
 * Turns raw answers into scores. The browser only ever sends WHAT WAS TICKED; totals are
 * always computed here, so nobody can submit a made-up result.
 *
 * $answers = [
 *   'A'|'B'|'C' => ['R' => [itemIndex,...], 'I' => [...], ...],
 *   'D'         => ['R' => [ratingSet1, ratingSet2], ...]   // 1-7, each set uses each number once
 * ]
 * @return array<string, array{a:int,b:int,c:int,d:int,abc:int,total:int}> keyed by type letter
 */
function score_test(mixed $answers): array
{
    if (!is_array($answers)) {
        throw new HttpError(422, 'Answers are missing.');
    }
    $scores = [];
    foreach (HOLLAND_TYPES as $t) {
        $scores[$t] = ['a' => 0, 'b' => 0, 'c' => 0, 'd' => 0, 'abc' => 0, 'total' => 0];
    }

    foreach (ITEM_COUNTS as $part => $max) {
        $byType = $answers[$part] ?? null;
        if (!is_array($byType)) {
            throw new HttpError(422, "Part $part is missing.");
        }
        foreach (HOLLAND_TYPES as $t) {
            $picked = $byType[$t] ?? [];
            if (!is_array($picked) || count($picked) > $max) {
                throw new HttpError(422, "Part $part has invalid answers.");
            }
            $seen = [];
            foreach ($picked as $i) {
                if (!is_int($i) || $i < 0 || $i >= $max || isset($seen[$i])) {
                    throw new HttpError(422, "Part $part has invalid answers.");
                }
                $seen[$i] = true;
            }
            $scores[$t][strtolower($part)] = count($seen);
        }
    }

    $d = $answers['D'] ?? null;
    if (!is_array($d)) {
        throw new HttpError(422, 'Part D is missing.');
    }
    $usedPerSet = [[], []];
    foreach (HOLLAND_TYPES as $t) {
        $pair = $d[$t] ?? null;
        if (!is_array($pair) || count($pair) !== 2 || !isset($pair[0], $pair[1])) {
            throw new HttpError(422, 'Part D must be rated for every item.');
        }
        foreach ([0, 1] as $k) {
            $v = $pair[$k];
            if (!is_int($v) || $v < 1 || $v > 7 || isset($usedPerSet[$k][$v])) {
                throw new HttpError(422, 'Part D: each number can be used only once within a set.');
            }
            $usedPerSet[$k][$v] = true;
        }
        $scores[$t]['d'] = $pair[0] + $pair[1];
    }

    foreach (HOLLAND_TYPES as $t) {
        $s = &$scores[$t];
        $s['abc'] = $s['a'] + $s['b'] + $s['c'];
        $s['total'] = $s['abc'] + $s['d'];
        unset($s);
    }
    return $scores;
}

/** Type letters, strongest first; ties keep R,I,A,S,E,C order (same as the on-screen result). @return string[] */
function rank_types(array $scores): array
{
    $types = HOLLAND_TYPES;
    usort($types, static fn (string $x, string $y): int =>
        $scores[$y]['total'] <=> $scores[$x]['total'] ?: array_search($x, HOLLAND_TYPES, true) <=> array_search($y, HOLLAND_TYPES, true));
    return $types;
}

function holland_code(array $scores): string
{
    return implode('', array_slice(rank_types($scores), 0, 3));
}
