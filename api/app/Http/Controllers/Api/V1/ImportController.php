<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesBudgetUuids;
use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Services\RecordTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ImportController extends Controller
{
    use ResolvesBudgetUuids;

    /**
     * Bulk-import normalized rows (the web CSV wizard does the parsing and
     * column mapping). Dedupe is by import_id — "v1:amount:date:occurrence",
     * YNAB-style — so re-importing the same file skips everything, while two
     * genuinely identical transactions in one file still both import. Deleted
     * imports stay deleted (trashed rows count as existing).
     */
    public function store(Request $request, Budget $budget, RecordTransaction $recorder)
    {
        Gate::authorize('update', $budget);

        $data = $request->validate([
            'account_id' => ['required', 'uuid'],
            'transactions' => ['required', 'array', 'min:1', 'max:1000'],
            'transactions.*.date' => ['required', 'date'],
            'transactions.*.amount' => ['required', 'integer', 'between:-100000000000,100000000000'],
            'transactions.*.payee_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'transactions.*.memo' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $accountId = $this->accountId($budget, $data['account_id']);

        $imported = 0;
        $skipped = 0;
        $occurrences = [];

        DB::transaction(function () use ($budget, $recorder, $data, $accountId, &$imported, &$skipped, &$occurrences): void {
            foreach ($data['transactions'] as $row) {
                $date = date('Y-m-d', strtotime($row['date']));
                $occurrenceKey = "{$row['amount']}:$date";
                $occurrence = $occurrences[$occurrenceKey] = ($occurrences[$occurrenceKey] ?? 0) + 1;
                $importId = "v1:{$row['amount']}:$date:$occurrence";

                $exists = $budget->transactions()
                    ->withTrashed()
                    ->where('account_id', $accountId)
                    ->where('import_id', $importId)
                    ->exists();

                if ($exists) {
                    $skipped++;

                    continue;
                }

                $recorder->create($budget, [
                    'account_id' => $accountId,
                    'date' => $date,
                    'amount' => $row['amount'],
                    'payee_name' => $row['payee_name'] ?? null,
                    'memo' => $row['memo'] ?? null,
                    'cleared' => 'cleared',
                    'approved' => false,
                    'import_id' => $importId,
                ]);
                $imported++;
            }
        });

        return response()->json(['imported' => $imported, 'skipped' => $skipped], 201);
    }

    /** Mark imported transactions as reviewed. */
    public function approveAll(Request $request, Budget $budget)
    {
        Gate::authorize('update', $budget);

        $data = $request->validate(['account_id' => ['sometimes', 'uuid']]);

        $query = $budget->transactions()->where('approved', false);
        if (isset($data['account_id'])) {
            $query->where('account_id', $this->accountId($budget, $data['account_id']));
        }

        return response()->json(['approved' => $query->update(['approved' => true])]);
    }
}
