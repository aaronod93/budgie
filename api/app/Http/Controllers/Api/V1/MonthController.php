<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Services\MonthService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

class MonthController extends Controller
{
    public function show(Budget $budget, string $month, MonthService $months)
    {
        Gate::authorize('view', $budget);

        return response()->json($months->compute($budget, self::parseMonth($month)));
    }

    public static function parseMonth(string $month): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m', $month);

        abort_if(
            $parsed === false || $parsed->year < 2000 || $parsed->year > 2100,
            404,
            'Month out of range.',
        );

        return $parsed->startOfMonth();
    }
}
