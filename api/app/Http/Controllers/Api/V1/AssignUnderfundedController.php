<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Services\MonthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AssignUnderfundedController extends Controller
{
    /**
     * Top up every category with an underfunded target in one action,
     * returning the recalculated month.
     */
    public function __invoke(Budget $budget, string $month, MonthService $months)
    {
        Gate::authorize('update', $budget);

        $parsed = MonthController::parseMonth($month);
        $payload = $months->compute($budget, $parsed);

        DB::transaction(function () use ($budget, $parsed, $payload): void {
            foreach ($payload['groups'] as $group) {
                foreach ($group['categories'] as $category) {
                    $underfunded = $category['target']['underfunded'] ?? 0;
                    if ($underfunded <= 0) {
                        continue;
                    }
                    $categoryId = $budget->categories()->where('uuid', $category['uuid'])->value('id');
                    $row = $budget->monthlyBudgets()->firstOrCreate(
                        ['category_id' => $categoryId, 'month' => $parsed],
                    );
                    $row->increment('assigned', $underfunded);
                }
            }
        });

        return response()->json($months->compute($budget, $parsed));
    }
}
