<?php

namespace App\Services\Mail;

use Illuminate\Support\Carbon;

/**
 * Reads a mailbox over IMAP4rev1 for the Email Inbox, over a raw socket
 * like App\Services\ImapClient (ext-imap is deprecated in PHP 8.4).
 *
 * Read-only: messages are fetched with BODY.PEEK, so nothing is marked
 * read, moved or deleted in the mailbox -- staff reading the same
 * mailbox in Gmail or Outlook see it exactly as before.
 *
 * Only the first MAX_FETCH_BYTES of each message are fetched: the text
 * a person typed comes first in a MIME message, and attachments after
 * it are only named, never stored.
 */
class ImapMailbox
{
    public const MAX_FETCH_BYTES = 1048576;

    private int $seq = 0;

    /** @param resource $stream an open connection whose greeting has not been read yet */
    public function __construct(private $stream) {}

    public static function connect(string $host, int $port, bool $useSsl, int $timeoutSeconds = 20): self
    {
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(($useSsl ? 'ssl' : 'tcp')."://{$host}:{$port}", $errno, $errstr, $timeoutSeconds);
        if ($stream === false) {
            throw new MailboxReadError("Could not connect to {$host}:{$port}: {$errstr}");
        }
        stream_set_timeout($stream, $timeoutSeconds);

        return new self($stream);
    }

    public function login(string $username, string $password): void
    {
        $greeting = fgets($this->stream);
        if ($greeting === false || ! str_starts_with(ltrim($greeting), '* OK')) {
            throw new MailboxReadError('Unexpected greeting from the mail server: '.trim((string) $greeting));
        }
        $this->command('LOGIN '.self::quote($username).' '.self::quote($password), 'Sign-in to the mailbox failed');
    }

    /** Opens INBOX read-only and returns its UIDVALIDITY. */
    public function examineInbox(): int
    {
        [$lines] = $this->command('EXAMINE INBOX', 'Could not open the inbox');
        foreach ($lines as $line) {
            if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $line, $m)) {
                return (int) $m[1];
            }
        }
        throw new MailboxReadError('The mail server did not give the inbox a UIDVALIDITY.');
    }

    /** @return list<int> UIDs of messages that arrived on or after $since, ascending */
    public function uidsSince(Carbon $since): array
    {
        return $this->search('SINCE '.$since->format('j-M-Y'));
    }

    /** @return list<int> UIDs above $lastUid, ascending */
    public function uidsAbove(int $lastUid): array
    {
        // "n:*" always includes the newest message even when its UID is below n.
        return array_values(array_filter($this->search('UID '.($lastUid + 1).':*'), fn (int $u) => $u > $lastUid));
    }

    /** @return array{raw: string, internal_date: ?Carbon} */
    public function fetch(int $uid): array
    {
        [$lines, $literals] = $this->command('UID FETCH '.$uid.' (UID INTERNALDATE BODY.PEEK[]<0.'.self::MAX_FETCH_BYTES.'>)', "Could not read message {$uid}");
        $internal = null;
        foreach ($lines as $line) {
            if (preg_match('/INTERNALDATE "([^"]+)"/', $line, $m)) {
                try {
                    $internal = Carbon::createFromFormat('j-M-Y H:i:s O', trim($m[1]))->setTimezone(config('app.timezone'));
                } catch (\Throwable) {
                    $internal = null;
                }
            }
        }
        if ($literals === []) {
            throw new MailboxReadError("The mail server returned no content for message {$uid}.");
        }

        return ['raw' => $literals[0], 'internal_date' => $internal];
    }

    public function logout(): void
    {
        try {
            $this->command('LOGOUT', 'Logout failed');
        } catch (\Throwable) {
            // Signing out is a courtesy; the connection closes either way.
        }
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    /** @return list<int> */
    private function search(string $criteria): array
    {
        [$lines] = $this->command('UID SEARCH '.$criteria, 'Searching the inbox failed');
        $uids = [];
        foreach ($lines as $line) {
            if (preg_match('/^\* SEARCH\b(.*)$/i', trim($line), $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) as $n) {
                    if (ctype_digit($n)) {
                        $uids[] = (int) $n;
                    }
                }
            }
        }
        sort($uids);

        return array_values(array_unique($uids));
    }

    /**
     * Sends one command and reads to its tagged reply, collecting the
     * untagged lines and any literals ({n} followed by n bytes).
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function command(string $command, string $failure): array
    {
        $tag = 'w'.(++$this->seq);
        fwrite($this->stream, "{$tag} {$command}\r\n");
        $lines = [];
        $literals = [];
        while (true) {
            $line = fgets($this->stream);
            if ($line === false) {
                throw new MailboxReadError("{$failure}: the mail server closed the connection.");
            }
            while (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
                $literals[] = $this->readBytes((int) $m[1]);
                $rest = fgets($this->stream);
                if ($rest === false) {
                    throw new MailboxReadError("{$failure}: the mail server closed the connection.");
                }
                $line = preg_replace('/\{\d+\}\r?\n$/', '{literal}', $line).$rest;
            }
            if (str_starts_with($line, "{$tag} ")) {
                if (! preg_match('/^'.$tag.' OK/i', $line)) {
                    throw new MailboxReadError("{$failure}: ".trim(substr($line, strlen($tag) + 1)));
                }

                return [$lines, $literals];
            }
            $lines[] = $line;
        }
    }

    private function readBytes(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->stream, $n - strlen($buf));
            if ($chunk === false || ($chunk === '' && feof($this->stream))) {
                throw new MailboxReadError('The mail server closed the connection mid-message.');
            }
            $buf .= $chunk;
        }

        return $buf;
    }

    private static function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
