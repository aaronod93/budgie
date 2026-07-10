<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BudgetResource;
use App\Models\Budget;
use App\Services\CreateBudget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BudgetController extends Controller
{
    public function index(Request $request)
    {
        return BudgetResource::collection(
            $request->user()->budgets()->orderBy('created_at')->get(),
        );
    }

    public function store(Request $request, CreateBudget $createBudget)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'currency' => ['sometimes', 'string', 'size:3', 'alpha'],
        ]);

        $budget = $createBudget(
            $request->user(),
            $data['name'],
            strtoupper($data['currency'] ?? 'AUD'),
        );

        return (new BudgetResource($budget))->response()->setStatusCode(201);
    }

    public function show(Budget $budget)
    {
        Gate::authorize('view', $budget);

        return new BudgetResource($budget);
    }

    public function update(Request $request, Budget $budget)
    {
        Gate::authorize('update', $budget);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
        ]);

        $budget->update($data);

        return new BudgetResource($budget);
    }

    public function destroy(Budget $budget)
    {
        Gate::authorize('delete', $budget);

        $budget->delete();

        return response()->noContent();
    }
}
