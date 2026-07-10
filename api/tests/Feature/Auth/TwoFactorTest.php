<?php

use App\Models\User;

test('login is challenged when two factor is enabled', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $response = $this->postJson('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertOk()->assertJson(['two_factor' => true]);
    $this->assertGuest();
});

test('users can enable two factor authentication', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/user/two-factor-authentication')
        ->assertOk();

    expect($user->fresh()->two_factor_secret)->not->toBeNull();
});
