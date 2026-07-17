<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Target;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

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
            'cadence' => ['sometimes', 'in:week,fortnight,month,quarter,year'],
            'starts_on' => ['sometimes', 'nullable', 'date', 'required_with:repeat_times'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
            'repeat_times' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:600'],
        ]);

        if (! empty($data['ends_on']) && ! empty($data['repeat_times'])) {
            throw ValidationException::withMessages([
                'repeat_times' => 'Use an end date or a repeat count, not both.',
            ]);
        }

        $isBalance = $data['type'] === 'balance_by_date';

        $target = Target::firstOrNew(['category_id' => $category->id]);
        $target->category_id = $category->id;
        $target->fill([
            'type' => $data['type'],
            'amount' => $data['amount'],
            'target_date' => $isBalance ? $data['target_date'] : null,
            'cadence' => $isBalance ? 'month' : ($data['cadence'] ?? 'month'),
            'starts_on' => $isBalance ? null : ($data['starts_on'] ?? null),
            'ends_on' => $isBalance ? null : ($data['ends_on'] ?? null),
            'repeat_times' => $isBalance ? null : ($data['repeat_times'] ?? null),
        ]);
        $target->budget_id = $budget->id;
        $target->save();

        return response()->json(['data' => self::payload($target)], 201);
    }

    /**
     * Replace the target's snooze state. Months are explicit ("snooze this
     * month" / "snooze X times" send the month list); until covers "snooze
     * until a date". Editing the target itself leaves snoozes untouched.
     */
    public function snooze(Request $request, Budget $budget, Category $category)
    {
        Gate::authorize('update', $budget);

        $target = $category->target;
        abort_if($target === null, 404);

        $data = $request->validate([
            'months' => ['present', 'array', 'max:120'],
            'months.*' => ['string', 'regex:/^\d{4}-\d{2}$/'],
            'until' => ['present', 'nullable', 'date'],
        ]);

        $target->snoozed_months = array_values(array_unique($data['months']));
        $target->snoozed_until = $data['until'];
        $target->save();

        return response()->json(['data' => self::payload($target)]);
    }

    public function destroy(Budget $budget, Category $category)
    {
        Gate::authorize('update', $budget);

        $category->target?->delete();

        return response()->noContent();
    }

    private static function payload(Target $target): array
    {
        return [
            'type' => $target->type,
            'amount' => $target->amount,
            'target_date' => $target->target_date?->toDateString(),
            'cadence' => $target->cadence,
            'starts_on' => $target->starts_on?->toDateString(),
            'ends_on' => $target->ends_on?->toDateString(),
            'repeat_times' => $target->repeat_times,
            'snoozed_months' => $target->snoozed_months ?? [],
            'snoozed_until' => $target->snoozed_until?->toDateString(),
        ];
    }
}
