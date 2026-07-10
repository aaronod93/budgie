<?php

function managementSetup(object $test): array
{
    $budget = budgetFor(login());
    $account = $test->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Checking', 'type' => 'checking', 'balance' => 100000, 'balance_date' => '2026-07-01',
    ])->json('data');

    return [$budget, $account];
}

function monthRow(array $month, string $name): ?array
{
    foreach ($month['groups'] as $group) {
        foreach ($group['categories'] as $category) {
            if ($category['name'] === $name) {
                return $category;
            }
        }
    }

    return null;
}

test('groups and categories can be created and appear on the budget screen', function () {
    [$budget] = managementSetup($this);

    $group = $this->postJson("/api/v1/budgets/{$budget->uuid}/category-groups", ['name' => 'Pets'])
        ->assertCreated()->json('data');

    $this->postJson("/api/v1/budgets/{$budget->uuid}/categories", [
        'name' => 'Vet Bills', 'group_id' => $group['uuid'],
    ])->assertCreated();

    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();
    $names = array_column($month['groups'], 'name');

    expect($names)->toContain('Pets')
        ->and(monthRow($month, 'Vet Bills'))->not->toBeNull();
});

test('groups and categories can be renamed and reordered', function () {
    [$budget] = managementSetup($this);

    $groups = $this->getJson("/api/v1/budgets/{$budget->uuid}/category-groups")->json('data');
    [$bills, $everyday] = [$groups[0], $groups[1]];

    // Rename.
    $this->patchJson("/api/v1/budgets/{$budget->uuid}/category-groups/{$bills['uuid']}", ['name' => 'Fixed Costs'])
        ->assertOk()->assertJsonPath('data.name', 'Fixed Costs');
    $category = $everyday['categories'][0];
    $this->patchJson("/api/v1/budgets/{$budget->uuid}/categories/{$category['uuid']}", ['name' => 'Food'])
        ->assertOk()->assertJsonPath('data.name', 'Food');

    // Reorder groups: Everyday first.
    $order = array_column($groups, 'uuid');
    $this->postJson("/api/v1/budgets/{$budget->uuid}/category-groups-reorder", [
        'order' => [$order[1], $order[0], $order[2]],
    ])->assertNoContent();

    // Reorder categories inside Everyday: reverse them.
    $categoryOrder = array_reverse(array_column($everyday['categories'], 'uuid'));
    $this->postJson("/api/v1/budgets/{$budget->uuid}/categories-reorder", [
        'group_id' => $everyday['uuid'],
        'order' => $categoryOrder,
    ])->assertNoContent();

    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();
    expect($month['groups'][0]['name'])->toBe('Everyday')
        ->and($month['groups'][0]['categories'][0]['name'])->toBe('Fun Money')
        ->and($month['groups'][1]['name'])->toBe('Fixed Costs');
});

test('a category can move to a different group', function () {
    [$budget] = managementSetup($this);

    $groups = $this->getJson("/api/v1/budgets/{$budget->uuid}/category-groups")->json('data');
    $bills = collect($groups)->firstWhere('name', 'Bills');
    $savings = collect($groups)->firstWhere('name', 'Savings');
    $phone = collect($bills['categories'])->firstWhere('name', 'Phone');

    $this->patchJson("/api/v1/budgets/{$budget->uuid}/categories/{$phone['uuid']}", [
        'group_id' => $savings['uuid'],
    ])->assertOk()->assertJsonPath('data.group_uuid', $savings['uuid']);

    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();
    $savingsGroup = collect($month['groups'])->firstWhere('name', 'Savings');
    expect(array_column($savingsGroup['categories'], 'name'))->toContain('Phone');
});

test('hidden categories leave the budget screen but keep their money', function () {
    [$budget] = managementSetup($this);
    $groceries = $budget->categories()->where('name', 'Groceries')->first();

    $this->postJson("/api/v1/budgets/{$budget->uuid}/months/2026-07/categories/{$groceries->uuid}/assign", ['amount' => 20000]);
    $this->patchJson("/api/v1/budgets/{$budget->uuid}/categories/{$groceries->uuid}", ['hidden' => true]);

    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();
    expect(monthRow($month, 'Groceries'))->toBeNull()
        ->and($month['ready_to_assign'])->toBe(80000); // still assigned

    $this->patchJson("/api/v1/budgets/{$budget->uuid}/categories/{$groceries->uuid}", ['hidden' => false]);
    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();
    expect(monthRow($month, 'Groceries')['available'])->toBe(20000);
});

test('deleting a category with history requires migrating it, and the money follows', function () {
    [$budget, $account] = managementSetup($this);
    $groceries = $budget->categories()->where('name', 'Groceries')->first();
    $eatingOut = $budget->categories()->where('name', 'Eating Out')->first();

    $this->postJson("/api/v1/budgets/{$budget->uuid}/months/2026-07/categories/{$groceries->uuid}/assign", ['amount' => 30000]);
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'], 'date' => '2026-07-10', 'amount' => -12000,
        'payee_name' => 'Woolworths', 'category_id' => $groceries->uuid,
    ]);

    // Without a target: refused.
    $this->deleteJson("/api/v1/budgets/{$budget->uuid}/categories/{$groceries->uuid}")
        ->assertUnprocessable();

    // With a target: history and assignment merge into it.
    $this->deleteJson("/api/v1/budgets/{$budget->uuid}/categories/{$groceries->uuid}?migrate_to={$eatingOut->uuid}")
        ->assertNoContent();

    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();
    expect(monthRow($month, 'Groceries'))->toBeNull()
        ->and(monthRow($month, 'Eating Out'))->toMatchArray([
            'assigned' => 30000, 'activity' => -12000, 'available' => 18000,
        ])
        ->and($month['ready_to_assign'])->toBe(70000);

    $rows = $this->getJson("/api/v1/budgets/{$budget->uuid}/transactions")->json('data');
    $moved = collect($rows)->firstWhere('amount', -12000);
    expect($moved['category']['name'])->toBe('Eating Out');
});

test('an unused category deletes without a migration target', function () {
    [$budget] = managementSetup($this);
    $holiday = $budget->categories()->where('name', 'Holiday')->first();

    $this->deleteJson("/api/v1/budgets/{$budget->uuid}/categories/{$holiday->uuid}")->assertNoContent();

    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();
    expect(monthRow($month, 'Holiday'))->toBeNull();
});

test('a group can only be deleted once empty', function () {
    [$budget] = managementSetup($this);

    $groups = $this->getJson("/api/v1/budgets/{$budget->uuid}/category-groups")->json('data');
    $savings = collect($groups)->firstWhere('name', 'Savings');

    $this->deleteJson("/api/v1/budgets/{$budget->uuid}/category-groups/{$savings['uuid']}")
        ->assertUnprocessable();

    foreach ($savings['categories'] as $category) {
        $this->deleteJson("/api/v1/budgets/{$budget->uuid}/categories/{$category['uuid']}")->assertNoContent();
    }

    $this->deleteJson("/api/v1/budgets/{$budget->uuid}/category-groups/{$savings['uuid']}")
        ->assertNoContent();
});

test('the internal group and payment categories stay protected', function () {
    [$budget] = managementSetup($this);
    $this->postJson("/api/v1/budgets/{$budget->uuid}/accounts", ['name' => 'Visa', 'type' => 'credit']);

    $rta = $budget->readyToAssignCategory();
    $payment = $budget->categories()->where('internal_type', 'credit_card_payment')->first();

    $this->deleteJson("/api/v1/budgets/{$budget->uuid}/categories/{$rta->uuid}")->assertNotFound();
    $this->patchJson("/api/v1/budgets/{$budget->uuid}/categories/{$payment->uuid}", ['name' => 'X'])->assertNotFound();
});
