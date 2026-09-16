<?php

namespace App\Services;

/**
 * Minimal IMAP4rev1 client -- just enough to prove a mailbox's IMAP
 * credentials actually work (connect, LOGIN, LOGOUT), for the "Test
 * IMAP connection" button on Maintenance -> System Email. Not a mail
 * reader: nothing here fetches or parses messages.
 *
 * Implemented over raw sockets rather than PHP's `ext-imap`: that
 * extension is deprecated as of PHP 8.4 (and isn't even installed in
 * every environment this runs in), so depending on it here would be
 * fragile for a feature this small. A hand-rolled LOGIN/LOGOUT round
 * trip needs nothing beyond `stream_socket_client()`.
 */
class ImapClient
{
    /**
     * Connect, log in, and log out. Throws ImapException with the
     * server's own response text on any failure -- connection, TLS,
     * or bad credentials -- so the caller can show it directly, same
     * as Mailer's connection test.
     */
    public static function testLogin(
        string $host,
        int $port,
        string $username,
        string $password,
        bool $useSsl,
        int $timeoutSeconds = 15,
    ): void {
        $transport = $useSsl ? 'ssl' : 'tcp';
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            "{$transport}://{$host}:{$port}",
            $errno,
            $errstr,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
        );
        if ($stream === false) {
            throw new ImapException("Could not connect to {$host}:{$port}: {$errstr}");
        }
        stream_set_timeout($stream, $timeoutSeconds);

        try {
            $greeting = fgets($stream, 4096);
            if ($greeting === false || ! str_starts_with(ltrim($greeting), '* OK')) {
                throw new ImapException('Unexpected greeting from IMAP server: '.trim((string) $greeting));
            }

            fwrite($stream, 'a1 LOGIN '.self::quote($username).' '.self::quote($password)."\r\n");
            $response = self::readUntilTagged($stream, 'a1');
            if (! str_starts_with($response, 'a1 OK')) {
                throw new ImapException('IMAP login failed: '.($response ?: 'no response from server'));
            }

            fwrite($stream, "a2 LOGOUT\r\n");
            self::readUntilTagged($stream, 'a2');
        } finally {
            fclose($stream);
        }
    }

    /** IMAP quoted-string: wraps in double quotes, escaping backslash and embedded quotes. */
    private static function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /** Reads lines until one starts with "$tag ", returning that final tagged line. */
    private static function readUntilTagged($stream, string $tag): string
    {
        $last = '';
        while (! feof($stream)) {
            $line = fgets($stream, 4096);
            if ($line === false) {
                break;
            }
            $last = trim($line);
            if (str_starts_with($line, "{$tag} ")) {
                break;
            }
        }

        return $last;
    }
}

class ImapException extends \RuntimeException {}
