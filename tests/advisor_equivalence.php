<?php
declare(strict_types=1);

// Run: php tests/advisor_equivalence.php [count]   Compares the PHP advisor assessment with the original JavaScript.
require_once __DIR__ . '/../private/lib/advisor.php';
require_once __DIR__ . '/helpers.php';

$count = (int)($argv[1] ?? 3000);
$json = shell_exec('node ' . escapeshellarg(__DIR__ . '/tools/assess_js.js') . ' ' . escapeshellarg(__DIR__ . '/fixtures/career-code-original.html') . ' ' . $count);
$cases = json_decode((string)$json, true);
check("original JavaScript produced $count cases", is_array($cases) && count($cases) === $count);

$mismatch = 0; $first = null; $verdicts = [];
foreach ($cases as $i => $c) {
    $r = assess_candidate($c['scores'], $c['lang']);
    $e = $c['expected'];
    $got = [
        'v' => [$r['verdict']['key'], $r['verdict']['title'], $r['verdict']['text']],
        'why' => $r['why'], 'watch' => $r['watch'], 'qs' => $r['questions'],
        'pattern' => array_map(fn ($p) => ['warn' => $p['warn'], 'txt' => $p['text']], $r['pattern']),
    ];
    $want = ['v' => $e['v'], 'why' => $e['why'], 'watch' => $e['watch'], 'qs' => $e['qs'], 'pattern' => $e['pattern']];
    // checks: compare the pass/ok flags per criterion
    $gotChecks = array_map(fn ($rw) => [$rw['t'], $rw['status']], $r['rows']);
    $wantChecks = [];
    foreach ($e['checks'] as $o) {
        $m = fn ($ok) => $ok === null ? 'na' : ($ok ? 'met' : 'notMet');
        $wantChecks[] = [$o['t'], $m($o['totalOK'])];
        if ($o['t'] !== 'C') { $wantChecks[] = [$o['t'], $m($o['abcOK'])]; }
    }
    $verdicts[$r['verdict']['key']] = ($verdicts[$r['verdict']['key']] ?? 0) + 1;
    if ($got != $want || $gotChecks != $wantChecks) {
        $mismatch++;
        $first ??= ['case' => $i, 'lang' => $c['lang'], 'got' => $got, 'want' => $want, 'gotChecks' => $gotChecks, 'wantChecks' => $wantChecks];
    }
}
check("PHP matches the original on all $count cases (verdict, notes, questions, pattern, criteria)", $mismatch === 0);
if ($first) { echo "  first mismatch:\n" . json_encode($first, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"; }
ksort($verdicts);
check('the random cases exercise every verdict (' . json_encode($verdicts) . ')', count($verdicts) === 4);
finish();
