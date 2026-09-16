<?php

/**
 * A tiny single-connection fake IMAP4rev1 server, for
 * tests/Feature/ImapClientTest.php to prove ImapClient::testLogin()'s
 * golden path against something real rather than only its failure
 * paths (which just point at an unreachable host/port). Launched via
 * proc_open, handles exactly one connection, then exits.
 *
 * Usage: php fake_imap_server.php <port> <expected_username> <expected_password>
 */
[, $port, $expectedUsername, $expectedPassword] = $argv;

$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "listen failed: {$errstr}\n");
    exit(1);
}
// Signals readiness over stdout rather than a throwaway TCP probe --
// this server only ever accepts ONE connection, so a separate probe
// connection would itself consume that slot and starve the real caller.
fwrite(STDOUT, "READY\n");
fflush(STDOUT);

$conn = stream_socket_accept($server, 30);
if ($conn === false) {
    exit(1);
}

fwrite($conn, "* OK fake IMAP4rev1 server ready\r\n");

$loginLine = fgets($conn, 4096);
if (preg_match('/^(\S+) LOGIN "((?:[^"\\\\]|\\\\.)*)" "((?:[^"\\\\]|\\\\.)*)"\r?\n$/', (string) $loginLine, $m)) {
    [, $tag, $user, $pass] = $m;
    $user = str_replace(['\\"', '\\\\'], ['"', '\\'], $user);
    $pass = str_replace(['\\"', '\\\\'], ['"', '\\'], $pass);
    if ($user === $expectedUsername && $pass === $expectedPassword) {
        fwrite($conn, "{$tag} OK LOGIN completed\r\n");
    } else {
        fwrite($conn, "{$tag} NO LOGIN failed: invalid credentials\r\n");
    }
} else {
    fwrite($conn, "a1 BAD malformed command\r\n");
}

$logoutLine = fgets($conn, 4096);
if (preg_match('/^(\S+) LOGOUT/', (string) $logoutLine, $m)) {
    fwrite($conn, "* BYE logging out\r\n{$m[1]} OK LOGOUT completed\r\n");
}

fclose($conn);
fclose($server);
