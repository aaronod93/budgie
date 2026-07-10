<?php

function importSetup(object $test): array
{
    $budget = budgetFor(login());
    $account = $test->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Checking', 'type' => 'checking',
    ])->json('data');

    return [$budget, $account];
}

test('imported rows arrive cleared, unapproved and deduped on re-import', function () {
    [$budget, $account] = importSetup($this);

    $payload = [
        'account_id' => $account['uuid'],
        'transactions' => [
            ['date' => '2026-07-01', 'amount' => -4550, 'payee_name' => 'Woolworths', 'memo' => 'shop'],
            ['date' => '2026-07-02', 'amount' => -1200, 'payee_name' => 'Cafe'],
            ['date' => '2026-07-03', 'amount' => 250000, 'payee_name' => 'Employer'],
        ],
    ];

    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions-import", $payload)
        ->assertCreated()
        ->assertJson(['imported' => 3, 'skipped' => 0]);

    $rows = $this->getJson("/api/v1/budgets/{$budget->uuid}/transactions?unapproved=1")->json('data');
    expect($rows)->toHaveCount(3)
        ->and($rows[0]['cleared'])->toBe('cleared')
        ->and($rows[0]['approved'])->toBeFalse();

    // Re-importing the same file skips everything.
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions-import", $payload)
        ->assertCreated()
        ->assertJson(['imported' => 0, 'skipped' => 3]);
});

test('identical rows within one file both import; occurrences dedupe across imports', function () {
    [$budget, $account] = importSetup($this);

    $payload = [
        'account_id' => $account['uuid'],
        'transactions' => [
            ['date' => '2026-07-01', 'amount' => -500, 'payee_name' => 'Coffee'],
            ['date' => '2026-07-01', 'amount' => -500, 'payee_name' => 'Coffee'],
        ],
    ];

    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions-import", $payload)
        ->assertJson(['imported' => 2, 'skipped' => 0]);
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions-import", $payload)
        ->assertJson(['imported' => 0, 'skipped' => 2]);
});

test('a deleted imported transaction stays deleted on re-import', function () {
    [$budget, $account] = importSetup($this);

    $payload = [
        'account_id' => $account['uuid'],
        'transactions' => [['date' => '2026-07-01', 'amount' => -4550, 'payee_name' => 'Woolworths']],
    ];

    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions-import", $payload);
    $txn = $this->getJson("/api/v1/budgets/{$budget->uuid}/transactions")->json('data.0');
    $this->deleteJson("/api/v1/budgets/{$budget->uuid}/transactions/{$txn['uuid']}")->assertNoContent();

    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions-import", $payload)
        ->assertJson(['imported' => 0, 'skipped' => 1]);
});

test('imports auto-categorise from payee defaults', function () {
    [$budget, $account] = importSetup($this);
    $groceries = $budget->categories()->where('name', 'Groceries')->first();

    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'], 'date' => '2026-06-01', 'amount' => -100,
        'payee_name' => 'Woolworths',
    ]);
    $payee = collect($this->getJson("/api/v1/budgets/{$budget->uuid}/payees")->json('data'))
        ->firstWhere('name', 'Woolworths');
    $this->patchJson("/api/v1/budgets/{$budget->uuid}/payees/{$payee['uuid']}", [
        'default_category_id' => $groceries->uuid,
    ]);

    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions-import", [
        'account_id' => $account['uuid'],
        'transactions' => [['date' => '2026-07-01', 'amount' => -4550, 'payee_name' => 'Woolworths']],
    ]);

    $imported = collect($this->getJson("/api/v1/budgets/{$budget->uuid}/transactions?unapproved=1")->json('data'));
    expect($imported->first()['category']['name'])->toBe('Groceries');
});

test('approve-all marks imported transactions reviewed', function () {
    [$budget, $account] = importSetup($this);

    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions-import", [
        'account_id' => $account['uuid'],
        'transactions' => [
            ['date' => '2026-07-01', 'amount' => -100],
            ['date' => '2026-07-02', 'amount' => -200],
        ],
    ]);

    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions-approve-all", [
        'account_id' => $account['uuid'],
    ])->assertOk()->assertJson(['approved' => 2]);

    expect($this->getJson("/api/v1/budgets/{$budget->uuid}/transactions?unapproved=1")->json('data'))->toBeEmpty();
});

test('transactions can be searched by payee and memo with date bounds', function () {
    [$budget, $account] = importSetup($this);

    foreach ([
        ['2026-07-01', -100, 'Woolworths', null],
        ['2026-07-05', -200, 'Cafe Nero', 'birthday coffee'],
        ['2026-08-01', -300, 'Woolworths', null],
    ] as [$date, $amount, $payee, $memo]) {
        $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
            'account_id' => $account['uuid'], 'date' => $date, 'amount' => $amount,
            'payee_name' => $payee, 'memo' => $memo,
        ]);
    }

    expect($this->getJson("/api/v1/budgets/{$budget->uuid}/transactions?search=woolworths")->json('data'))
        ->toHaveCount(2)
        ->and($this->getJson("/api/v1/budgets/{$budget->uuid}/transactions?search=birthday")->json('data'))
        ->toHaveCount(1)
        ->and($this->getJson("/api/v1/budgets/{$budget->uuid}/transactions?search=woolworths&until=2026-07-31")->json('data'))
        ->toHaveCount(1);
});
