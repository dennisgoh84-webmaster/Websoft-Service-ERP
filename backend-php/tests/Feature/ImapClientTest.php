<?php

namespace Tests\Feature;

use App\Services\ImapClient;
use App\Services\ImapException;
use Tests\TestCase;

/**
 * App\Services\ImapClient -- the "Test IMAP connection" button's
 * connect/LOGIN/LOGOUT round trip (Maintenance -> System Email, added
 * 2026-09-16 alongside the existing SMTP-based send settings). The
 * golden path runs against tests/Support/fake_imap_server.php (a real
 * socket, not a mock) rather than trusting the protocol parsing
 * untested; the failure paths point at a port nothing listens on,
 * matching how the existing Mailer tests exercise a real refused
 * connection.
 */
class ImapClientTest extends TestCase
{
    private array $procs = [];

    protected function tearDown(): void
    {
        foreach ($this->procs as $proc) {
            proc_terminate($proc);
            proc_close($proc);
        }
        parent::tearDown();
    }

    /**
     * Starts the fake server and waits for its own "READY" line on
     * stdout -- not a throwaway TCP probe connection, which would
     * itself consume the one connection this single-shot server ever
     * accepts and starve the real caller.
     */
    private function startFakeServer(int $port, string $username, string $password): void
    {
        $script = base_path('tests/Support/fake_imap_server.php');
        $proc = proc_open(
            ['php', $script, (string) $port, $username, $password],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);
        $this->procs[] = $proc;

        stream_set_timeout($pipes[1], 5);
        $line = fgets($pipes[1]);
        if ($line === false || trim($line) !== 'READY') {
            $stderr = stream_get_contents($pipes[2]);
            $this->fail("fake IMAP server never signalled ready (stderr: {$stderr})");
        }
    }

    public function test_a_correct_login_succeeds_against_a_real_socket(): void
    {
        $port = random_int(20000, 40000);
        $this->startFakeServer($port, 'otp@webmaster.example', 'correct horse');

        // No exception = success; testLogin() returns void.
        ImapClient::testLogin('127.0.0.1', $port, 'otp@webmaster.example', 'correct horse', false);
        $this->addToAssertionCount(1);
    }

    public function test_a_wrong_password_is_reported_by_the_server_and_thrown(): void
    {
        $port = random_int(20000, 40000);
        $this->startFakeServer($port, 'otp@webmaster.example', 'correct horse');

        $this->expectException(ImapException::class);
        $this->expectExceptionMessage('invalid credentials');
        ImapClient::testLogin('127.0.0.1', $port, 'otp@webmaster.example', 'wrong password', false);
    }

    public function test_credentials_with_quotes_and_backslashes_survive_the_round_trip(): void
    {
        $port = random_int(20000, 40000);
        $password = 'p@ss "word" \\with/ specials';
        $this->startFakeServer($port, 'otp@webmaster.example', $password);

        ImapClient::testLogin('127.0.0.1', $port, 'otp@webmaster.example', $password, false);
        $this->addToAssertionCount(1);
    }

    public function test_an_unreachable_host_throws_a_clear_connection_error(): void
    {
        $this->expectException(ImapException::class);
        $this->expectExceptionMessage('Could not connect');
        ImapClient::testLogin('127.0.0.1', 1, 'user', 'pass', false, timeoutSeconds: 2);
    }
}
