<?php
declare(strict_types=1);

require_once __DIR__ . '/scoring.php';

const DASHBOARD_PATH = '/members/career-code/dashboard/';

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Candidate-facing type descriptions (generated from cc-data.js; a test keeps them equal). */
function holland_info(?string $path = null): array
{
    static $cache = [];
    $path ??= __DIR__ . '/../data/holland.json';
    if (!isset($cache[$path])) {
        $doc = json_decode((string)file_get_contents($path), true);
        if (!is_array($doc) || !isset($doc['info'])) {
            throw new RuntimeException('holland.json is missing or invalid.');
        }
        $cache[$path] = $doc['info'];
    }
    return $cache[$path];
}

function email_strings(string $lang): array
{
    return $lang === 'zh' ? [
        'subject' => fn (string $code) => "你的职业代码：$code",
        'hi' => fn (string $n) => "$n 你好，",
        'intro' => '感谢你完成职业代码测评。以下是你的结果。',
        'code' => '你的职业代码',
        'summary' => fn (string $a, string $b, string $c) => "你最强的兴趣是{$a}，其次是{$b}和{$c}。",
        'tie' => fn (string $a, string $b, string $code) => "你的第三个字母由{$a}和{$b}并列，因此{$code}同样适合描述你。",
        'strengths' => '你的职业优势', 'fields' => '适合你代码的领域',
        'advisor' => fn (string $n) => "DreamHub E3 的顾问 {$n} 会尽快与你联系。你也可以直接联系我们：",
        'wa' => 'WhatsApp 联系我们',
        'why' => '你收到这封邮件，是因为你在 dreamhub-e3.com 完成了职业代码测评。此结果反映的是兴趣，而非能力或品格。',
        'sep' => '、',
    ] : [
        'subject' => fn (string $code) => "Your Career Code: $code",
        'hi' => fn (string $n) => "Hi $n,",
        'intro' => 'Thank you for completing the Career Code assessment. Here are your results.',
        'code' => 'Your career code',
        'summary' => fn (string $a, string $b, string $c) => "Your strongest interest is $a, followed by $b and $c.",
        'tie' => fn (string $a, string $b, string $code) => "Your third letter is tied between $a and $b, so $code describes you equally well.",
        'strengths' => 'Your career strengths', 'fields' => 'Fields that often suit your code',
        'advisor' => fn (string $n) => "$n from DreamHub E3 will contact you soon. You can also reach us directly:",
        'wa' => 'WhatsApp us',
        'why' => 'You received this email because you completed the Career Code assessment at dreamhub-e3.com. This profile reflects interests, not ability or character.',
        'sep' => ', ',
    ];
}

/**
 * The candidate's result email.
 * @return array{subject:string, html:string, text:string}
 */
function build_candidate_email(array $cand, array $scores, array $owner, array $config, ?array $info = null): array
{
    $lang = $cand['lang'] === 'zh' ? 'zh' : 'en';
    $S = email_strings($lang);
    $info ??= holland_info();
    $I = $info[$lang];
    $rank = rank_types($scores);
    $code = implode('', array_slice($rank, 0, 3));
    $contact = $config['contact'] ?? [];
    $wa = (string)($contact['whatsapp'] ?? 'https://wa.me/60126718056');
    $mail = (string)($contact['email'] ?? 'hello@dreamhub-e3.com');

    $summary = $S['summary']($I[$rank[0]]['name'], $I[$rank[1]]['name'], $I[$rank[2]]['name']);
    if ($scores[$rank[2]]['total'] === $scores[$rank[3]]['total']) {
        $summary .= ($lang === 'zh' ? '' : ' ') . $S['tie']($rank[2], $rank[3], $rank[0] . $rank[1] . $rank[3]);
    }
    $top = array_slice($rank, 0, 3);
    $strengths = array_values(array_unique(array_merge(...array_map(fn ($t) => array_slice($I[$t]['strengths'], 0, 3), $top))));
    $fields = array_values(array_unique(array_merge(...array_map(fn ($t) => $I[$t]['fields'], array_slice($rank, 0, 2)))));
    $advisor = $S['advisor']($owner['name']);

    // ---- plain text
    $t = [$S['hi']($cand['name']), '', $S['intro'], '', $S['code'] . ': ' . implode(' ', str_split($code)), $summary, ''];
    foreach ($top as $ty) {
        $t[] = "$ty: {$I[$ty]['name']} ({$I[$ty]['tag']})";
        $t[] = $I[$ty]['desc'];
        $t[] = '';
    }
    $t[] = $S['strengths'] . ': ' . implode($S['sep'], $strengths);
    $t[] = $S['fields'] . ': ' . implode($S['sep'], $fields);
    $t[] = '';
    $t[] = $advisor;
    $t[] = "WhatsApp: $wa";
    $t[] = "Email: $mail";
    $t[] = '';
    $t[] = $S['why'];

    // ---- html
    $cards = '';
    foreach ($top as $ty) {
        $cards .= '<div style="border-left:4px solid #B9AFA7;padding:2px 0 2px 12px;margin:14px 0"><div style="font-weight:700">'
            . h($ty) . ': ' . h($I[$ty]['name']) . ' <span style="font-weight:400;color:#5C6870">(' . h($I[$ty]['tag']) . ')</span></div><div>'
            . h($I[$ty]['desc']) . '</div></div>';
    }
    $html = '<!doctype html><html><body style="margin:0;background:#F4F2EF;font-family:Arial,Helvetica,sans-serif;color:#24333A">'
        . '<div style="max-width:560px;margin:0 auto;background:#FFFFFF">'
        . '<div style="background:#24333A;color:#FFFFFF;padding:18px 24px;font-size:20px;font-weight:700">Dream<span style="color:#B9AFA7">Hub</span> E3</div>'
        . '<div style="padding:24px;line-height:1.55">'
        . '<p style="margin-top:0">' . h($S['hi']($cand['name'])) . '</p><p>' . h($S['intro']) . '</p>'
        . '<p style="margin-bottom:4px;color:#5C6870">' . h($S['code']) . '</p>'
        . '<p style="margin:0 0 12px;font-size:34px;font-weight:700;letter-spacing:6px">' . h($code) . '</p>'
        . '<p>' . h($summary) . '</p>' . $cards
        . '<p><b>' . h($S['strengths']) . '</b><br>' . h(implode($S['sep'], $strengths)) . '</p>'
        . '<p><b>' . h($S['fields']) . '</b><br>' . h(implode($S['sep'], $fields)) . '</p>'
        . '<p>' . h($advisor) . '</p>'
        . '<p><a href="' . h($wa) . '" style="display:inline-block;background:#24333A;color:#FFFFFF;text-decoration:none;padding:10px 18px;border-radius:999px">' . h($S['wa']) . '</a>'
        . ' &nbsp; <a href="mailto:' . h($mail) . '" style="color:#24333A">' . h($mail) . '</a></p>'
        . '<p style="font-size:12px;color:#5C6870;border-top:1px solid #E2DDD8;padding-top:12px">' . h($S['why']) . '</p>'
        . '</div></div></body></html>';

    return ['subject' => $S['subject']($code), 'html' => $html, 'text' => implode("\n", $t)];
}

/**
 * Short heads-up for the advisor and their uplines. Link only: no scores, no advisor notes,
 * so a forwarded or leaked email reveals nothing sensitive.
 * @return array{subject:string, html:string, text:string}
 */
function build_advisor_notice(array $cand, string $code, array $owner, array $recipient, array $config, bool $duplicate): array
{
    $base = rtrim((string)($config['base_url'] ?? 'https://dreamhub-e3.com'), '/');
    $link = $base . DASHBOARD_PATH . '?candidate=' . (int)$cand['id'];
    $purpose = $cand['purpose'] === 'internship' ? 'internship application' : 'career assessment';
    $name = mail_clean_name($cand['name']);
    $lines = [
        "Hi {$recipient['name']},",
        '',
        "$name completed the Career Code ($purpose). Code: $code.",
        'Assigned to: ' . $owner['name'] . ($duplicate ? ' (this person has taken the test before; check the earlier record)' : ''),
        '',
        "Open the full result and advisor notes (login required): $link",
    ];
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;color:#24333A;line-height:1.55;max-width:520px">'
        . '<p>' . h("Hi {$recipient['name']},") . '</p>'
        . '<p><b>' . h($name) . '</b> completed the Career Code (' . h($purpose) . '). Code: <b>' . h($code) . '</b>.<br>Assigned to: '
        . h($owner['name']) . ($duplicate ? ' <i>(this person has taken the test before; check the earlier record)</i>' : '') . '</p>'
        . '<p><a href="' . h($link) . '" style="display:inline-block;background:#24333A;color:#FFFFFF;text-decoration:none;padding:10px 18px;border-radius:999px">Open result and advisor notes</a></p>'
        . '<p style="font-size:12px;color:#5C6870">Login required. Please do not forward this email.</p></div>';
    return ['subject' => "New Career Code result: $name ($code)", 'html' => $html, 'text' => implode("\n", $lines)];
}

function mail_clean_name(string $s): string
{
    return trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s) ?? '');
}
