<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Target;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TargetController extends Controller
{
    /** Create or replace the category's target. */
    public function store(Request $request, Budget $budget, Category $category)
    {
        Gate::authorize('update', $budget);

        abort_if($category->isReadyToAssign(), 404);

        $data = $request->validate([
            'type' => ['required', 'in:refill_monthly,monthly_builder,balance_by_date'],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'target_date' => ['required_if:type,balance_by_date', 'nullable', 'date'],
        ]);

        $target = Target::firstOrNew(['category_id' => $category->id]);
        $target->category_id = $category->id;
        $target->fill([
            'type' => $data['type'],
            'amount' => $data['amount'],
            'target_date' => $data['type'] === 'balance_by_date' ? $data['target_date'] : null,
        ]);
        $target->budget_id = $budget->id;
        $target->save();

        return response()->json(['data' => [
            'type' => $target->type,
            'amount' => $target->amount,
            'target_date' => $target->target_date?->toDateString(),
        ]], 201);
    }

    public function destroy(Budget $budget, Category $category)
    {
        Gate::authorize('update', $budget);

        $category->target?->delete();

        return response()->noContent();
    }
}
