<?php
declare(strict_types=1);

/** Cliente SMTP mínimo, sin dependencias (SSL, STARTTLS, AUTH LOGIN/PLAIN). */
class Smtp
{
    private $sock = null;

    public function __construct(
        private string $host, private int $port, private string $secure,
        private string $user, private string $pass, private int $timeout = 20
    ) {}

    public function connect(): void
    {
        $target = ($this->secure === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $errno = 0; $errstr = '';
        $this->sock = @stream_socket_client($target, $errno, $errstr, $this->timeout);
        if (!$this->sock) throw new RuntimeException("No se pudo conectar con $this->host:$this->port ($errstr)");
        stream_set_timeout($this->sock, $this->timeout);
        $this->expect(220);
        $helo = preg_replace('/[^a-zA-Z0-9.\-]/', '', gethostname() ?: 'localhost') ?: 'localhost';
        $caps = $this->cmd("EHLO $helo", 250);
        if ($this->secure === 'tls') {
            $this->cmd('STARTTLS', 220);
            if (!@stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT))
                throw new RuntimeException('No se pudo iniciar TLS');
            $caps = $this->cmd("EHLO $helo", 250);
        }
        if ($this->user !== '') {
            if (preg_match('/AUTH[ =][^\r\n]*LOGIN/i', $caps) || !preg_match('/AUTH[ =][^\r\n]*PLAIN/i', $caps)) {
                $this->cmd('AUTH LOGIN', 334);
                $this->cmd(base64_encode($this->user), 334);
                $this->cmd(base64_encode($this->pass), 235);
            } else {
                $this->cmd('AUTH PLAIN ' . base64_encode("\0$this->user\0$this->pass"), 235);
            }
        }
    }

    public function send(string $from, string $to, string $message): void
    {
        if (!$this->sock) $this->connect();
        $this->cmd("MAIL FROM:<$from>", 250);
        $this->cmd("RCPT TO:<$to>", [250, 251]);
        $this->cmd('DATA', 354);
        $message = preg_replace('/\r\n|\r|\n/', "\r\n", $message);
        $message = preg_replace('/^\./m', '..', $message);
        $this->write($message . "\r\n.\r\n");
        $this->expect(250);
    }

    public function reset(): void
    {
        try { if ($this->sock) $this->cmd('RSET', 250); } catch (Throwable $e) { $this->close(); }
    }

    public function close(): void
    {
        if ($this->sock) { @fwrite($this->sock, "QUIT\r\n"); @fclose($this->sock); }
        $this->sock = null;
    }

    private function write(string $data): void
    {
        $len = strlen($data); $done = 0;
        while ($done < $len) {
            $n = @fwrite($this->sock, substr($data, $done));
            if ($n === false || $n === 0) throw new RuntimeException('Conexión SMTP interrumpida');
            $done += $n;
        }
    }
    private function cmd(string $line, $codes): string
    {
        $this->write($line . "\r\n");
        return $this->expect($codes);
    }
    private function expect($codes): string
    {
        $resp = '';
        while (($l = fgets($this->sock, 2048)) !== false) {
            $resp .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') break;
        }
        if ($resp === '') throw new RuntimeException('El servidor SMTP no responde');
        if (!in_array((int)substr($resp, 0, 3), (array)$codes, true))
            throw new RuntimeException('SMTP: ' . trim($resp));
        return $resp;
    }
}

function mime_header(string $s): string
{
    return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}
function mime_address(string $name, string $email): string
{
    if ($name === '') return $email;
    $n = preg_match('/[^\x20-\x7e]/', $name) ? mime_header($name) : '"' . addcslashes($name, '"\\') . '"';
    return "$n <$email>";
}

/** Construye el mensaje. Devuelve [cabeceras (array), cuerpo]. */
function build_message(array $m): array
{
    $b = 'b' . token(12);
    $domain = substr(strrchr($m['from_email'], '@') ?: '@localhost', 1);
    $h = [
        'Date' => date('r'),
        'From' => mime_address($m['from_name'] ?? '', $m['from_email']),
        'To' => $m['to'],
        'Subject' => mime_header(trim(preg_replace('/\s+/', ' ', $m['subject']))),
        'Message-ID' => '<' . token(12) . '@' . $domain . '>',
        'MIME-Version' => '1.0',
    ];
    if (!empty($m['reply_to'])) $h['Reply-To'] = $m['reply_to'];
    if (!empty($m['unsub_url'])) {
        $h['List-Unsubscribe'] = '<' . $m['unsub_url'] . '>';
        $h['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
    }
    $alt = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($m['text'])) . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($m['html'])) . "--$b--\r\n";
    if (empty($m['attachments'])) {
        $h['Content-Type'] = "multipart/alternative; boundary=\"$b\"";
        return [$h, $alt];
    }
    // Con adjuntos: multipart/mixed que envuelve el cuerpo (texto + HTML) y cada archivo
    $mx = 'm' . token(12);
    $h['Content-Type'] = "multipart/mixed; boundary=\"$mx\"";
    $body = "--$mx\r\nContent-Type: multipart/alternative; boundary=\"$b\"\r\n\r\n" . $alt;
    foreach ($m['attachments'] as $path) $body .= "--$mx\r\n" . attachment_part($path);
    return [$h, $body . "--$mx--\r\n"];
}

/** Parte MIME de un adjunto. Se guarda en memoria porque es idéntica para todos los destinatarios del lote. */
function attachment_part(string $path): string
{
    static $cache = [];
    if (isset($cache[$path])) return $cache[$path];
    $data = @file_get_contents($path);
    if ($data === false) throw new RuntimeException('No se pudo leer el adjunto ' . basename($path));
    $name = addcslashes(basename($path), '"\\');
    $part = 'Content-Type: ' . media_mime($path) . "; name=\"$name\"\r\nContent-Transfer-Encoding: base64\r\n"
        . "Content-Disposition: attachment; filename=\"$name\"\r\n\r\n" . chunk_split(base64_encode($data));
    if (count($cache) < 10) $cache[$path] = $part;
    return $part;
}
