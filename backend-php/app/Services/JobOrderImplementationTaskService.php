<?php

namespace App\Services;

use App\Models\JobOrder;
use App\Models\JobOrderImplementationTask;
use App\Models\Product;
use App\Models\ProductImplementationTemplate;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * NEW FEATURE (not a Python->PHP conversion -- backend/ has no
 * equivalent; built directly in backend-php per Dennis's request, see
 * docs/backlog.md / docs/planned-work.md): "Job Order - To allow
 * choosing of multiple Products and Template to import according to
 * Product".
 *
 * Copies each selected product's Job Implementation Template tasks
 * (App\Models\ProductImplementationTemplate) onto a Job Order as
 * App\Models\JobOrderImplementationTask rows, the same
 * copy-a-template-onto-the-document-at-creation-time pattern already
 * used for PROJECT-type Job Orders' 5-milestone template (see
 * App\Http\Controllers\Api\JobOrderController::PROJECT_MILESTONE_TEMPLATE).
 *
 * DEDUPE CHOICE (documented per CLAUDE.md's "never silently assume a
 * business rule" -- this is an implementation default, not a
 * confirmed rule): when more than one selected product's template
 * contains a task with the same name (case-insensitive, trimmed), only
 * the FIRST occurrence is copied onto the Job Order, in the order the
 * products were selected -- a Job Order shouldn't show "Installation"
 * twice just because two selected products both call for one. The
 * task keeps its original `source_product_id` (whichever product's
 * template it came from first); nothing tracks that a second product
 * also wanted that same step done.
 */
class JobOrderImplementationTaskService
{
    /**
     * Import the Job Implementation Template tasks for each given
     * product onto the Job Order. Only ADDS tasks -- never removes or
     * re-imports a task whose (product_id, task_name) pair is already
     * present on this Job Order, so calling this again after adding
     * more products to an existing Job Order is safe and idempotent.
     *
     * @param  array<string>  $productIds  In selection order.
     * @return int Number of tasks actually added.
     */
    public static function importFromProducts(JobOrder $jobOrder, array $productIds): int
    {
        if (empty($productIds)) {
            return 0;
        }

        $existingNames = JobOrderImplementationTask::where('job_order_id', $jobOrder->id)
            ->get()
            ->map(fn ($t) => self::normalize($t->task_name))
            ->all();
        $seen = array_fill_keys($existingNames, true);

        $maxSortOrder = (int) JobOrderImplementationTask::where('job_order_id', $jobOrder->id)->max('sort_order');
        $nextSortOrder = $maxSortOrder + 1;
        $added = 0;

        foreach ($productIds as $productId) {
            $product = Product::find($productId);
            if ($product === null || $product->company_id !== $jobOrder->company_id) {
                continue;
            }
            $template = ProductImplementationTemplate::with('tasks')->where('product_id', $productId)->first();
            if ($template === null) {
                continue;
            }
            foreach ($template->tasks as $templateTask) {
                $key = self::normalize($templateTask->task_name);
                if (isset($seen[$key])) {
                    continue; // dedupe -- see class docblock
                }
                $seen[$key] = true;

                JobOrderImplementationTask::create([
                    'job_order_id' => $jobOrder->id,
                    'source_product_id' => $productId,
                    'task_name' => $templateTask->task_name,
                    'description' => $templateTask->description,
                    'sort_order' => $nextSortOrder++,
                    'status' => JobOrderImplementationTask::STATUS_PENDING,
                ]);
                $added++;
            }
        }

        return $added;
    }

    private static function normalize(string $taskName): string
    {
        return mb_strtolower(trim($taskName));
    }

    /**
     * 7.3-style gate (mirrors ProjectMilestone completion): only Sales
     * Manager or Owner can mark an implementation task Completed.
     */
    public static function canComplete(User $user): bool
    {
        return in_array($user->role, [User::ROLE_SALES_MANAGER, User::ROLE_OWNER], true);
    }

    public static function markCompleted(JobOrderImplementationTask $task, User $user): void
    {
        $task->status = JobOrderImplementationTask::STATUS_COMPLETED;
        $task->completed_by_user_id = $user->id;
        $task->completed_at = Carbon::now('UTC');
        $task->save();
    }

    public static function markPending(JobOrderImplementationTask $task): void
    {
        $task->status = JobOrderImplementationTask::STATUS_PENDING;
        $task->completed_by_user_id = null;
        $task->completed_at = null;
        $task->save();
    }
}
