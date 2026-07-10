<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AssignController;
use App\Http\Controllers\Api\V1\BudgetController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CategoryGroupController;
use App\Http\Controllers\Api\V1\MonthController;
use App\Http\Controllers\Api\V1\MoveMoneyController;
use App\Http\Controllers\Api\V1\PayeeController;
use App\Http\Controllers\Api\V1\TransactionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::prefix('v1')->group(function () {
        Route::apiResource('budgets', BudgetController::class);

        Route::prefix('budgets/{budget}')->scopeBindings()->group(function () {
            Route::apiResource('accounts', AccountController::class);
            Route::apiResource('category-groups', CategoryGroupController::class)->except('show');
            Route::apiResource('categories', CategoryController::class)->only(['store', 'update', 'destroy']);
            Route::apiResource('payees', PayeeController::class)->only(['index', 'update']);
            Route::apiResource('transactions', TransactionController::class);

            Route::get('months/{month}', [MonthController::class, 'show'])
                ->where('month', '\d{4}-\d{2}');
            Route::post('months/{month}/categories/{category}/assign', AssignController::class)
                ->where('month', '\d{4}-\d{2}');
            Route::post('months/{month}/move-money', MoveMoneyController::class)
                ->where('month', '\d{4}-\d{2}');
        });
    });
});
