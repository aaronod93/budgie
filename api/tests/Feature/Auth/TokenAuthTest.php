<?php

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

test('valid credentials exchange for a working personal access token', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/api/v1/auth/token', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Pixel Test',
    ]);

    $response->assertCreated()->assertJsonPath('user.email', $user->email);

    $token = $response->json('token');

    $this->flushHeaders();
    $this->getJson('/api/user', ['Authorization' => "Bearer $token"])
        ->assertOk()
        ->assertJsonPath('email', $user->email);
});

test('invalid credentials are rejected', function () {
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/token', [
        'email' => $user->email,
        'password' => 'wrong-password',
        'device_name' => 'Pixel Test',
    ])->assertUnprocessable();
});

test('mfa users must supply a valid totp code', function () {
    $secret = app(Google2FA::class)->generateSecretKey();
    $user = User::factory()->create([
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-one'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $payload = [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Pixel Test',
    ];

    // Missing code
    $this->postJson('/api/v1/auth/token', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    // Wrong code
    $this->postJson('/api/v1/auth/token', [...$payload, 'code' => '000000'])
        ->assertUnprocessable();

    // Valid TOTP
    $this->postJson('/api/v1/auth/token', [
        ...$payload,
        'code' => app(Google2FA::class)->getCurrentOtp($secret),
    ])->assertCreated();
});

test('a recovery code works once and is consumed', function () {
    $secret = app(Google2FA::class)->generateSecretKey();
    $user = User::factory()->create([
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-one', 'recovery-two'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $payload = [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Pixel Test',
        'code' => 'recovery-one',
    ];

    $this->postJson('/api/v1/auth/token', $payload)->assertCreated();
    $this->postJson('/api/v1/auth/token', $payload)->assertUnprocessable();
});

test('the current token can be revoked', function () {
    $user = User::factory()->create();

    $token = $this->postJson('/api/v1/auth/token', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Pixel Test',
    ])->json('token');

    $this->flushHeaders();
    $this->deleteJson('/api/v1/auth/token', [], ['Authorization' => "Bearer $token"])
        ->assertNoContent();

    // The guard caches the authenticated user within a test process; reset it
    // so the next request re-authenticates from the (now deleted) token.
    $this->app['auth']->forgetGuards();
    $this->flushHeaders();
    $this->getJson('/api/user', ['Authorization' => "Bearer $token"])->assertUnauthorized();
});
