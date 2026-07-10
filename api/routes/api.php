<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AssignController;
use App\Http\Controllers\Api\V1\AssignUnderfundedController;
use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\BudgetController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CategoryGroupController;
use App\Http\Controllers\Api\V1\ImportController;
use App\Http\Controllers\Api\V1\MonthController;
use App\Http\Controllers\Api\V1\MoveMoneyController;
use App\Http\Controllers\Api\V1\PayeeController;
use App\Http\Controllers\Api\V1\ReconcileController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ScheduledTransactionController;
use App\Http\Controllers\Api\V1\TargetController;
use App\Http\Controllers\Api\V1\TransactionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('v1/auth/token', [AuthTokenController::class, 'store'])->middleware('throttle:6,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::delete('v1/auth/token', [AuthTokenController::class, 'destroy']);

    Route::prefix('v1')->group(function () {
        Route::apiResource('budgets', BudgetController::class);

        Route::prefix('budgets/{budget}')->scopeBindings()->group(function () {
            Route::apiResource('accounts', AccountController::class);
            Route::apiResource('category-groups', CategoryGroupController::class)->except('show');
            Route::apiResource('categories', CategoryController::class)->only(['store', 'update', 'destroy']);
            Route::apiResource('payees', PayeeController::class)->only(['index', 'update']);
            Route::apiResource('transactions', TransactionController::class);
            Route::post('transactions-import', [ImportController::class, 'store']);
            Route::post('transactions-approve-all', [ImportController::class, 'approveAll']);
            Route::apiResource('scheduled-transactions', ScheduledTransactionController::class)->except('show');
            Route::post('scheduled-transactions/{scheduled_transaction}/enter', [ScheduledTransactionController::class, 'enter']);
            Route::post('accounts/{account}/reconcile', ReconcileController::class);
            Route::post('payees/{payee}/merge', [PayeeController::class, 'merge']);

            Route::put('categories/{category}/target', [TargetController::class, 'store']);
            Route::delete('categories/{category}/target', [TargetController::class, 'destroy']);
            Route::post('months/{month}/assign-underfunded', AssignUnderfundedController::class)
                ->where('month', '\d{4}-\d{2}');

            Route::get('reports/spending', [ReportController::class, 'spending']);
            Route::get('reports/net-worth', [ReportController::class, 'netWorth']);
            Route::get('reports/income-expense', [ReportController::class, 'incomeExpense']);
            Route::get('reports/age-of-money', [ReportController::class, 'ageOfMoney']);

            Route::get('months/{month}', [MonthController::class, 'show'])
                ->where('month', '\d{4}-\d{2}');
            Route::post('months/{month}/categories/{category}/assign', AssignController::class)
                ->where('month', '\d{4}-\d{2}');
            Route::post('months/{month}/move-money', MoveMoneyController::class)
                ->where('month', '\d{4}-\d{2}');
        });
    });
});
