<?php

namespace App\Services\Mail;

/** Anything that stops the Email Inbox reading the mailbox; the message is shown as is. */
class MailboxReadError extends \RuntimeException {}
