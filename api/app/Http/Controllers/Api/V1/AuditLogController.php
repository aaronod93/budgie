<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use Illuminate\Support\Facades\Gate;

class AuditLogController extends Controller
{
    public function index(Budget $budget)
    {
        Gate::authorize('view', $budget);

        $entries = $budget->auditLogs()
            ->with('user:id,name')
            ->latest('created_at')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn ($log) => [
                'action' => $log->action,
                'description' => $log->description,
                'user' => $log->user?->name,
                'created_at' => $log->created_at->toIso8601String(),
            ]);

        return response()->json(['data' => $entries]);
    }
}
