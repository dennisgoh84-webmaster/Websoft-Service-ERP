<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PasswordPolicy;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Set a staff user's password from the command line.
 *
 * This exists for deploy/install.sh, which used to leave a fresh
 * server reachable on the seeder's published demo password -- fine on
 * a laptop, not fine on a box about to be exposed through a tunnel.
 * The installer now generates a random one and sets it through here.
 *
 * It goes through PasswordPolicy exactly as every HTTP path does, so a
 * password set here is hashed the same way and has to meet the same
 * complexity rule. It writes no audit row on purpose: an audit trail
 * records who did what, and a shell has no signed-in actor to record.
 */
class SetUserPassword extends Command
{
    protected $signature = 'user:set-password {email : The staff user\'s email address} {password : The new password}';

    protected $description = "Set a staff user's password (used by deploy/install.sh)";

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $password = (string) $this->argument('password');

        try {
            PasswordPolicy::validateComplexity($password);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->error("No staff user with email {$email}.");

            return self::FAILURE;
        }

        $user->hashed_password = PasswordPolicy::hash($password);
        // Whoever runs this has just chosen the password, so there is
        // nothing left for a forced first-login change to accomplish.
        $user->must_change_password = false;
        $user->save();

        $this->info("Password updated for {$email}.");

        return self::SUCCESS;
    }
}
