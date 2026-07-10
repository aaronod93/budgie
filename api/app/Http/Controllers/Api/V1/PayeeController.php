<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PayeeResource;
use App\Models\Budget;
use App\Models\Payee;
use App\Models\ScheduledTransaction;
use App\Models\SubTransaction;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PayeeController extends Controller
{
    public function index(Budget $budget)
    {
        Gate::authorize('view', $budget);

        $payees = $budget->payees()->with(['transferAccount', 'defaultCategory'])->orderBy('name')->get();

        // Payee memory: each payee's most recent transaction tells the client
        // which category to pre-select and whether to focus outflow or inflow.
        $latest = Transaction::query()
            ->whereIn('id', Transaction::query()
                ->selectRaw('MAX(id)')
                ->where('budget_id', $budget->id)
                ->whereNotNull('payee_id')
                ->groupBy('payee_id'))
            ->with('category:id,uuid')
            ->get(['id', 'payee_id', 'category_id', 'amount'])
            ->keyBy('payee_id');

        foreach ($payees as $payee) {
            $last = $latest->get($payee->id);
            $payee->last_category_uuid = $last?->category?->uuid;
            $payee->last_flow = $last === null ? null : ($last->amount < 0 ? 'outflow' : 'inflow');
        }

        return PayeeResource::collection($payees);
    }

    public function update(Request $request, Budget $budget, Payee $payee)
    {
        Gate::authorize('update', $budget);

        // Transfer payees are system-managed and renamed with their account.
        abort_if($payee->transfer_account_id !== null, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'default_category_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        if (array_key_exists('default_category_id', $data)) {
            $payee->default_category_id = $this->resolveCategory($budget, $data['default_category_id']);
            unset($data['default_category_id']);
        }

        $payee->fill($data)->save();
        $payee->load(['transferAccount', 'defaultCategory']);

        return new PayeeResource($payee);
    }

    /** Merge this payee into another: transactions move, this payee is removed. */
    public function merge(Request $request, Budget $budget, Payee $payee)
    {
        Gate::authorize('update', $budget);

        abort_if($payee->transfer_account_id !== null, 404);

        $data = $request->validate(['into_payee_id' => ['required', 'uuid']]);

        $into = $budget->payees()
            ->whereNull('transfer_account_id')
            ->where('uuid', $data['into_payee_id'])
            ->first();

        if ($into === null || $into->id === $payee->id) {
            throw ValidationException::withMessages(['into_payee_id' => 'Choose a different payee to merge into.']);
        }

        DB::transaction(function () use ($payee, $into): void {
            Transaction::where('payee_id', $payee->id)->update(['payee_id' => $into->id]);
            SubTransaction::where('payee_id', $payee->id)->update(['payee_id' => $into->id]);
            ScheduledTransaction::where('payee_id', $payee->id)->update(['payee_id' => $into->id]);
            $payee->delete();
        });

        $into->load(['transferAccount', 'defaultCategory']);

        return new PayeeResource($into);
    }

    private function resolveCategory(Budget $budget, ?string $uuid): ?int
    {
        if ($uuid === null) {
            return null;
        }

        $id = $budget->categories()->whereNull('internal_type')->where('uuid', $uuid)->value('id');

        if ($id === null) {
            throw ValidationException::withMessages(['default_category_id' => 'Unknown category.']);
        }

        return $id;
    }
}
