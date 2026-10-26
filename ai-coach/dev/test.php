<?php
// LOCAL TESTING ONLY. Run through run-tests.sh.
$base = getenv('APP_BASE');
$mockLog = getenv('MOCK_LOG');
$pass = 0; $fail = 0; $allBodies = '';

function req(string $user, string $method, string $action, string $query = '', $body = null, array $opt = []): array
{
    global $base, $allBodies;
    $ch = curl_init("$base/api.php?action=$action$query");
    $h = [];
    if ($user !== '') $h[] = "X-Dev-User: $user";
    if (!isset($opt['nocsrf'])) $h[] = 'X-Coach-Request: 1';
    if (isset($opt['origin'])) $h[] = 'Origin: ' . $opt['origin'];
    if (isset($opt['file'])) {
        $method = 'POST';
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['pdf' => new CURLFile($opt['file'], 'application/pdf', $opt['filename'])]);
    } elseif ($body !== null) {
        $h[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $allBodies .= $raw;
    return [$code, json_decode((string) $raw, true) ?? [], (string) $raw];
}
function ok(bool $cond, string $name, string $extra = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name $extra\n"; }
}
function mocklog(): array
{
    global $mockLog;
    return array_map(fn($l) => json_decode($l, true), file($mockLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
}
function pdf(string $path, string $extra = ''): void
{
    file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\n$extra\ntrailer<</Root 1 0 R>>\n%%EOF\n" . str_repeat(' ', 200));
}

$tmp = sys_get_temp_dir() . '/coach-test-' . getmypid();
mkdir($tmp);
pdf("$tmp/b-guide.pdf"); pdf("$tmp/a-products.pdf"); pdf("$tmp/c-sales.pdf"); pdf("$tmp/locked.pdf", '/Encrypt 9 0 R');
file_put_contents("$tmp/notes.txt", 'not a pdf');

echo "\n== Sign-in and admin gate (server-side) ==\n";
[$c] = req('', 'GET', 'me');                         ok($c === 401, 'signed-out visitor is refused on the API', "got $c");
[$c] = req('alan', 'GET', 'me', '', null);           ok($c === 200, 'agent can load the coach');
[$c, $d] = req('alan', 'GET', 'me');                 ok($d['is_admin'] === false && $d['ready'] === false, 'agent: not admin, coach not ready yet');
[$c, $d] = req('boss', 'GET', 'me');                 ok($d['is_admin'] === true, 'super admin sees admin flag');
$adminCalls = [
    ['GET', 'settings', '', null], ['GET', 'usage', '&range=7', null], ['GET', 'usage_member', '&m=' . str_repeat('a', 32), null],
    ['POST', 'save_instructions', '', ['text' => 'x']], ['POST', 'restore_instructions', '', ['version' => 1]], ['POST', 'delete_pdf', '', ['name' => 'a.pdf']],
];
foreach (['alan' => 'plain agent', 'teamadmin' => 'team admin (not super)'] as $u => $label) {
    $bad = [];
    foreach ($adminCalls as [$m, $a, $q, $b]) { [$c] = req($u, $m, $a, $q, $b); if ($c !== 403) $bad[] = "$a=$c"; }
    [$c] = req($u, 'POST', 'upload_pdf', '', null, ['file' => "$tmp/a-products.pdf", 'filename' => 'a-products.pdf']); if ($c !== 403) $bad[] = "upload=$c";
    ok(!$bad, "$label gets 403 on every admin endpoint when calling it directly", implode(',', $bad));
}
[$c] = req('boss', 'POST', 'save_instructions', '', ['text' => 'hi'], ['nocsrf' => 1]);                    ok($c === 403, 'POST without the request header is refused');
[$c] = req('boss', 'POST', 'save_instructions', '', ['text' => 'hi'], ['origin' => 'https://evil.example']); ok($c === 403, 'POST from another website is refused');
[$c] = req('boss', 'POST', 'save_instructions', '', ['text' => '   ']);                                      ok($c === 422, 'empty instructions are refused');

echo "\n== Coach not set up yet ==\n";
[$c, $d] = req('alan', 'POST', 'chat', '', ['message' => 'hello']); ok($c === 409, 'agent cannot chat before instructions exist', "got $c");

echo "\n== Instructions: keep the last 5 ==\n";
for ($i = 1; $i <= 7; $i++) req('boss', 'POST', 'save_instructions', '', ['text' => "Instruction text number $i"]);
[$c, $d] = req('boss', 'GET', 'settings');
$vs = array_column($d['versions'], 'version');
ok($vs === [7, 6, 5, 4, 3], 'after 7 saves only versions 7..3 remain', json_encode($vs));
ok($d['instructions']['text'] === 'Instruction text number 7', 'current instructions are the latest save');
req('boss', 'POST', 'save_instructions', '', ['text' => 'Instruction text number 7']);
[$c, $d] = req('boss', 'GET', 'settings'); ok(count($d['versions']) === 5 && $d['versions'][0]['version'] === 7, 'saving identical text does not create a version');
[$c, $d] = req('boss', 'POST', 'restore_instructions', '', ['version' => 5]);
[$c, $d] = req('boss', 'GET', 'settings');
ok($d['instructions']['text'] === 'Instruction text number 5' && $d['versions'][0]['version'] === 8 && count($d['versions']) === 5, 'restoring v5 makes a new v8 and still keeps only 5');
[$c] = req('boss', 'POST', 'restore_instructions', '', ['version' => 1]); ok($c === 404, 'restoring a version that was pruned is refused');
req('boss', 'POST', 'save_instructions', '', ['text' => 'You are the TEST COACH. Be brief.']);

echo "\n== Knowledge PDFs ==\n";
foreach (['b-guide.pdf', 'a-products.pdf', 'c-sales.pdf'] as $f) { [$c, $d] = req('boss', 'POST', 'upload_pdf', '', null, ['file' => "$tmp/$f", 'filename' => $f]); ok($d['ok'] === true && $d['status'] === 'added', "upload $f"); }
[$c, $d] = req('boss', 'POST', 'upload_pdf', '', null, ['file' => "$tmp/b-guide.pdf", 'filename' => 'b-guide.pdf']); ok($d['status'] === 'replaced', 'same name replaces');
[$c, $d] = req('boss', 'POST', 'upload_pdf', '', null, ['file' => "$tmp/b-guide.pdf", 'filename' => 'B-Guide.PDF']);
[$c, $d] = req('boss', 'GET', 'settings'); ok(count($d['knowledge']) === 3, 'still 3 files (no duplicates, even with different letter case)', json_encode(array_column($d['knowledge'], 'name')));
[$c, $d] = req('boss', 'POST', 'upload_pdf', '', null, ['file' => "$tmp/locked.pdf", 'filename' => 'locked.pdf']); ok($d['ok'] === false, 'password-protected PDF is refused');
[$c, $d] = req('boss', 'POST', 'upload_pdf', '', null, ['file' => "$tmp/notes.txt", 'filename' => 'notes.pdf']); ok($d['ok'] === false, 'a text file renamed .pdf is refused');
[$c, $d] = req('boss', 'POST', 'upload_pdf', '', null, ['file' => "$tmp/notes.txt", 'filename' => 'notes.txt']); ok($d['ok'] === false, 'non-PDF extension is refused');
[$c, $d] = req('boss', 'POST', 'upload_pdf', '', null, ['file' => "$tmp/a-products.pdf", 'filename' => '../../evil.pdf']);
[$c, $d] = req('boss', 'GET', 'settings'); ok(in_array('evil.pdf', array_column($d['knowledge'], 'name'), true), 'path in the file name is stripped (stays in the knowledge folder)');
[$c] = req('boss', 'POST', 'delete_pdf', '', ['name' => '../instructions/v000008.json']); ok($c === 404, 'delete cannot reach outside the knowledge folder');
[$c] = req('boss', 'POST', 'delete_pdf', '', ['name' => 'evil.pdf']); ok($c === 200, 'delete a PDF');
[$c, $d] = req('boss', 'GET', 'settings'); ok($d['status']['pdf_count'] === 3, 'status line counts 3 PDFs');

echo "\n== Chat: what is sent to Claude ==\n";
file_put_contents($mockLog, '');
[$c, $d] = req('alan', 'POST', 'chat', '', ['message' => 'hello coach']);
ok($c === 200 && str_starts_with($d['reply'] ?? '', 'COACH-REPLY'), 'agent gets a reply');
$r = mocklog()[0] ?? null; $b = $r['body'] ?? [];
ok(($r['headers']['x-api-key'] ?? '') === 'test-key-123', 'API key sent server-side');
ok(($r['headers']['anthropic-version'] ?? '') === '2026-10-21', 'anthropic-version header set');
ok(str_contains($r['headers']['anthropic-beta'] ?? '', 'server-side-fallback-2026-07-01') && ($b['fallbacks'] ?? '') === 'default', 'refusal fallback enabled');
ok(($b['model'] ?? '') === 'claude-opus-5-5' && ($b['output_config']['effort'] ?? '') === 'medium', 'model and effort');
ok(!isset($b['temperature']) && !isset($b['thinking']) && !isset($b['top_p']), 'no sampling or thinking params sent');
ok(str_contains(json_encode($b['system']), 'TEST COACH') && str_contains(json_encode($b['system']), 'Simplified Chinese'), 'system has leader instructions and the language rule');
$lead = $b['messages'][0]['content'] ?? [];
$docs = array_values(array_filter($lead, fn($x) => $x['type'] === 'document'));
ok(array_column($docs, 'title') === ['a-products.pdf', 'B-Guide.pdf', 'c-sales.pdf'], 'all PDFs sent as document blocks in a stable (sorted) order');
ok(($docs[2]['cache_control']['type'] ?? '') === 'ephemeral' && ($docs[2]['cache_control']['ttl'] ?? '') === '1h' && !isset($docs[0]['cache_control']) && !isset($docs[1]['cache_control']), 'cache breakpoint only on the last PDF');
ok(($docs[0]['source']['media_type'] ?? '') === 'application/pdf' && str_starts_with(base64_decode($docs[0]['source']['data']), '%PDF-'), 'PDF sent as valid base64');
ok(str_contains(end($lead)['text'], 'Agent: Alan') && ($b['messages'][1]['role'] ?? '') === 'assistant' && end($b['messages'])['content'] === 'hello coach', 'agent name block, then conversation, then the new message');

echo "\n== Memory is private per agent ==\n";
req('alan', 'POST', 'chat', '', ['message' => 'second question from alan']);
$logs = mocklog(); $second = end($logs)['body']['messages'];
ok(count($second) === 5 && $second[2]['content'] === 'hello coach', 'Alan\'s 2nd request carries his earlier exchange');
file_put_contents($mockLog, '');
req('brenda', 'POST', 'chat', '', ['message' => 'brenda here']);
$bj = json_encode(mocklog()[0]['body']['messages']);
ok(!str_contains($bj, 'hello coach') && !str_contains($bj, 'second question') && !str_contains($bj, 'Alan'), 'Brenda\'s request contains nothing from Alan');
[$c, $d] = req('alan', 'GET', 'history'); $t = json_encode($d); ok(str_contains($t, 'hello coach') && !str_contains($t, 'brenda here'), 'Alan\'s history shows only Alan');
[$c, $d] = req('brenda', 'GET', 'history'); $t = json_encode($d); ok(str_contains($t, 'brenda here') && !str_contains($t, 'hello coach'), 'Brenda\'s history shows only Brenda');

echo "\n== Handoff, language, errors ==\n";
[$c, $d] = req('alan', 'POST', 'chat', '', ['message' => 'UNKNOWN topic please']);
ok($d['handoff'] === true && !str_contains($d['reply'], '[[HANDOFF]]'), 'handoff flagged and the marker is removed from the reply');
[$c, $d] = req('alan', 'POST', 'chat', '', ['message' => '你好，请帮我']); ok(str_contains($d['reply'] ?? '', '你好'), 'Chinese passes through intact');
[$c, $d] = req('alan', 'POST', 'chat', '', ['message' => 'FAIL529 test']);
ok($c === 502 && !isset($d['detail']) && !str_contains(json_encode($d), 'secret detail'), 'API failure: agent gets a friendly error without internals');
[$c, $d] = req('boss', 'POST', 'chat', '', ['message' => 'FAIL529 test']);
ok($c === 502 && str_contains($d['detail'] ?? '', 'secret detail'), 'API failure: super admin gets the technical detail');
[$c, $d] = req('alan', 'GET', 'history'); ok(!str_contains(json_encode($d), 'FAIL529'), 'a failed question is not stored in the chat');
[$c] = req('alan', 'POST', 'chat', '', ['message' => str_repeat('x', 4001)]); ok($c === 422, 'over-long message refused');

echo "\n== Rolling memory (coaching notes) ==\n";
for ($i = 1; $i <= 7; $i++) req('brenda', 'POST', 'chat', '', ['message' => "brenda msg $i"]);
sleep(1);
file_put_contents($mockLog, '');
req('brenda', 'POST', 'chat', '', ['message' => 'brenda after notes']);
$bb = mocklog()[0]['body']['messages'];
ok(str_contains(json_encode(end($bb[0]['content'])), 'likes role-play practice'), 'older chat is folded into Brenda\'s notes and used next time');
ok(count($bb) <= 2 + 4 + 1 + 1, 'only the recent window is sent, not the whole history', (string) count($bb));

echo "\n== Rate limit ==\n";
$last = 0; for ($i = 1; $i <= 22; $i++) { [$last] = req('carol', 'POST', 'chat', '', ['message' => "q$i"]); if ($last === 429) break; }
ok($last === 429 && $i === 21, 'agent is limited after 20 messages in an hour', "stopped at $i with $last");

echo "\n== Usage (questions only) ==\n";
[$c, $d] = req('boss', 'GET', 'usage', '&range=7');
$names = array_column($d['people'], 'name');
ok(in_array('Alan', $names) && in_array('Brenda', $names), 'people table lists agents who asked');
ok($d['tiles']['handoffs'] === 1 && $d['tiles']['questions'] >= 5 && $d['tiles']['agents_using'] >= 3, 'tiles: questions, agents using, handoffs', json_encode($d['tiles']));
$alan = array_values(array_filter($d['people'], fn($p) => $p['name'] === 'Alan'))[0];
[$c, $d, $raw] = req('boss', 'GET', 'usage_member', '&m=' . $alan['mid'], null);
ok(str_contains($raw, 'hello coach') && str_contains($raw, 'UNKNOWN topic'), 'leader sees Alan\'s questions');
ok(!str_contains($raw, 'COACH-REPLY') && !str_contains($raw, 'documents'), 'leader never receives the coach\'s answers');
ok(count(array_filter($d['questions'], fn($q) => $q['handoff'])) === 1, 'handoff question is flagged');
[$c, $d, $raw] = req('boss', 'GET', 'usage_member', '&m=../etc'); ok($c === 422, 'bad agent id refused');

echo "\n== Page and secrets ==\n";
$h = curl_init("$base/index.php"); curl_setopt_array($h, [CURLOPT_HTTPHEADER => ['X-Dev-User: alan'], CURLOPT_RETURNTRANSFER => true]); $html = curl_exec($h); $c = curl_getinfo($h, CURLINFO_RESPONSE_CODE);
ok($c === 200 && str_contains($html, 'AI Coach'), 'page loads for a signed-in agent');
ok(!str_contains($html, 'test-key-123') && !str_contains($html, 'sk-ant'), 'API key is not in the page');
$h = curl_init("$base/index.php"); curl_setopt($h, CURLOPT_RETURNTRANSFER, true); curl_exec($h); ok(curl_getinfo($h, CURLINFO_RESPONSE_CODE) === 401, 'page refuses a signed-out visitor');
ok(!str_contains($allBodies, 'test-key-123'), 'API key never appears in any API response');
$dataDir = getenv('TEST_DATA_DIR'); $docRoot = realpath(__DIR__ . '/..');
ok(!str_starts_with(realpath($dataDir), $docRoot), 'private data folder is outside the web folder');
ok(!glob("$docRoot/*.pdf") && !glob("$docRoot/**/*.pdf"), 'no PDFs stored in the web folder');

echo "\n== Data folder inside the web folder is refused ==\n";
$base2 = getenv('APP_BASE_BADDIR');
$ch = curl_init("$base2/api.php?action=me"); curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['X-Dev-User: alan'], CURLOPT_RETURNTRANSFER => true]); $raw = curl_exec($ch);
ok(curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 500 && str_contains((string) $raw, 'outside public_html'), 'coach refuses to run if its data folder is under public_html');

echo "\nResult: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
