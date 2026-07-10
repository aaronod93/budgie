<?php

use App\Models\User;

test('creating a budget seeds starter groups and a ready to assign category', function () {
    login();

    $response = $this->postJson('/api/v1/budgets', ['name' => 'Home Budget']);

    $response->assertCreated()->assertJsonPath('data.name', 'Home Budget')->assertJsonPath('data.currency', 'AUD');

    $uuid = $response->json('data.uuid');
    $groups = $this->getJson("/api/v1/budgets/$uuid/category-groups")->assertOk()->json('data');

    expect(array_column($groups, 'name'))->toBe(['Bills', 'Everyday', 'Savings'])
        ->and(array_column($groups[1]['categories'], 'name'))->toContain('Groceries');
});

test('budgets are listed for the owner only', function () {
    $user = login();
    budgetFor($user);
    budgetFor(User::factory()->create(), 'Someone Elses');

    $names = array_column($this->getJson('/api/v1/budgets')->assertOk()->json('data'), 'name');

    expect($names)->toBe(['Test Budget']);
});

test('another users budget cannot be viewed or modified', function () {
    $other = budgetFor(User::factory()->create());
    login();

    $this->getJson("/api/v1/budgets/{$other->uuid}")->assertForbidden();
    $this->patchJson("/api/v1/budgets/{$other->uuid}", ['name' => 'X'])->assertForbidden();
    $this->getJson("/api/v1/budgets/{$other->uuid}/months/2026-07")->assertForbidden();
});

test('a budget can be renamed and deleted', function () {
    $budget = budgetFor(login());

    $this->patchJson("/api/v1/budgets/{$budget->uuid}", ['name' => 'Renamed'])
        ->assertOk()->assertJsonPath('data.name', 'Renamed');

    $this->deleteJson("/api/v1/budgets/{$budget->uuid}")->assertNoContent();
    $this->getJson("/api/v1/budgets/{$budget->uuid}")->assertNotFound();
});
