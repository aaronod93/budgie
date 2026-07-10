<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Models\Budget;
use App\Services\CreateAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AccountController extends Controller
{
    public function index(Budget $budget)
    {
        Gate::authorize('view', $budget);

        return AccountResource::collection($this->withBalances($budget)->orderBy('sort_order')->get());
    }

    public function store(Request $request, Budget $budget, CreateAccount $createAccount)
    {
        Gate::authorize('update', $budget);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:checking,savings,cash,credit,tracking'],
            'note' => ['nullable', 'string'],
            'balance' => ['sometimes', 'integer'],
            'balance_date' => ['sometimes', 'date'],
        ]);

        $account = $createAccount(
            $budget,
            ['name' => $data['name'], 'type' => $data['type'], 'note' => $data['note'] ?? null],
            $data['balance'] ?? 0,
            $data['balance_date'] ?? null,
        );

        return (new AccountResource($this->fresh($budget, $account)))->response()->setStatusCode(201);
    }

    public function show(Budget $budget, Account $account)
    {
        Gate::authorize('view', $budget);

        return new AccountResource($this->fresh($budget, $account));
    }

    public function update(Request $request, Budget $budget, Account $account)
    {
        Gate::authorize('update', $budget);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
            'closed' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $account->update($data);

        return new AccountResource($this->fresh($budget, $account));
    }

    public function destroy(Budget $budget, Account $account)
    {
        Gate::authorize('update', $budget);

        $account->transactions()->delete();
        $account->delete();

        return response()->noContent();
    }

    private function withBalances(Budget $budget)
    {
        return $budget->accounts()
            ->withSum('transactions as balance', 'amount')
            ->withSum(['transactions as cleared_balance' => fn ($q) => $q->whereIn('cleared', ['cleared', 'reconciled'])], 'amount');
    }

    private function fresh(Budget $budget, Account $account): Account
    {
        return $this->withBalances($budget)->findOrFail($account->id);
    }
}
