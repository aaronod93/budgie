<?php

namespace App\Services;

use App\Events\BudgetActivity;
use App\Models\AuditLog;
use App\Models\Budget;
use App\Models\User;

/**
 * One hook for the two Phase 5 concerns: append to the budget's audit log and
 * broadcast the entry to other live devices on the private budget channel.
 */
class RecordActivity
{
    public function __invoke(
        Budget $budget,
        ?User $user,
        string $action,
        string $description,
        ?string $subjectUuid = null,
    ): void {
        $log = new AuditLog([
            'action' => $action,
            'subject_uuid' => $subjectUuid,
            'description' => $description,
        ]);
        $log->budget_id = $budget->id;
        $log->user_id = $user?->id;
        $log->created_at = now();
        $log->save();

        try {
            BudgetActivity::dispatch($budget->uuid, [
                'action' => $action,
                'description' => $description,
                'subject_uuid' => $subjectUuid,
                'user' => $user?->name,
                'created_at' => $log->created_at->toIso8601String(),
            ]);
        } catch (\Throwable) {
            // Reverb not running (local dev) — the audit row is what matters.
        }
    }
}
