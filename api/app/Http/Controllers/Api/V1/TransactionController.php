<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesBudgetUuids;
use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\Budget;
use App\Models\Transaction;
use App\Services\RecordTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TransactionController extends Controller
{
    use ResolvesBudgetUuids;

    private const EAGER = ['account', 'payee', 'category', 'subTransactions.category', 'transferTransaction.account'];

    public function index(Request $request, Budget $budget)
    {
        Gate::authorize('view', $budget);

        $filters = $request->validate([
            'account_id' => ['sometimes', 'uuid'],
            'category_id' => ['sometimes', 'uuid'],
            'since' => ['sometimes', 'date'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        $query = $budget->transactions()->with(self::EAGER)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit($filters['limit'] ?? 500);

        if (isset($filters['account_id'])) {
            $query->where('account_id', $this->accountId($budget, $filters['account_id']));
        }
        if (isset($filters['category_id'])) {
            $query->where('category_id', $this->categoryId($budget, $filters['category_id']));
        }
        if (isset($filters['since'])) {
            $query->where('date', '>=', $filters['since']);
        }

        return TransactionResource::collection($query->get());
    }

    public function store(Request $request, Budget $budget, RecordTransaction $recorder)
    {
        Gate::authorize('update', $budget);

        $data = $this->validatePayload($request, creating: true);

        $transaction = $recorder->create($budget, $this->resolveIds($budget, $data));

        return (new TransactionResource($transaction->load(self::EAGER)))
            ->response()->setStatusCode(201);
    }

    public function show(Budget $budget, Transaction $transaction)
    {
        Gate::authorize('view', $budget);

        return new TransactionResource($transaction->load(self::EAGER));
    }

    public function update(Request $request, Budget $budget, Transaction $transaction, RecordTransaction $recorder)
    {
        Gate::authorize('update', $budget);

        $data = $this->validatePayload($request, creating: false);

        $transaction = $recorder->update($transaction, $this->resolveIds($budget, $data));

        return new TransactionResource($transaction->load(self::EAGER));
    }

    public function destroy(Request $request, Budget $budget, Transaction $transaction, RecordTransaction $recorder)
    {
        Gate::authorize('update', $budget);

        $recorder->delete($transaction, $request->boolean('force'));

        return response()->noContent();
    }

    private function validatePayload(Request $request, bool $creating): array
    {
        return $request->validate([
            'account_id' => [$creating ? 'required' : 'prohibited', 'uuid'],
            'date' => [$creating ? 'required' : 'sometimes', 'date'],
            'amount' => [$creating ? 'required' : 'sometimes', 'integer', 'between:-100000000000,100000000000'],
            'payee_id' => ['sometimes', 'nullable', 'uuid'],
            'payee_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category_id' => ['sometimes', 'nullable', 'uuid'],
            'memo' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'cleared' => ['sometimes', 'in:uncleared,cleared,reconciled'],
            'approved' => ['sometimes', 'boolean'],
            'force' => ['sometimes', 'boolean'],
            'import_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'transfer_account_id' => [$creating ? 'sometimes' : 'prohibited', 'uuid', 'prohibits:splits'],
            'splits' => ['sometimes', 'array', 'max:50'],
            'splits.*.amount' => ['required_with:splits', 'integer'],
            'splits.*.category_id' => ['sometimes', 'nullable', 'uuid'],
            'splits.*.memo' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
    }

    /** Translate every UUID reference in the payload into internal ids. */
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
        foreach ($data['splits'] ?? [] as $i => $split) {
            if (array_key_exists('category_id', $split)) {
                $data['splits'][$i]['category_id'] = $this->categoryId($budget, $split['category_id'], "splits.$i.category_id");
            }
        }

        return $data;
    }
}
