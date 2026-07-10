<?php

use App\Models\ScheduledTransaction;

function scheduledSetup(object $test): array
{
    $budget = budgetFor(login());
    $account = $test->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Checking', 'type' => 'checking', 'balance' => 100000,
    ])->json('data');

    return [$budget, $account];
}

test('a monthly schedule posts on its date and advances without day overflow', function () {
    [$budget, $account] = scheduledSetup($this);
    $groceries = $budget->categories()->where('name', 'Groceries')->first();

    $scheduled = $this->postJson("/api/v1/budgets/{$budget->uuid}/scheduled-transactions", [
        'account_id' => $account['uuid'],
        'frequency' => 'monthly',
        'next_date' => '2026-01-31',
        'amount' => -19900,
        'payee_name' => 'Rent Co',
        'category_id' => $groceries->uuid,
    ])->assertCreated()->json('data');

    $txn = $this->postJson(
        "/api/v1/budgets/{$budget->uuid}/scheduled-transactions/{$scheduled['uuid']}/enter",
    )->assertCreated()->json('data');

    expect($txn['date'])->toBe('2026-01-31')
        ->and($txn['amount'])->toBe(-19900)
        ->and($txn['payee']['name'])->toBe('Rent Co');

    $list = $this->getJson("/api/v1/budgets/{$budget->uuid}/scheduled-transactions")->json('data');
    expect($list[0]['next_date'])->toBe('2026-02-28'); // Jan 31 -> Feb 28, no overflow
});

test('a one-off schedule retires after being entered', function () {
    [$budget, $account] = scheduledSetup($this);

    $scheduled = $this->postJson("/api/v1/budgets/{$budget->uuid}/scheduled-transactions", [
        'account_id' => $account['uuid'],
        'frequency' => 'once',
        'next_date' => '2026-08-01',
        'amount' => -5000,
        'payee_name' => 'One Off',
    ])->json('data');

    $this->postJson("/api/v1/budgets/{$budget->uuid}/scheduled-transactions/{$scheduled['uuid']}/enter")
        ->assertCreated();

    expect($this->getJson("/api/v1/budgets/{$budget->uuid}/scheduled-transactions")->json('data'))->toBeEmpty();
});

test('a scheduled transfer enters as a mirrored pair', function () {
    [$budget, $account] = scheduledSetup($this);
    $savings = $this->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Savings', 'type' => 'savings',
    ])->json('data');

    $scheduled = $this->postJson("/api/v1/budgets/{$budget->uuid}/scheduled-transactions", [
        'account_id' => $account['uuid'],
        'frequency' => 'monthly',
        'next_date' => '2026-08-01',
        'amount' => -10000,
        'transfer_account_id' => $savings['uuid'],
    ])->json('data');

    $this->postJson("/api/v1/budgets/{$budget->uuid}/scheduled-transactions/{$scheduled['uuid']}/enter")
        ->assertCreated()
        ->assertJsonPath('data.transfer_account_uuid', $savings['uuid']);

    $fresh = $this->getJson("/api/v1/budgets/{$budget->uuid}/accounts/{$savings['uuid']}")->json('data');
    expect($fresh['balance'])->toBe(10000);
});

test('the daily command posts everything due, catching up missed periods', function () {
    [$budget, $account] = scheduledSetup($this);
    $groceries = $budget->categories()->where('name', 'Groceries')->first();

    // Three weeks behind: should post 4 times (3 past + today boundary varies),
    // so pin next_date to exactly 21 days ago -> 4 due postings incl. today's.
    $this->postJson("/api/v1/budgets/{$budget->uuid}/scheduled-transactions", [
        'account_id' => $account['uuid'],
        'frequency' => 'weekly',
        'next_date' => now()->subDays(21)->toDateString(),
        'amount' => -2500,
        'payee_name' => 'Gym',
        'category_id' => $groceries->uuid,
    ]);

    $this->artisan('budgie:post-scheduled')->assertSuccessful();

    $rows = $this->getJson("/api/v1/budgets/{$budget->uuid}/transactions?account_id={$account['uuid']}")->json('data');
    $gym = collect($rows)->filter(fn ($r) => ($r['payee']['name'] ?? '') === 'Gym');
    expect($gym)->toHaveCount(4);

    $next = ScheduledTransaction::first();
    expect($next->next_date->isFuture())->toBeTrue();
});

test('scheduled transactions can be updated and deleted', function () {
    [$budget, $account] = scheduledSetup($this);

    $scheduled = $this->postJson("/api/v1/budgets/{$budget->uuid}/scheduled-transactions", [
        'account_id' => $account['uuid'],
        'frequency' => 'monthly',
        'next_date' => '2026-08-15',
        'amount' => -9900,
        'payee_name' => 'Netflix',
    ])->json('data');

    $this->patchJson("/api/v1/budgets/{$budget->uuid}/scheduled-transactions/{$scheduled['uuid']}", [
        'amount' => -12900,
        'frequency' => 'yearly',
    ])->assertOk()->assertJsonPath('data.amount', -12900);

    $this->deleteJson("/api/v1/budgets/{$budget->uuid}/scheduled-transactions/{$scheduled['uuid']}")
        ->assertNoContent();

    expect($this->getJson("/api/v1/budgets/{$budget->uuid}/scheduled-transactions")->json('data'))->toBeEmpty();
});
