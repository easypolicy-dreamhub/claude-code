<?php
declare(strict_types=1);

require_once __DIR__ . '/http.php';

interface Mailer
{
    /** @throws RuntimeException when the message could not be handed over */
    public function send(string $to, string $subject, string $html, string $text): void;
}

/** Strips anything that could add extra headers (CR/LF injection via a name or subject). */
function mail_clean(string $s): string
{
    return trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s) ?? '');
}

function mail_encode_header(string $s): string
{
    $s = mail_clean($s);
    return preg_match('/^[\x20-\x7E]*$/', $s) ? $s : '=?UTF-8?B?' . base64_encode($s) . '?=';
}

function mail_address(string $email, string $name = ''): string
{
    $email = mail_clean($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid email address.');
    }
    return $name === '' ? $email : mail_encode_header($name) . " <$email>";
}

/**
 * Builds a multipart/alternative message (plain text + HTML, base64 so Chinese is safe).
 * @return array{0: string[], 1: string} [header lines (no To/Subject), body]
 */
function mail_build(string $from, string $fromName, string $html, string $text, string $domain): array
{
    $boundary = '=_e3_' . bin2hex(random_bytes(12));
    $part = static fn (string $type, string $body): string =>
        "--$boundary\r\nContent-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($body), 76, "\r\n");
    $body = $part('text/plain', $text) . $part('text/html', $html) . "--$boundary--\r\n";
    $headers = [
        'From: ' . mail_address($from, $fromName),
        'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . mail_clean($domain) . '>',
        'MIME-Version: 1.0',
        "Content-Type: multipart/alternative; boundary=\"$boundary\"",
    ];
    return [$headers, $body];
}

/** Uses the server's own mail system (exim on cPanel): fewest things to configure. */
final class PhpMailMailer implements Mailer
{
    public function __construct(private readonly string $from, private readonly string $fromName)
    {
    }

    public function send(string $to, string $subject, string $html, string $text): void
    {
        [$headers, $body] = mail_build($this->from, $this->fromName, $html, $text, substr(strrchr($this->from, '@'), 1));
        $ok = mail(mail_address($to), mail_encode_header($subject), str_replace("\r\n", "\n", $body), implode("\n", $headers), '-f' . mail_clean($this->from));
        if (!$ok) {
            throw new RuntimeException('mail() refused the message.');
        }
    }
}

/** Plain SMTP client (implicit TLS on 465, STARTTLS on 587, or none for localhost). */
final class SmtpMailer implements Mailer
{
    /** @var resource|null */
    private $fp = null;

    public function __construct(
        private readonly string $host, private readonly int $port, private readonly string $secure,
        private readonly string $user, private readonly string $pass,
        private readonly string $from, private readonly string $fromName,
        private readonly bool $verifyTls = true, private readonly int $timeout = 15,
    ) {
    }

    public function send(string $to, string $subject, string $html, string $text): void
    {
        [$headers, $body] = mail_build($this->from, $this->fromName, $html, $text, substr(strrchr($this->from, '@'), 1));
        array_unshift($headers, 'To: ' . mail_address($to), 'Subject: ' . mail_encode_header($subject));
        $data = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $data = preg_replace('/^\./m', '..', $data) ?? $data; // dot-stuffing

        try {
            $this->connect();
            $this->cmd('MAIL FROM:<' . mail_clean($this->from) . '>', [250]);
            $this->cmd('RCPT TO:<' . mail_clean(mail_address($to)) . '>', [250, 251]);
            $this->cmd('DATA', [354]);
            fwrite($this->fp, $data . "\r\n.\r\n");
            $this->expect([250]);
            $this->cmd('QUIT', [221]);
        } finally {
            if (is_resource($this->fp)) {
                fclose($this->fp);
            }
            $this->fp = null;
        }
    }

    private function connect(): void
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $this->verifyTls, 'verify_peer_name' => $this->verifyTls]]);
        $scheme = $this->secure === 'ssl' ? 'ssl' : 'tcp';
        $fp = @stream_socket_client("$scheme://{$this->host}:{$this->port}", $errno, $err, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new RuntimeException("SMTP connect failed: $err");
        }
        stream_set_timeout($fp, $this->timeout);
        $this->fp = $fp;
        $this->expect([220]);
        $this->cmd('EHLO ' . (gethostname() ?: 'localhost'), [250]);
        if ($this->secure === 'tls') {
            $this->cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($this->fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP STARTTLS failed.');
            }
            $this->cmd('EHLO ' . (gethostname() ?: 'localhost'), [250]);
        }
        if ($this->user !== '') {
            $this->cmd('AUTH LOGIN', [334]);
            $this->cmd(base64_encode($this->user), [334]);
            $this->cmd(base64_encode($this->pass), [235]);
        }
    }

    private function cmd(string $line, array $ok): string
    {
        fwrite($this->fp, $line . "\r\n");
        return $this->expect($ok);
    }

    private function expect(array $ok): string
    {
        $reply = '';
        while (($line = fgets($this->fp, 1024)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') { // last line of a (possibly multi-line) reply
                break;
            }
        }
        if ($reply === '' || !in_array((int)substr($reply, 0, 3), $ok, true)) {
            throw new RuntimeException('SMTP error: ' . trim($reply));
        }
        return $reply;
    }
}

/** Test double: remembers what would have been sent. */
final class MemoryMailer implements Mailer
{
    /** @var array<int, array{to:string,subject:string,html:string,text:string}> */
    public array $sent = [];
    public bool $fail = false;

    public function send(string $to, string $subject, string $html, string $text): void
    {
        if ($this->fail) {
            throw new RuntimeException('simulated mail failure');
        }
        $this->sent[] = compact('to', 'subject', 'html', 'text');
    }
}

function make_mailer(array $config): Mailer
{
    $m = $config['mail'] ?? [];
    $from = (string)($m['from'] ?? 'hello@dreamhub-e3.com');
    $name = (string)($m['from_name'] ?? 'DreamHub E3');
    if (($m['transport'] ?? 'mail') === 'smtp') {
        return new SmtpMailer(
            (string)$m['host'], (int)($m['port'] ?? 465), (string)($m['secure'] ?? 'ssl'),
            (string)($m['user'] ?? ''), (string)($m['pass'] ?? ''), $from, $name, (bool)($m['verify_tls'] ?? true)
        );
    }
    return new PhpMailMailer($from, $name);
}

/**
 * Sends one test message and reports what happened, in plain words. Used by /members/api/mail_test.php
 * (super admin only) so a mail problem can be diagnosed in seconds instead of by taking a whole test.
 * @return array{ok:bool, transport:string, from:string, to:string, error:?string, advice:string}
 */
function run_mail_test(array $config, Mailer $mailer, string $to): array
{
    $m = $config['mail'] ?? [];
    $transport = ($m['transport'] ?? 'mail') === 'smtp' ? 'smtp' : 'mail';
    $out = ['ok' => false, 'transport' => $transport, 'from' => (string)($m['from'] ?? 'hello@dreamhub-e3.com'), 'to' => $to, 'error' => null, 'advice' => ''];
    try {
        $mailer->send($to, 'DreamHub E3 mail test ' . gmdate('H:i:s'), '<p>This is a test from your DreamHub E3 website. If you can read this, sending works.</p>', "This is a test from your DreamHub E3 website. If you can read this, sending works.\n");
        $out['ok'] = true;
        $out['advice'] = $transport === 'mail'
            ? 'The server accepted the message. Check the inbox AND the spam folder of ' . $to . ' for "DreamHub E3 mail test". If it never shows up, the server is not delivering it: switch to SMTP (login with the mailbox).'
            : 'The mail server accepted the message after logging in. Check the inbox AND the spam folder of ' . $to . ' for "DreamHub E3 mail test".';
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
        $msg = strtolower($e->getMessage());
        $out['advice'] = match (true) {
            str_contains($msg, '535') || str_contains($msg, 'auth') => 'The mailbox login was refused. Check the mailbox address and password in config.php (mail > user, pass).',
            str_contains($msg, 'connect') => 'Could not reach the mail server. Check host and port in config.php (mail > host, port), and that secure matches the port (465 = ssl, 587 = tls).',
            str_contains($msg, 'starttls') || str_contains($msg, 'ssl') || str_contains($msg, 'certificate') => 'The secure connection failed. Try "verify_tls" => false in the mail settings (only for this server), or use the other port.',
            str_contains($msg, 'mail() refused') => 'Your host does not allow websites to send mail this way. Switch to SMTP (login with the mailbox).',
            default => 'The mail server refused the message. The error above says why.',
        };
    }
    return $out;
}
