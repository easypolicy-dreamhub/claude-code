<?php
declare(strict_types=1);

/**
 * Serves the same page as index.html, but with the internship wording in the title and link-preview tags,
 * so a /career-code/?p=internship link shows "Apply for the Empowerment Internship" in WhatsApp.
 * Used only through the rewrite rule in .htaccess. If anything goes wrong it serves index.html unchanged.
 */
header('Content-Type: text/html; charset=utf-8');
$html = @file_get_contents(__DIR__ . '/index.html');
if ($html === false) {
    http_response_code(500);
    exit;
}
$title = 'Apply for the Empowerment Internship';
$desc = 'A free 20-minute questionnaire for the DreamHub E3 Empowerment Internship Program. Discover your career strengths and start your application.';
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$swap = [
    '#<title>.*?</title>#s'                                   => '<title>' . $e($title) . ' | DreamHub E3</title>',
    '#<meta name="description" content="[^"]*">#'             => '<meta name="description" content="' . $e($desc) . '">',
    '#<meta property="og:title" content="[^"]*">#'            => '<meta property="og:title" content="' . $e($title) . '">',
    '#<meta property="og:description" content="[^"]*">#'      => '<meta property="og:description" content="' . $e($desc) . '">',
];
foreach ($swap as $pattern => $replacement) {
    $out = preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $replacement), $html, 1);
    if (is_string($out)) {
        $html = $out;
    }
}
echo $html;
