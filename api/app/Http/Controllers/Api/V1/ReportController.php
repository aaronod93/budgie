<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReportController extends Controller
{
    public function spending(Request $request, Budget $budget, ReportService $reports)
    {
        Gate::authorize('view', $budget);

        [$from, $to] = $this->range($request);
        $groupBy = $request->validate(['group_by' => ['sometimes', 'in:category,payee']])['group_by'] ?? 'category';

        return response()->json($reports->spending($budget, $from, $to, $groupBy));
    }

    public function netWorth(Budget $budget, ReportService $reports)
    {
        Gate::authorize('view', $budget);

        return response()->json($reports->netWorth($budget));
    }

    public function incomeExpense(Request $request, Budget $budget, ReportService $reports)
    {
        Gate::authorize('view', $budget);

        [$from, $to] = $this->range($request);

        return response()->json($reports->incomeExpense($budget, $from, $to));
    }

    public function ageOfMoney(Budget $budget, ReportService $reports)
    {
        Gate::authorize('view', $budget);

        return response()->json(['age_of_money' => $reports->ageOfMoney($budget)]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(Request $request): array
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m'],
            'to' => ['sometimes', 'date_format:Y-m'],
        ]);

        $to = isset($data['to'])
            ? CarbonImmutable::createFromFormat('!Y-m', $data['to'])
            : CarbonImmutable::now()->startOfMonth();
        $from = isset($data['from'])
            ? CarbonImmutable::createFromFormat('!Y-m', $data['from'])
            : $to->subMonths(11);

        abort_if($from->greaterThan($to), 422, 'from must be before to');

        return [$from->startOfMonth(), $to->startOfMonth()];
    }
}
