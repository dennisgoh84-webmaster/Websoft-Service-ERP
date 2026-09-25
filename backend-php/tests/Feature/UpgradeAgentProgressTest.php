<?php

namespace Tests\Feature;

use App\Models\UpgradeRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Live upgrade progress (2026-09-25): while an upgrade runs, the host
 * agent posts the log so far to /system/upgrade-agent/progress, and
 * Central Command's Client Upgrades screen shows it as it happens.
 */
class UpgradeAgentProgressTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-agent-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['websoft.upgrade_agent_token' => self::TOKEN]);
    }

    private function request(string $status): UpgradeRequest
    {
        return UpgradeRequest::create([
            'kind' => UpgradeRequest::KIND_UPGRADE,
            'target_ref' => str_repeat('a', 40),
            'status' => $status,
            'requested_at' => now(),
            'started_at' => $status === UpgradeRequest::STATUS_RUNNING ? now() : null,
        ]);
    }

    private function progress(string $id, string $log, string $token = self::TOKEN)
    {
        return $this->postJson('/api/system/upgrade-agent/progress', ['id' => $id, 'log' => $log], ['X-Upgrade-Agent-Token' => $token]);
    }

    public function test_a_running_upgrade_gets_its_log_so_far(): void
    {
        $req = $this->request(UpgradeRequest::STATUS_RUNNING);

        $this->progress($req->id, "==> Backing up the database\n")->assertOk()->assertJson(['updated' => true]);
        // Laravel trims request strings, so compare without the final newline.
        $this->assertSame('==> Backing up the database', trim($req->fresh()->log));

        $this->progress($req->id, "==> Backing up the database\n==> Building images\n")->assertOk();
        $this->assertStringContainsString('Building images', $req->fresh()->log);
        $this->assertSame(UpgradeRequest::STATUS_RUNNING, $req->fresh()->status);
    }

    public function test_a_finished_upgrade_keeps_its_final_log(): void
    {
        $req = $this->request(UpgradeRequest::STATUS_SUCCEEDED);
        $req->update(['log' => 'complete log']);

        $this->progress($req->id, 'late partial log')->assertOk()->assertJson(['updated' => false]);
        $this->assertSame('complete log', $req->fresh()->log);
    }

    public function test_progress_needs_the_agent_token(): void
    {
        $req = $this->request(UpgradeRequest::STATUS_RUNNING);

        $this->progress($req->id, 'x', 'wrong-token')->assertStatus(401);
        $this->assertNull($req->fresh()->log);
    }
}
