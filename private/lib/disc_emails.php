<?php
declare(strict_types=1);

require_once __DIR__ . '/emails.php';

/** Emails for the DISC step. Wording is neutral: nothing here says whether the person "qualified". */

function disc_mail_date(string $utc, string $lang): string
{
    $t = (int)strtotime($utc . ' UTC') + 8 * 3600;   // Malaysia time
    return $lang === 'zh' ? gmdate('Y年n月j日', $t) : gmdate('j M Y', $t);
}

function disc_button(string $url, string $label): string
{
    return '<p><a href="' . h($url) . '" style="display:inline-block;background:#24333A;color:#FFFFFF;text-decoration:none;padding:12px 22px;border-radius:999px">' . h($label) . '</a></p>';
}

/** @return array{subject:string, html:string, text:string} */
function build_disc_invite_email(array $cand, string $link, bool $reminder, array $advisor, array $config, string $expiresUtc): array
{
    $zh = ($cand['lang'] ?? 'en') === 'zh';
    $name = mail_clean_name($cand['name']);
    $adv = mail_clean_name($advisor['name']);
    $until = disc_mail_date($expiresUtc, $zh ? 'zh' : 'en');
    if ($zh) {
        $subject = ($reminder ? '提醒：' : '') . '你在 DreamHub E3 的下一步';
        $lines = ["$name 你好，", '', ($reminder ? '提醒你：' : '') . "感谢你完成职业代码测评。顾问 $adv 邀请你进行下一步：一份简短的 DISC 工作风格问卷（共28题，约10分钟）。", '',
            "开始链接：$link", '', '开始时需要输入你手机号码的最后4位数字，用来确认是你本人。此链接只能使用一次，有效至 ' . $until . '。', '', 'DreamHub E3'];
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;color:#24333A;line-height:1.6;max-width:520px"><p>' . h("$name 你好，") . '</p><p>' . h(($reminder ? '提醒你：' : '') . "感谢你完成职业代码测评。顾问 $adv 邀请你进行下一步：一份简短的 DISC 工作风格问卷（共28题，约10分钟）。")
            . '</p>' . disc_button($link, '开始问卷') . '<p style="font-size:13px;color:#5C6870">' . h('开始时需要输入你手机号码的最后4位数字，用来确认是你本人。此链接只能使用一次，有效至 ' . $until . '。') . '</p><p>DreamHub E3</p></div>';
    } else {
        $subject = ($reminder ? 'Reminder: ' : '') . 'Your next step with DreamHub E3';
        $lines = ["Hi $name,", '', ($reminder ? 'A quick reminder. ' : '') . "Thank you for completing the Career Code. $adv has invited you to the next step: a short DISC working-style questionnaire (28 questions, about 10 minutes).", '',
            "Start here: $link", '', 'You will be asked for the last 4 digits of your mobile number to confirm it is you. The link works once and is valid until ' . $until . '.', '', 'DreamHub E3'];
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;color:#24333A;line-height:1.6;max-width:520px"><p>' . h("Hi $name,") . '</p><p>' . h(($reminder ? 'A quick reminder. ' : '') . "Thank you for completing the Career Code. $adv has invited you to the next step: a short DISC working-style questionnaire (28 questions, about 10 minutes).")
            . '</p>' . disc_button($link, 'Start the questionnaire') . '<p style="font-size:13px;color:#5C6870">' . h('You will be asked for the last 4 digits of your mobile number to confirm it is you. The link works once and is valid until ' . $until . '.') . '</p><p>DreamHub E3</p></div>';
    }
    return ['subject' => $subject, 'html' => $html, 'text' => implode("\n", $lines)];
}

/** @return array{subject:string, html:string, text:string} */
function build_disc_candidate_email(array $cand, array $view, string $lang, array $config): array
{
    $zh = $lang === 'zh';
    $name = mail_clean_name($cand['name']);
    $list = static fn (array $a): string => '<ul style="padding-left:20px;margin:6px 0">' . implode('', array_map(static fn (string $x): string => '<li>' . h($x) . '</li>', $a)) . '</ul>';
    $textList = static fn (array $a): string => implode("\n", array_map(static fn (string $x): string => '- ' . $x, $a));
    if ($zh) {
        $subject = '你的 DISC 工作风格：' . $view['label'];
        $L = ['hi' => "$name 你好，", 'intro' => '感谢你完成 DISC 工作风格问卷。以下是你的结果。', 'style' => '你的风格', 'str' => '你的优势', 'work' => '你在这样的环境中表现最好', 'tips' => '成长小提示',
            'why' => '此结果反映的是工作风格偏好，而非能力或品格，仅作为交流参考。'];
    } else {
        $subject = 'Your DISC working style: ' . $view['label'];
        $L = ['hi' => "Hi $name,", 'intro' => 'Thank you for completing the DISC working-style questionnaire. Here is your result.', 'style' => 'Your style', 'str' => 'Your strengths', 'work' => 'You do your best work when', 'tips' => 'Tips to grow',
            'why' => 'This result reflects working-style preferences, not ability or character. It is a starting point for a conversation.'];
    }
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;color:#24333A;line-height:1.6;max-width:560px">'
        . '<p>' . h($L['hi']) . '</p><p>' . h($L['intro']) . '</p>'
        . '<div style="border:1px solid #E2DDD8;border-radius:12px;padding:16px 18px;margin:12px 0"><div style="font-size:12px;color:#5C6870;text-transform:uppercase;letter-spacing:1px">' . h($L['style']) . '</div>'
        . '<div style="font-size:22px;font-weight:700;margin:2px 0">' . h($view['label']) . '</div><div style="color:#6E625A">' . h($view['tag']) . '</div><p>' . h($view['desc']) . '</p></div>'
        . '<p><b>' . h($L['str']) . '</b></p>' . $list($view['strengths'])
        . '<p><b>' . h($L['work']) . '</b><br>' . h($view['work']) . '</p>'
        . '<p><b>' . h($L['tips']) . '</b></p>' . $list($view['tips'])
        . '<p style="font-size:12px;color:#5C6870;border-top:1px solid #E2DDD8;padding-top:10px">' . h($L['why']) . '</p></div>';
    $text = implode("\n", [$L['hi'], '', $L['intro'], '', $L['style'] . ': ' . $view['label'] . ' (' . $view['tag'] . ')', $view['desc'], '', $L['str'] . ':', $textList($view['strengths']), '',
        $L['work'] . ': ' . $view['work'], '', $L['tips'] . ':', $textList($view['tips']), '', $L['why']]);
    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

/** Link only: no scores, no style details in the body. @return array{subject:string, html:string, text:string} */
function build_disc_advisor_notice(array $cand, string $style, array $owner, array $recipient, array $config): array
{
    $base = rtrim((string)($config['base_url'] ?? 'https://dreamhub-e3.com'), '/');
    $link = $base . DASHBOARD_PATH . '?candidate=' . (int)$cand['id'];
    $name = mail_clean_name($cand['name']);
    $lines = ["Hi {$recipient['name']},", '', "$name completed the DISC questionnaire.", 'Assigned to: ' . $owner['name'], '', "Open the result and advisor notes (login required): $link"];
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;color:#24333A;line-height:1.55;max-width:520px"><p>' . h("Hi {$recipient['name']},") . '</p><p><b>' . h($name) . '</b> completed the DISC questionnaire.<br>Assigned to: ' . h($owner['name']) . '</p>'
        . '<p><a href="' . h($link) . '" style="display:inline-block;background:#24333A;color:#FFFFFF;text-decoration:none;padding:10px 18px;border-radius:999px">Open result and advisor notes</a></p>'
        . '<p style="font-size:12px;color:#5C6870">Login required. Please do not forward this email.</p></div>';
    return ['subject' => "DISC completed: $name", 'html' => $html, 'text' => implode("\n", $lines)];
}
