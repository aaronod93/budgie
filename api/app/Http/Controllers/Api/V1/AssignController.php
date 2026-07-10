<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\Category;
use App\Services\AssignMoney;
use App\Services\MonthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AssignController extends Controller
{
    /**
     * Set a category's assigned amount for a month, returning the freshly
     * recalculated month so the client updates in one round-trip.
     */
    public function __invoke(
        Request $request,
        Budget $budget,
        string $month,
        Category $category,
        AssignMoney $assign,
        MonthService $months,
    ) {
        Gate::authorize('update', $budget);

        $data = $request->validate([
            'amount' => ['required', 'integer', 'between:-100000000000,100000000000'],
        ]);

        $parsed = MonthController::parseMonth($month);

        $assign($budget, $parsed, $category, $data['amount']);

        return response()->json($months->compute($budget, $parsed));
    }
}
