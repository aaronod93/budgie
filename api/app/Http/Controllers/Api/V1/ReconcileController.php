<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use App\Http\Resources\TransactionResource;
use App\Models\Account;
use App\Models\Budget;
use App\Services\ReconcileAccount;
use App\Services\RecordActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReconcileController extends Controller
{
    public function __invoke(Request $request, Budget $budget, Account $account, ReconcileAccount $reconcile)
    {
        Gate::authorize('update', $budget);

        $data = $request->validate([
            'statement_balance' => ['required', 'integer', 'between:-100000000000,100000000000'],
        ]);

        $result = $reconcile($account, $data['statement_balance']);

        app(RecordActivity::class)(
            $budget,
            $request->user(),
            'account.reconciled',
            "Reconciled {$account->name} to ".TransactionController::dollars($data['statement_balance']),
            $account->uuid,
        );

        $fresh = $budget->accounts()
            ->withSum('transactions as balance', 'amount')
            ->withSum(['transactions as cleared_balance' => fn ($q) => $q->whereIn('cleared', ['cleared', 'reconciled'])], 'amount')
            ->findOrFail($account->id);

        return response()->json([
            'account' => new AccountResource($fresh),
            'adjustment' => $result['adjustment']
                ? new TransactionResource($result['adjustment']->load(['account', 'payee', 'category']))
                : null,
        ]);
    }
}
