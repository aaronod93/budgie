<?php

use App\Models\Budget;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Live updates for everyone with access to a budget (owner or member).
Broadcast::channel('budget.{uuid}', function (User $user, string $uuid) {
    $budget = Budget::where('uuid', $uuid)->first();

    return $budget !== null && $user->can('view', $budget);
});
