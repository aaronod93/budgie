<?php

use App\Models\Budget;
use App\Models\User;
use App\Services\CreateBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/** Create and authenticate a user. */
function login(): User
{
    $user = User::factory()->create();
    test()->actingAs($user);

    return $user;
}

/** Create a budget (with its seeded groups + Ready to Assign) for a user. */
function budgetFor(User $user, string $name = 'Test Budget'): Budget
{
    return app(CreateBudget::class)($user, $name);
}
