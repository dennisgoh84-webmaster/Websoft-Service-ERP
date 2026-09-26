<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Existing Software Tasks land "by what they show today" (decision 12.1). */
class SoftwareTaskStatusMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_tasks_are_mapped_by_state_and_each_move_is_logged(): void
    {
        $company = Company::factory()->create();
        $row = fn (string $title, bool $tested, ?string $finish) => tap((string) Str::uuid(), fn ($id) => DB::table('software_tasks')->insert([
            'id' => $id, 'company_id' => $company->id, 'title' => $title, 'is_tested' => $tested, 'programming_finish_date' => $finish,
        ]));
        $tested = $row('Tested one', true, null);
        $late = $row('Past finish date', false, Carbon::yesterday()->toDateString());
        $open = $row('Still going', false, Carbon::tomorrow()->toDateString());
        $none = $row('No date', false, null);

        (require database_path('migrations/2026_09_30_003700_software_task_statuses.php'))->down();
        (require database_path('migrations/2026_09_30_003700_software_task_statuses.php'))->up();

        $status = fn ($id) => DB::table('software_tasks')->where('id', $id)->value('status');
        $this->assertSame('tested', $status($tested));
        $this->assertSame('for_testing', $status($late));
        $this->assertSame('open', $status($open));
        $this->assertSame('open', $status($none));
        $this->assertSame(4, DB::table('audit_log_entries')->where('action', 'status_set_by_migration')->count());
    }
}
