<?php

use App\Mail\BudgetInvitationMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

function invitePartner(object $test, $budget, User $partner, string $role = 'editor'): void
{
    $invitation = $test->postJson("/api/v1/budgets/{$budget->uuid}/invitations", [
        'email' => $partner->email, 'role' => $role,
    ])->assertCreated()->json('data');

    $test->actingAs($partner)
        ->postJson("/api/v1/invitations/{$invitation['uuid']}/accept")
        ->assertOk();
}

test('an invited partner receives an email and can accept', function () {
    Mail::fake();

    $owner = login();
    $budget = budgetFor($owner);
    $partner = User::factory()->create();

    $invitation = $this->postJson("/api/v1/budgets/{$budget->uuid}/invitations", [
        'email' => $partner->email, 'role' => 'editor',
    ])->assertCreated()->json('data');

    Mail::assertSent(BudgetInvitationMail::class, fn ($mail) => $mail->hasTo($partner->email));

    $this->actingAs($partner);
    $pending = $this->getJson('/api/v1/invitations')->assertOk()->json('data');
    expect($pending)->toHaveCount(1)
        ->and($pending[0]['budget_name'])->toBe('Test Budget')
        ->and($pending[0]['invited_by'])->toBe($owner->name);

    $this->postJson("/api/v1/invitations/{$invitation['uuid']}/accept")
        ->assertOk()->assertJsonPath('data.role', 'editor');

    $budgets = $this->getJson('/api/v1/budgets')->json('data');
    expect(collect($budgets)->firstWhere('uuid', $budget->uuid)['role'])->toBe('editor');
});

test('editors can mutate the budget but not share or delete it', function () {
    Mail::fake();
    $owner = login();
    $budget = budgetFor($owner);
    $account = $this->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Checking', 'type' => 'checking', 'balance' => 10000,
    ])->json('data');

    $partner = User::factory()->create();
    invitePartner($this, $budget, $partner, 'editor');

    // Editor can record spending and assign money.
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'], 'date' => '2026-07-10', 'amount' => -500, 'payee_name' => 'Cafe',
    ])->assertCreated();

    // But cannot manage sharing or delete the budget.
    $this->postJson("/api/v1/budgets/{$budget->uuid}/invitations", [
        'email' => 'else@example.com', 'role' => 'viewer',
    ])->assertForbidden();
    $this->deleteJson("/api/v1/budgets/{$budget->uuid}")->assertForbidden();
});

test('viewers can read everything but mutate nothing', function () {
    Mail::fake();
    $owner = login();
    $budget = budgetFor($owner);
    $account = $this->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Checking', 'type' => 'checking', 'balance' => 10000,
    ])->json('data');
    $groceries = $budget->categories()->where('name', 'Groceries')->first();

    $partner = User::factory()->create();
    invitePartner($this, $budget, $partner, 'viewer');

    $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->assertOk();
    $this->getJson("/api/v1/budgets/{$budget->uuid}/transactions")->assertOk();
    $this->getJson("/api/v1/budgets/{$budget->uuid}/reports/net-worth")->assertOk();
    $this->getJson("/api/v1/budgets/{$budget->uuid}/audit-log")->assertOk();

    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'], 'date' => '2026-07-10', 'amount' => -500,
    ])->assertForbidden();
    $this->postJson("/api/v1/budgets/{$budget->uuid}/months/2026-07/categories/{$groceries->uuid}/assign", [
        'amount' => 1000,
    ])->assertForbidden();
});

test('the owner manages roles and members; members can leave', function () {
    Mail::fake();
    $owner = login();
    $budget = budgetFor($owner);
    $partner = User::factory()->create();
    invitePartner($this, $budget, $partner, 'editor');

    $this->actingAs($owner);
    $members = $this->getJson("/api/v1/budgets/{$budget->uuid}/members")->json('data');
    expect($members)->toHaveCount(2)
        ->and($members[0]['role'])->toBe('owner')
        ->and($members[1]['role'])->toBe('editor');

    $memberUuid = $members[1]['uuid'];

    // Demote to viewer -> partner loses write access.
    $this->patchJson("/api/v1/budgets/{$budget->uuid}/members/$memberUuid", ['role' => 'viewer'])
        ->assertOk()->assertJsonPath('data.role', 'viewer');

    // Partner leaves.
    $this->actingAs($partner)
        ->deleteJson("/api/v1/budgets/{$budget->uuid}/members/$memberUuid")
        ->assertNoContent();
    $this->getJson("/api/v1/budgets/{$budget->uuid}")->assertForbidden();
});

test('inviting an existing member or the owner is rejected', function () {
    Mail::fake();
    $owner = login();
    $budget = budgetFor($owner);
    $partner = User::factory()->create();
    invitePartner($this, $budget, $partner);

    $this->actingAs($owner);
    $this->postJson("/api/v1/budgets/{$budget->uuid}/invitations", [
        'email' => $owner->email, 'role' => 'editor',
    ])->assertUnprocessable();
    $this->postJson("/api/v1/budgets/{$budget->uuid}/invitations", [
        'email' => $partner->email, 'role' => 'editor',
    ])->assertUnprocessable();
});

test('an invitation can be declined and only the addressee can act on it', function () {
    Mail::fake();
    $owner = login();
    $budget = budgetFor($owner);
    $partner = User::factory()->create();
    $stranger = User::factory()->create();

    $invitation = $this->postJson("/api/v1/budgets/{$budget->uuid}/invitations", [
        'email' => $partner->email, 'role' => 'editor',
    ])->json('data');

    $this->actingAs($stranger)
        ->postJson("/api/v1/invitations/{$invitation['uuid']}/accept")
        ->assertNotFound();

    $this->actingAs($partner)
        ->deleteJson("/api/v1/invitations/{$invitation['uuid']}")
        ->assertNoContent();
    expect($this->getJson('/api/v1/invitations')->json('data'))->toBeEmpty();
});
