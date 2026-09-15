<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `user:set-password` is what deploy/install.sh uses to replace the
 * seeder's published demo password with a generated one, so it is worth
 * a test: a fresh server's only login depends on it working.
 */
class SetUserPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $company = Company::factory()->create();

        return User::factory()->for($company)->create([
            'email' => 'owner@example.test',
            'hashed_password' => PasswordPolicy::hash('demo1234'),
            'must_change_password' => true,
        ]);
    }

    public function test_it_sets_the_password_and_clears_the_forced_change(): void
    {
        $user = $this->user();

        $this->artisan('user:set-password', [
            'email' => 'owner@example.test',
            'password' => 'a1b2c3d4e5Ws',
        ])->assertExitCode(0);

        $user->refresh();
        $this->assertTrue(PasswordPolicy::verify('a1b2c3d4e5Ws', $user->hashed_password));
        $this->assertFalse(PasswordPolicy::verify('demo1234', $user->hashed_password));
        $this->assertFalse($user->must_change_password);
    }

    public function test_it_refuses_a_password_that_fails_the_complexity_policy(): void
    {
        $user = $this->user();

        $this->artisan('user:set-password', [
            'email' => 'owner@example.test',
            'password' => 'short',
        ])->assertExitCode(1);

        $user->refresh();
        $this->assertTrue(PasswordPolicy::verify('demo1234', $user->hashed_password));
    }

    public function test_it_refuses_an_unknown_email(): void
    {
        $this->artisan('user:set-password', [
            'email' => 'nobody@example.test',
            'password' => 'a1b2c3d4e5Ws',
        ])->assertExitCode(1);
    }

    /**
     * The installer builds its password as `openssl rand -hex 10` plus a
     * two-letter suffix. Hex alone can in principle come back all
     * digits, which is why the suffix is there -- assert the shape the
     * script actually produces passes the policy.
     */
    public function test_the_shape_the_installer_generates_satisfies_the_policy(): void
    {
        $this->user();
        $generated = bin2hex(random_bytes(10)).'Ws';

        $this->artisan('user:set-password', [
            'email' => 'owner@example.test',
            'password' => $generated,
        ])->assertExitCode(0);
    }
}
