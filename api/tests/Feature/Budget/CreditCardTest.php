<?php

function ccSetup(object $test): array
{
    $budget = budgetFor(login());
    $checking = $test->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Checking', 'type' => 'checking', 'balance' => 100000, 'balance_date' => '2026-07-01',
    ])->json('data');
    $card = $test->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Visa', 'type' => 'credit',
    ])->json('data');

    return [$budget, $checking, $card];
}

function ccPayload(array $month, string $name): array
{
    foreach ($month['groups'] as $group) {
        foreach ($group['categories'] as $category) {
            if ($category['name'] === $name) {
                return $category;
            }
        }
    }
    throw new RuntimeException("Category $name not in payload");
}

test('creating a credit account creates a linked payment envelope', function () {
    [$budget] = ccSetup($this);

    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();

    $groupNames = array_column($month['groups'], 'name');
    expect($groupNames)->toContain('Credit Card Payments');

    $payment = ccPayload($month, 'Visa');
    expect($payment['is_credit_card_payment'])->toBeTrue()
        ->and($payment['available'])->toBe(0);
});

test('funded card spending moves budgeted money into the payment envelope', function () {
    [$budget, , $card] = ccSetup($this);
    $groceries = $budget->categories()->where('name', 'Groceries')->first();

    $this->postJson("/api/v1/budgets/{$budget->uuid}/months/2026-07/categories/{$groceries->uuid}/assign", ['amount' => 50000]);
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $card['uuid'], 'date' => '2026-07-10', 'amount' => -30000,
        'payee_name' => 'Woolworths', 'category_id' => $groceries->uuid,
    ]);

    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();

    expect(ccPayload($month, 'Groceries'))->toMatchArray(['activity' => -30000, 'available' => 20000])
        ->and(ccPayload($month, 'Visa'))->toMatchArray(['activity' => 30000, 'available' => 30000])
        ->and($month['ready_to_assign'])->toBe(50000)
        ->and($month['credit_overspend'])->toBe(0);
});

test('unfunded card spending becomes credit overspending, not payment money', function () {
    [$budget, , $card] = ccSetup($this);
    $groceries = $budget->categories()->where('name', 'Groceries')->first();

    $this->postJson("/api/v1/budgets/{$budget->uuid}/months/2026-07/categories/{$groceries->uuid}/assign", ['amount' => 10000]);
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $card['uuid'], 'date' => '2026-07-10', 'amount' => -30000,
        'payee_name' => 'Woolworths', 'category_id' => $groceries->uuid,
    ]);

    $july = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();

    expect(ccPayload($july, 'Groceries')['available'])->toBe(-20000)
        ->and(ccPayload($july, 'Visa')['available'])->toBe(10000)
        ->and($july['credit_overspend'])->toBe(20000);

    // Rollover: credit overspending rolls into card debt; RTA is NOT reduced
    // (unlike cash overspending).
    $august = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-08")->json();

    expect(ccPayload($august, 'Groceries')['available'])->toBe(0)
        ->and(ccPayload($august, 'Visa')['available'])->toBe(10000)
        ->and($august['ready_to_assign'])->toBe(90000)
        ->and($august['credit_overspend'])->toBe(0);
});

test('mixed cash and card overspending attributes the cash part to rta', function () {
    [$budget, $checking, $card] = ccSetup($this);
    $fun = $budget->categories()->where('name', 'Fun Money')->first();

    // No assignment. Cash spend 5000 + card spend 3000 in the same envelope.
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $checking['uuid'], 'date' => '2026-07-10', 'amount' => -5000,
        'payee_name' => 'Pub', 'category_id' => $fun->uuid,
    ]);
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $card['uuid'], 'date' => '2026-07-11', 'amount' => -3000,
        'payee_name' => 'Pub', 'category_id' => $fun->uuid,
    ]);

    $july = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();
    expect(ccPayload($july, 'Fun Money')['available'])->toBe(-8000)
        ->and($july['credit_overspend'])->toBe(3000);

    // August: cash part (5000) reduces RTA, credit part (3000) is card debt.
    $august = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-08")->json();
    expect($august['ready_to_assign'])->toBe(95000)
        ->and(ccPayload($august, 'Fun Money')['available'])->toBe(0);
});

test('paying the card draws down the payment envelope', function () {
    [$budget, $checking, $card] = ccSetup($this);
    $groceries = $budget->categories()->where('name', 'Groceries')->first();

    $this->postJson("/api/v1/budgets/{$budget->uuid}/months/2026-07/categories/{$groceries->uuid}/assign", ['amount' => 50000]);
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $card['uuid'], 'date' => '2026-07-10', 'amount' => -30000,
        'payee_name' => 'Woolworths', 'category_id' => $groceries->uuid,
    ]);

    // Pay the card in full from checking.
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $checking['uuid'], 'date' => '2026-07-20', 'amount' => -30000,
        'transfer_account_id' => $card['uuid'],
    ])->assertCreated();

    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();
    expect(ccPayload($month, 'Visa'))->toMatchArray(['activity' => 0, 'available' => 0]);

    $cardFresh = $this->getJson("/api/v1/budgets/{$budget->uuid}/accounts/{$card['uuid']}")->json('data');
    expect($cardFresh['balance'])->toBe(0);
});

test('a refund on the card pulls money back out of the payment envelope', function () {
    [$budget, , $card] = ccSetup($this);
    $groceries = $budget->categories()->where('name', 'Groceries')->first();

    $this->postJson("/api/v1/budgets/{$budget->uuid}/months/2026-07/categories/{$groceries->uuid}/assign", ['amount' => 50000]);
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $card['uuid'], 'date' => '2026-07-10', 'amount' => -30000,
        'payee_name' => 'Woolworths', 'category_id' => $groceries->uuid,
    ]);
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $card['uuid'], 'date' => '2026-07-15', 'amount' => 5000,
        'payee_name' => 'Woolworths', 'category_id' => $groceries->uuid,
    ]);

    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();

    expect(ccPayload($month, 'Groceries')['available'])->toBe(25000)
        ->and(ccPayload($month, 'Visa')['available'])->toBe(25000);
});

test('editing a card transaction months later recalculates the payment envelope', function () {
    [$budget, , $card] = ccSetup($this);
    $groceries = $budget->categories()->where('name', 'Groceries')->first();

    $this->postJson("/api/v1/budgets/{$budget->uuid}/months/2026-07/categories/{$groceries->uuid}/assign", ['amount' => 50000]);
    $txn = $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $card['uuid'], 'date' => '2026-07-10', 'amount' => -30000,
        'payee_name' => 'Woolworths', 'category_id' => $groceries->uuid,
    ])->json('data');

    expect(ccPayload($this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-09")->json(), 'Visa')['available'])
        ->toBe(30000);

    // Months later, shrink the July transaction.
    $this->patchJson("/api/v1/budgets/{$budget->uuid}/transactions/{$txn['uuid']}", ['amount' => -12000])->assertOk();

    $september = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-09")->json();
    expect(ccPayload($september, 'Visa')['available'])->toBe(12000)
        ->and(ccPayload($september, 'Groceries')['available'])->toBe(38000);
});

test('recategorizing a card transaction moves the funding attribution', function () {
    [$budget, , $card] = ccSetup($this);
    $groceries = $budget->categories()->where('name', 'Groceries')->first();
    $fun = $budget->categories()->where('name', 'Fun Money')->first();

    $this->postJson("/api/v1/budgets/{$budget->uuid}/months/2026-07/categories/{$groceries->uuid}/assign", ['amount' => 30000]);
    $txn = $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $card['uuid'], 'date' => '2026-07-10', 'amount' => -20000,
        'payee_name' => 'Kmart', 'category_id' => $groceries->uuid,
    ])->json('data');

    // Recategorize to the unfunded Fun Money: the move should become credit
    // overspending and the payment envelope should empty.
    $this->patchJson("/api/v1/budgets/{$budget->uuid}/transactions/{$txn['uuid']}", ['category_id' => $fun->uuid]);

    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();
    expect(ccPayload($month, 'Visa')['available'])->toBe(0)
        ->and(ccPayload($month, 'Groceries')['available'])->toBe(30000)
        ->and(ccPayload($month, 'Fun Money')['available'])->toBe(-20000)
        ->and($month['credit_overspend'])->toBe(20000);
});

test('money can be assigned directly to the payment envelope', function () {
    [$budget] = ccSetup($this);
    $payment = $budget->categories()->where('internal_type', 'credit_card_payment')->first();

    $month = $this->postJson(
        "/api/v1/budgets/{$budget->uuid}/months/2026-07/categories/{$payment->uuid}/assign",
        ['amount' => 15000],
    )->assertOk()->json();

    expect(ccPayload($month, 'Visa'))->toMatchArray(['assigned' => 15000, 'available' => 15000])
        ->and($month['ready_to_assign'])->toBe(85000);
});

test('pre-existing card debt as starting balance does not touch the budget', function () {
    $budget = budgetFor(login());
    $this->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Old Visa', 'type' => 'credit', 'balance' => -40000, 'balance_date' => '2026-07-01',
    ])->assertCreated();

    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();

    expect($month['ready_to_assign'])->toBe(0)
        ->and(ccPayload($month, 'Old Visa')['available'])->toBe(0);
});
