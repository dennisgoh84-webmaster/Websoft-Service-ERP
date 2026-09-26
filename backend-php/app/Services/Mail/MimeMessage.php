<?php

namespace App\Services\Mail;

use Illuminate\Support\Carbon;

/**
 * Just enough of a MIME (RFC 5322 / 2045-2047) reader for the Email
 * Inbox: who sent it, the subject, when, the text a person would read,
 * and the names of any attachments. Tolerant of a message cut short (the
 * reader fetches only the first megabyte) and of odd charsets.
 */
final class MimeMessage
{
    private const MAX_TEXT_CHARS = 100000;

    public ?string $fromName = null;

    public string $fromEmail = '';

    public string $subject = '';

    public ?string $messageId = null;

    public ?Carbon $date = null;

    public string $text = '';

    /** @var list<string> */
    public array $attachmentNames = [];

    public static function parse(string $raw): self
    {
        $m = new self;
        [$headers, $body] = self::split($raw);

        [$m->fromName, $m->fromEmail] = self::address(self::decodeHeader($headers['from'] ?? ''));
        $m->subject = trim(preg_replace('/\s+/', ' ', self::decodeHeader($headers['subject'] ?? '')));
        $m->messageId = isset($headers['message-id']) ? trim($headers['message-id']) : null;
        if (isset($headers['date'])) {
            try {
                $m->date = Carbon::parse(preg_replace('/\s*\([^)]*\)\s*$/', '', $headers['date']))->setTimezone(config('app.timezone'));
            } catch (\Throwable) {
                $m->date = null;
            }
        }

        $plain = [];
        $html = [];
        self::walk($headers, $body, $plain, $html, $m->attachmentNames, 0);
        $text = $plain !== [] ? implode("\n\n", $plain) : implode("\n\n", array_map([self::class, 'htmlToText'], $html));
        $text = trim(preg_replace("/\n{3,}/", "\n\n", str_replace(["\r\n", "\r"], "\n", $text)));
        $m->text = mb_substr($text, 0, self::MAX_TEXT_CHARS);

        return $m;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  list<string>  $plain
     * @param  list<string>  $html
     * @param  list<string>  $attachments
     */
    private static function walk(array $headers, string $body, array &$plain, array &$html, array &$attachments, int $depth): void
    {
        [$type, $params] = self::contentType($headers['content-type'] ?? 'text/plain');
        $disposition = strtolower($headers['content-disposition'] ?? '');
        $filename = self::param($disposition !== '' ? $headers['content-disposition'] : '', 'filename') ?? ($params['name'] ?? null);

        if (str_starts_with($type, 'multipart/') && isset($params['boundary']) && $depth < 10) {
            foreach (self::parts($body, $params['boundary']) as $part) {
                [$h, $b] = self::split($part);
                self::walk($h, $b, $plain, $html, $attachments, $depth + 1);
            }

            return;
        }
        if ($filename !== null || str_starts_with($disposition, 'attachment') || $type === 'message/rfc822') {
            $attachments[] = $filename !== null ? self::decodeHeader($filename) : ($type === 'message/rfc822' ? 'forwarded message' : 'attachment');

            return;
        }
        if ($type !== 'text/plain' && $type !== 'text/html') {
            return;
        }
        $decoded = self::toUtf8(self::decodeBody($body, $headers['content-transfer-encoding'] ?? ''), $params['charset'] ?? null);
        if ($type === 'text/plain') {
            $plain[] = $decoded;
        } else {
            $html[] = $decoded;
        }
    }

    /** @return array{0: array<string, string>, 1: string} headers (lower-case names, first occurrence) and body */
    private static function split(string $raw): array
    {
        $pos = strpos($raw, "\r\n\r\n");
        $sep = 4;
        $alt = strpos($raw, "\n\n");
        if ($pos === false || ($alt !== false && $alt < $pos)) {
            $pos = $alt;
            $sep = 2;
        }
        $head = $pos === false ? $raw : substr($raw, 0, $pos);
        $body = $pos === false ? '' : substr($raw, $pos + $sep);

        $headers = [];
        foreach (preg_split('/\r?\n/', preg_replace('/\r?\n[ \t]+/', ' ', $head)) as $line) {
            if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $m)) {
                $name = strtolower($m[1]);
                $headers[$name] ??= $m[2];
            }
        }

        return [$headers, $body];
    }

    /** @return list<string> */
    private static function parts(string $body, string $boundary): array
    {
        $chunks = preg_split('/^--'.preg_quote($boundary, '/').'(--)?[ \t]*\r?$/m', $body);
        array_shift($chunks); // the preamble
        $parts = [];
        foreach ($chunks as $chunk) {
            $chunk = preg_replace('/^\r?\n/', '', $chunk);
            if (trim($chunk) !== '') {
                $parts[] = $chunk;
            }
        }

        return $parts;
    }

    /** @return array{0: string, 1: array<string, string>} */
    private static function contentType(string $value): array
    {
        $type = strtolower(trim(explode(';', $value, 2)[0]));
        $params = [];
        if (preg_match_all('/;\s*([A-Za-z0-9*_-]+)\s*=\s*("([^"]*)"|[^;\s]+)/', $value, $all, PREG_SET_ORDER)) {
            foreach ($all as $p) {
                $params[strtolower($p[1])] = isset($p[3]) && $p[3] !== '' ? $p[3] : trim($p[2], '"');
            }
        }

        return [$type !== '' ? $type : 'text/plain', $params];
    }

    private static function param(string $value, string $name): ?string
    {
        if (preg_match('/;\s*'.$name.'\*?\s*=\s*("([^"]*)"|[^;\s]+)/i', $value, $m)) {
            $v = isset($m[2]) && $m[2] !== '' ? $m[2] : trim($m[1], '"');
            // RFC 2231: filename*=utf-8''name%20here
            if (preg_match("/^([A-Za-z0-9_-]+)'[^']*'(.*)$/", $v, $x)) {
                return self::toUtf8(rawurldecode($x[2]), $x[1]);
            }

            return $v;
        }

        return null;
    }

    private static function decodeBody(string $body, string $encoding): string
    {
        return match (strtolower(trim($encoding))) {
            'base64' => (string) base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $body)),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    private static function toUtf8(string $text, ?string $charset): string
    {
        $charset = $charset ? strtoupper(trim($charset)) : null;
        if ($charset !== null && $charset !== 'UTF-8' && $charset !== 'US-ASCII') {
            try {
                $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
                if (is_string($converted)) {
                    return $converted;
                }
            } catch (\ValueError) {
                // unknown charset -- fall through
            }
            $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
            if (is_string($converted)) {
                return $converted;
            }
        }

        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }

    private static function decodeHeader(string $value): string
    {
        if (! str_contains($value, '=?')) {
            return self::toUtf8($value, null);
        }
        // Encoded words separated only by whitespace are joined (RFC 2047 6.2).
        $value = preg_replace('/(\?=)\s+(=\?)/', '$1$2', $value);

        return preg_replace_callback('/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/', function ($m) {
            $bytes = strtoupper($m[2]) === 'B'
                ? (string) base64_decode($m[3])
                : quoted_printable_decode(str_replace('_', ' ', $m[3]));

            return self::toUtf8($bytes, explode('*', $m[1])[0]);
        }, $value);
    }

    /** @return array{0: ?string, 1: string} display name and address */
    private static function address(string $value): array
    {
        $value = trim($value);
        if (preg_match('/^(.*?)<([^>]+)>/', $value, $m)) {
            $name = trim(trim($m[1]), "\"' ");

            return [$name !== '' ? $name : null, strtolower(trim($m[2]))];
        }

        return [null, strtolower(trim($value, " <>\t"))];
    }

    private static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</(p|div|tr|li|h[1-6]|table|blockquote)>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text);

        return preg_replace("/ *\n */", "\n", $text);
    }
}
