<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesBudgetUuids;
use App\Http\Controllers\Controller;
use App\Http\Resources\ScheduledTransactionResource;
use App\Http\Resources\TransactionResource;
use App\Models\Budget;
use App\Models\ScheduledTransaction;
use App\Services\EnterScheduledTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ScheduledTransactionController extends Controller
{
    use ResolvesBudgetUuids;

    private const EAGER = ['account', 'payee', 'category', 'transferAccount'];

    public function index(Request $request, Budget $budget)
    {
        Gate::authorize('view', $budget);

        $filters = $request->validate(['account_id' => ['sometimes', 'uuid']]);

        $query = $budget->scheduledTransactions()->with(self::EAGER)->orderBy('next_date');

        if (isset($filters['account_id'])) {
            $query->where('account_id', $this->accountId($budget, $filters['account_id']));
        }

        return ScheduledTransactionResource::collection($query->get());
    }

    public function store(Request $request, Budget $budget)
    {
        Gate::authorize('update', $budget);

        $data = $this->validatePayload($request, creating: true);
        $data = $this->resolveIds($budget, $data);

        $scheduled = new ScheduledTransaction(array_intersect_key($data, array_flip([
            'frequency', 'next_date', 'amount', 'memo',
        ])));
        $scheduled->budget_id = $budget->id;
        $scheduled->account_id = $data['account_id'];
        $scheduled->payee_id = $data['payee_id'] ?? null;
        $scheduled->category_id = $data['category_id'] ?? null;
        $scheduled->transfer_account_id = $data['transfer_account_id'] ?? null;

        if (! empty($data['payee_name'])) {
            $scheduled->payee_id = $budget->payees()->firstOrCreate(['name' => trim($data['payee_name'])])->id;
        }

        $scheduled->save();

        return (new ScheduledTransactionResource($scheduled->load(self::EAGER)))
            ->response()->setStatusCode(201);
    }

    public function update(Request $request, Budget $budget, ScheduledTransaction $scheduledTransaction)
    {
        Gate::authorize('update', $budget);

        $data = $this->resolveIds($budget, $this->validatePayload($request, creating: false));

        if (! empty($data['payee_name'])) {
            $data['payee_id'] = $budget->payees()->firstOrCreate(['name' => trim($data['payee_name'])])->id;
        }

        $scheduledTransaction->fill(array_intersect_key($data, array_flip([
            'frequency', 'next_date', 'amount', 'memo',
        ])));
        foreach (['payee_id', 'category_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $scheduledTransaction->$field = $data[$field];
            }
        }
        $scheduledTransaction->save();

        return new ScheduledTransactionResource($scheduledTransaction->load(self::EAGER));
    }

    public function destroy(Budget $budget, ScheduledTransaction $scheduledTransaction)
    {
        Gate::authorize('update', $budget);

        $scheduledTransaction->delete();

        return response()->noContent();
    }

    /** Post the scheduled transaction now and advance its schedule. */
    public function enter(Budget $budget, ScheduledTransaction $scheduledTransaction, EnterScheduledTransaction $enter)
    {
        Gate::authorize('update', $budget);

        $transaction = $enter($scheduledTransaction);

        return (new TransactionResource($transaction->load([
            'account', 'payee', 'category', 'subTransactions.category', 'transferTransaction.account',
        ])))->response()->setStatusCode(201);
    }

    private function validatePayload(Request $request, bool $creating): array
    {
        return $request->validate([
            'account_id' => [$creating ? 'required' : 'prohibited', 'uuid'],
            'frequency' => [$creating ? 'required' : 'sometimes', 'in:once,weekly,fortnightly,monthly,yearly'],
            'next_date' => [$creating ? 'required' : 'sometimes', 'date'],
            'amount' => [$creating ? 'required' : 'sometimes', 'integer', 'between:-100000000000,100000000000'],
            'payee_id' => ['sometimes', 'nullable', 'uuid'],
            'payee_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category_id' => ['sometimes', 'nullable', 'uuid'],
            'transfer_account_id' => [$creating ? 'sometimes' : 'prohibited', 'nullable', 'uuid'],
            'memo' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
    }

    private function resolveIds(Budget $budget, array $data): array
    {
        if (isset($data['account_id'])) {
            $data['account_id'] = $this->accountId($budget, $data['account_id']);
        }
        if (isset($data['transfer_account_id'])) {
            $data['transfer_account_id'] = $this->accountId($budget, $data['transfer_account_id'], 'transfer_account_id');
        }
        if (array_key_exists('category_id', $data)) {
            $data['category_id'] = $this->categoryId($budget, $data['category_id']);
        }
        if (array_key_exists('payee_id', $data)) {
            $data['payee_id'] = $this->payeeId($budget, $data['payee_id']);
        }

        return $data;
    }
}
