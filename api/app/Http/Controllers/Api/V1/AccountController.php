<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Models\Budget;
use App\Services\CreateAccount;
use App\Services\RecordActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        app(RecordActivity::class)(
            $budget,
            $request->user(),
            'account.created',
            "Added {$data['type']} account {$account->name}",
            $account->uuid,
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

    /**
     * Deleting an account removes it and everything attached to it: its
     * transactions (mirrored transfer rows on other accounts survive — that
     * money genuinely moved), its schedules, its transfer payee, and a credit
     * card's linked payment category. The client confirms before calling.
     */
    public function destroy(Request $request, Budget $budget, Account $account)
    {
        Gate::authorize('update', $budget);

        $transactionCount = $account->transactions()->count();

        DB::transaction(function () use ($budget, $account): void {
            $account->transactions()->delete();

            $budget->scheduledTransactions()
                ->where(fn ($q) => $q
                    ->where('account_id', $account->id)
                    ->orWhere('transfer_account_id', $account->id))
                ->delete();

            $budget->payees()->where('transfer_account_id', $account->id)->delete();

            $budget->categories()
                ->where('internal_type', 'credit_card_payment')
                ->where('linked_account_id', $account->id)
                ->delete();

            $account->delete();
        });

        app(RecordActivity::class)(
            $budget,
            $request->user(),
            'account.deleted',
            "Deleted account {$account->name} and its $transactionCount transaction(s)",
            $account->uuid,
        );

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
