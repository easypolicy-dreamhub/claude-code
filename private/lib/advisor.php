<?php
declare(strict_types=1);

require_once __DIR__ . '/scoring.php';
require_once __DIR__ . '/emails.php';

/**
 * Advisor assessment ("is this person likely to suit an advisory career?"). Server side only: the
 * rules and wording never reach the public page, and the result is returned only to members who may
 * see the candidate. A line-for-line port of assess() in the original Career Code app; a test runs
 * that original JavaScript against this code on thousands of random score sets.
 */
const DEFAULT_ADVISOR_RULES = [
    'S' => ['total' => 25, 'abc' => 15],
    'E' => ['total' => 25, 'abc' => 15],
    'C' => ['total' => 25, 'abc' => null],
];

function advisor_text(string $lang, ?string $path = null): array
{
    static $cache = [];
    $path ??= __DIR__ . '/../data/advisor_text.json';
    if (!isset($cache[$path])) {
        $doc = json_decode((string)file_get_contents($path), true);
        if (!is_array($doc) || !isset($doc['en'], $doc['zh'])) {
            throw new RuntimeException('advisor_text.json is missing or invalid.');
        }
        $cache[$path] = $doc;
    }
    return $cache[$path][$lang === 'zh' ? 'zh' : 'en'];
}

/** Fills {0},{1},... in a template in ONE pass, so an argument containing "{1}" is never re-expanded. */
function tpl(string $t, mixed ...$args): string
{
    return preg_replace_callback('/\{(\d+)\}/', static fn (array $m): string => isset($args[(int)$m[1]]) ? (string)$args[(int)$m[1]] : $m[0], $t) ?? $t;
}

/**
 * @param array $scores per type letter: a,b,c,d,abc may be null (manual entry with totals only); total is required
 * @return array{verdict:array, rows:array, pattern:array, why:string[], watch:string[], questions:string[], labels:array}
 */
function assess_candidate(array $scores, string $lang = 'en', ?array $rules = null, ?array $info = null): array
{
    $A = advisor_text($lang);
    $I = ($info ?? holland_info())[$lang === 'zh' ? 'zh' : 'en'];
    $rules ??= DEFAULT_ADVISOR_RULES;
    $rank = rank_types($scores);
    $code = array_slice($rank, 0, 3);

    $chk = static function (string $t) use ($scores, $rules): array {
        $rule = $rules[$t];
        $o = ['t' => $t, 'total' => $scores[$t]['total'], 'abc' => $scores[$t]['abc'] ?? null, 'rule_total' => $rule['total'], 'rule_abc' => $rule['abc']];
        $o['totalOK'] = $scores[$t]['total'] >= $rule['total'];
        $o['abcOK'] = $rule['abc'] === null ? true : (($scores[$t]['abc'] ?? null) === null ? null : $scores[$t]['abc'] >= $rule['abc']);
        $o['pass'] = $o['totalOK'] && $o['abcOK'] !== false;
        return $o;
    };
    [$S, $E, $C] = [$chk('S'), $chk('E'), $chk('C')];

    if ($S['pass'] && $E['pass'] && $C['pass']) {
        $v = ['v-strong', ...$A['strong']];
    } elseif ($S['pass'] && $E['pass']) {
        $v = ['v-good', ...$A['good']];
    } elseif ($S['pass'] || $E['pass']) {
        $v = ['v-review', tpl($A['review'][0], $S['pass'] ? 'S' : 'E', $S['pass'] ? 'E' : 'S'), tpl($A['review'][1], $S['pass'] ? 'S' : 'E', $S['pass'] ? 'E' : 'S')];
    } else {
        $v = ['v-low', ...$A['low']];
    }

    $why = $watch = $qs = [];
    foreach ([$S, $E] as $o) {
        $t = $o['t'];
        $nm = $I[$t]['name'];
        $ab = $o['abc'] === null ? '' : tpl($A['ab'], $o['abc']);
        if ($o['pass'] && $o['abcOK'] === null) {
            $why[] = tpl($A['abcNA'], $nm, $o['total'], $rules[$t]['abc']);
        } elseif ($o['pass']) {
            $why[] = $t === 'S' ? tpl($A['sPass'], $o['total'], $ab) : tpl($A['ePass'], $o['total'], $ab);
        } elseif ($o['totalOK'] && $o['abcOK'] === false) {
            $why[] = tpl($A['abcLow'], $nm, $o['total'], $o['abc']);
            $watch[] = $t === 'S' ? $A['watchS'] : $A['watchE'];
        } else {
            $why[] = $t === 'S' ? tpl($A['sFail'], $o['total']) : tpl($A['eFail'], $o['total']);
        }
        if (!$o['pass'] || $o['abcOK'] === false) {
            array_push($qs, ...($t === 'E' ? $A['qE'] : $A['qS']));
        }
    }
    if ($C['pass']) {
        $why[] = tpl($A['cPass'], $C['total']);
    } else {
        $why[] = tpl($A['cFail'], $C['total']);
        $qs[] = $A['qC'];
    }

    if (in_array('I', $code, true)) { $why[] = $A['noteI']; }
    if (in_array('A', $code, true)) { $why[] = $A['noteA']; }
    if (in_array('R', $code, true)) { $why[] = $A['noteR']; }
    if ($code[0] === 'S' || $code[0] === 'E') { $why[] = tpl($A['lead'], $I[$code[0]]['name']); }
    $i1 = array_search($code[0], HOLLAND_TYPES, true);
    $i2 = array_search($code[1], HOLLAND_TYPES, true);
    $dist = min(abs($i1 - $i2), 6 - abs($i1 - $i2));
    if ($dist === 1) { $why[] = tpl($A['consistent'], $code[0], $code[1]); }
    if ($dist === 3) { $why[] = tpl($A['opposite'], $code[0], $code[1]); $qs[] = $A['qOpp']; }
    $spread = $scores[$rank[0]]['total'] - $scores[$rank[5]]['total'];
    if ($spread < 10) { $watch[] = tpl($A['flat'], $spread); }
    if ($scores[$rank[0]]['total'] >= 40 && $spread >= 25) { $why[] = $A['defined']; }
    $qs[] = $A['qFinal'];

    // Score pattern: 5+ per part, zeros, overall spread
    $pattern = [];
    $P = ['a', 'b', 'c'];
    $hasParts = static function (string $t) use ($scores, $P): bool {
        foreach ($P as $k) {
            if (($scores[$t][$k] ?? null) === null) { return false; }
        }
        return true;
    };
    foreach (['S', 'E', 'C'] as $t) {
        if (!$hasParts($t)) { continue; }
        $nm = $I[$t]['name'];
        $v3 = array_map(static fn (string $k): int => $scores[$t][$k], $P);
        $n5 = count(array_filter($v3, static fn (int $x): bool => $x >= 5));
        $zeros = [];
        foreach ($P as $k) { if ($scores[$t][$k] === 0) { $zeros[] = strtoupper($k); } }
        if ($zeros) {
            $pattern[] = ['warn' => true, 'text' => tpl($A['zero'], $nm, implode($A['and'], $zeros))];
        } elseif ($n5 === 3) {
            $pattern[] = ['warn' => false, 'text' => tpl($A['breadthAll'], $nm)];
        } elseif ($n5 <= 1) {
            $pattern[] = ['warn' => true, 'text' => tpl($A['narrow'], $nm, ...$v3)];
        } else {
            $pattern[] = ['warn' => false, 'text' => tpl($A['breadthSome'], $nm, $n5, ...$v3)];
        }
    }
    if (count(array_filter(HOLLAND_TYPES, $hasParts)) === 6) {
        $cells = [];
        foreach (HOLLAND_TYPES as $t) { foreach ($P as $k) { $cells[] = $scores[$t][$k]; } }
        $z = count(array_filter($cells, static fn (int $x): bool => $x === 0));
        $hi = count(array_filter($cells, static fn (int $x): bool => $x >= 5));
        if ($z >= 9) { $pattern[] = ['warn' => true, 'text' => tpl($A['manyZeros'], $z)]; }
        if ($hi >= 15) { $pattern[] = ['warn' => true, 'text' => tpl($A['highAll'], $hi)]; }
    }
    if (!array_filter(['S', 'E', 'C'], $hasParts)) { $pattern[] = ['warn' => false, 'text' => $A['noParts']]; }

    $mark = static fn (?bool $ok): string => $ok === null ? 'na' : ($ok ? 'met' : 'notMet');
    $rows = [];
    foreach ([$S, $E, $C] as $o) {
        $rows[] = ['t' => $o['t'], 'text' => tpl($A['rowTotal'], $o['total'], $o['rule_total']), 'status' => $mark($o['totalOK']), 'indent' => false];
        if ($o['rule_abc'] !== null) {
            $rows[] = ['t' => $o['t'], 'text' => tpl($A['rowAbc'], $o['abc'] ?? '–', $o['rule_abc']), 'status' => $mark($o['abcOK']), 'indent' => true];
        }
    }

    return [
        'verdict' => ['key' => $v[0], 'title' => $v[1], 'text' => $v[2]],
        'rows' => $rows, 'pattern' => $pattern, 'why' => $why, 'watch' => $watch,
        'questions' => array_values(array_unique($qs)),
        'labels' => [
            'title' => $A['title'], 'criteria' => $A['criteria'], 'why' => $A['why'], 'watch' => $A['watch'], 'questions' => $A['qs'],
            'pattern' => $A['pattern'], 'hex' => $A['hex'], 'disclaimer' => $A['disclaimer'],
            'met' => $A['met'], 'notMet' => $A['notMet'], 'notEntered' => $A['notEntered'],
        ],
    ];
}
