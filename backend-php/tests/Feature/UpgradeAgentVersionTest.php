<?php

namespace Tests\Feature;

use App\Models\UpgradeAgentState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The version number (2026-09-26): the upgrade agent reports the
 * running and the latest version, e.g. "1.0.214" (deploy/version.sh),
 * and this install keeps them for Central Command's Client Upgrades,
 * which shows them in the same wording as the login screen.
 */
class UpgradeAgentVersionTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-agent-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['websoft.upgrade_agent_token' => self::TOKEN]);
    }

    private function heartbeat(array $extra)
    {
        return $this->postJson('/api/system/upgrade-agent/heartbeat', $extra + [
            'current_sha' => str_repeat('a', 40),
            'current_committed_at' => '2026-09-26T16:43:30+00:00',
            'remote_sha' => str_repeat('b', 40),
            'remote_committed_at' => '2026-09-27T09:00:00+08:00',
            'commits_behind' => 3,
        ], ['X-Upgrade-Agent-Token' => self::TOKEN]);
    }

    public function test_the_agent_reported_version_numbers_are_kept(): void
    {
        $this->heartbeat(['current_version' => '1.0.290', 'remote_version' => '1.0.293'])->assertOk();

        $state = UpgradeAgentState::singleton()->fresh();
        $this->assertSame('1.0.290', $state->current_version);
        $this->assertSame('1.0.293', $state->remote_version);
    }

    public function test_an_agent_from_before_version_numbers_still_reports(): void
    {
        // An install upgrading from before 2026-09-26 runs its old agent
        // script once, which sends no version numbers.
        $this->heartbeat([])->assertOk();

        $state = UpgradeAgentState::singleton()->fresh();
        $this->assertSame(str_repeat('a', 40), $state->current_sha);
        $this->assertNull($state->current_version);
    }

    public function test_a_version_needs_the_agent_token(): void
    {
        $this->postJson('/api/system/upgrade-agent/heartbeat', ['current_version' => '9.9.9'], ['X-Upgrade-Agent-Token' => 'wrong'])
            ->assertStatus(401);
        $this->assertNull(UpgradeAgentState::find(1)?->current_version);
    }
}
