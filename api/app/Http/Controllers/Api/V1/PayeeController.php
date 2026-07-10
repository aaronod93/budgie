<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PayeeResource;
use App\Models\Budget;
use App\Models\Payee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PayeeController extends Controller
{
    public function index(Budget $budget)
    {
        Gate::authorize('view', $budget);

        return PayeeResource::collection(
            $budget->payees()->with('transferAccount')->orderBy('name')->get(),
        );
    }

    public function update(Request $request, Budget $budget, Payee $payee)
    {
        Gate::authorize('update', $budget);

        // Transfer payees are system-managed and renamed with their account.
        abort_if($payee->transfer_account_id !== null, 404);

        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $payee->update($data);
        $payee->load('transferAccount');

        return new PayeeResource($payee);
    }
}
