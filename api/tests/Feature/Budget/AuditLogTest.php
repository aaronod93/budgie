<?php

use App\Events\BudgetActivity;
use Illuminate\Support\Facades\Event;

test('mutations are recorded in the audit log with the acting user', function () {
    $owner = login();
    $budget = budgetFor($owner);

    $account = $this->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Checking', 'type' => 'checking', 'balance' => 100000,
    ])->json('data');
    $groceries = $budget->categories()->where('name', 'Groceries')->first();

    $this->postJson("/api/v1/budgets/{$budget->uuid}/months/2026-07/categories/{$groceries->uuid}/assign", ['amount' => 5000]);
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'], 'date' => '2026-07-10', 'amount' => -1234,
        'payee_name' => 'Cafe', 'category_id' => $groceries->uuid,
    ]);

    $log = $this->getJson("/api/v1/budgets/{$budget->uuid}/audit-log")->assertOk()->json('data');
    $actions = array_column($log, 'action');

    expect($actions)->toContain('account.created', 'budget.assigned', 'transaction.created')
        ->and($log[0]['user'])->toBe($owner->name)
        ->and(collect($log)->firstWhere('action', 'transaction.created')['description'])
        ->toContain('$12.34', 'Cafe', 'Checking');
});

test('every audited mutation broadcasts to the private budget channel', function () {
    Event::fake([BudgetActivity::class]);

    $budget = budgetFor(login());

    $this->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Checking', 'type' => 'checking',
    ]);

    Event::assertDispatched(BudgetActivity::class, function (BudgetActivity $event) use ($budget) {
        return $event->budgetUuid === $budget->uuid
            && $event->broadcastOn()->name === "private-budget.$budget->uuid"
            && $event->entry['action'] === 'account.created';
    });
});

test('the audit log is scoped to budget members', function () {
    $budget = budgetFor(login());
    login(); // a different user

    $this->getJson("/api/v1/budgets/{$budget->uuid}/audit-log")->assertForbidden();
});
