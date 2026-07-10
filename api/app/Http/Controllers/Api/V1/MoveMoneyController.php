<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Services\MonthService;
use App\Services\MoveMoney;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MoveMoneyController extends Controller
{
    /**
     * Move money between envelopes (null/omitted category = Ready to Assign),
     * returning the freshly recalculated month.
     */
    public function __invoke(
        Request $request,
        Budget $budget,
        string $month,
        MoveMoney $move,
        MonthService $months,
    ) {
        Gate::authorize('update', $budget);

        $data = $request->validate([
            'from_category_id' => ['sometimes', 'nullable', 'uuid'],
            'to_category_id' => ['sometimes', 'nullable', 'uuid'],
            'amount' => ['required', 'integer', 'min:1'],
        ]);

        $parsed = MonthController::parseMonth($month);

        $move(
            $budget,
            $parsed,
            $this->findCategory($budget, $data['from_category_id'] ?? null, 'from_category_id'),
            $this->findCategory($budget, $data['to_category_id'] ?? null, 'to_category_id'),
            $data['amount'],
        );

        return response()->json($months->compute($budget, $parsed));
    }

    private function findCategory(Budget $budget, ?string $uuid, string $field)
    {
        if ($uuid === null) {
            return null;
        }

        $category = $budget->categories()
            ->where('uuid', $uuid)
            ->where(fn ($q) => $q->whereNull('internal_type')->orWhere('internal_type', 'credit_card_payment'))
            ->first();

        if ($category === null) {
            throw ValidationException::withMessages([$field => 'Unknown category.']);
        }

        return $category;
    }
}
