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

        // Lifetime money in/out per payee, so the list can show how much has
        // flowed to and from each one across the whole budget.
        $totals = Transaction::query()
            ->where('budget_id', $budget->id)
            ->whereNotNull('payee_id')
            ->groupBy('payee_id')
            ->selectRaw('payee_id')
            ->selectRaw('SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) AS inflow')
            ->selectRaw('SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END) AS outflow')
            ->get()
            ->keyBy('payee_id');

        foreach ($payees as $payee) {
            $last = $latest->get($payee->id);
            $payee->last_category_uuid = $last?->category?->uuid;
            $payee->last_flow = $last === null ? null : ($last->amount < 0 ? 'outflow' : 'inflow');

            $total = $totals->get($payee->id);
            $payee->inflow_total = (int) ($total->inflow ?? 0);
            $payee->outflow_total = (int) ($total->outflow ?? 0);
        }

        return PayeeResource::collection($payees);
    }

    public function store(Request $request, Budget $budget)
    {
        Gate::authorize('update', $budget);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:16'],
        ]);

        $name = trim($data['name']);

        $exists = $budget->payees()
            ->whereNull('transfer_account_id')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => 'A payee with that name already exists.']);
        }

        $payee = $budget->payees()->create([
            'name' => $name,
            'icon' => $data['icon'] ?? null,
        ]);

        $payee->load(['transferAccount', 'defaultCategory']);
        $payee->inflow_total = 0;
        $payee->outflow_total = 0;

        return (new PayeeResource($payee))->response()->setStatusCode(201);
    }

    public function update(Request $request, Budget $budget, Payee $payee)
    {
        Gate::authorize('update', $budget);

        // Transfer payees are system-managed and renamed with their account.
        abort_if($payee->transfer_account_id !== null, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:16'],
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
