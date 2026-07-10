<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * Token auth for the mobile app (PLAN.md §5): full credentials (plus a TOTP or
 * recovery code when MFA is enabled) are exchanged once for a Sanctum personal
 * access token; from then on the device unlocks biometrically and sends the
 * stored token.
 */
class AuthTokenController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $this->verifyTwoFactor($user, $data['code'] ?? null);
        }

        return response()->json([
            'token' => $user->createToken($data['device_name'])->plainTextToken,
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
        ], 201);
    }

    /** Revoke the token used for this request (mobile sign-out). */
    public function destroy(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    private function verifyTwoFactor(User $user, ?string $code): void
    {
        if ($code === null || $code === '') {
            throw ValidationException::withMessages([
                'code' => 'Two-factor authentication code required.',
            ]);
        }

        $totpValid = app(TwoFactorAuthenticationProvider::class)->verify(
            decrypt($user->two_factor_secret),
            $code,
        );

        if ($totpValid) {
            return;
        }

        // Fall back to recovery codes, consuming the used one.
        $recoveryCodes = json_decode(decrypt($user->two_factor_recovery_codes), true) ?? [];

        if (in_array($code, $recoveryCodes, true)) {
            $user->forceFill([
                'two_factor_recovery_codes' => encrypt(json_encode(
                    array_values(array_diff($recoveryCodes, [$code])),
                )),
            ])->save();

            return;
        }

        throw ValidationException::withMessages([
            'code' => 'The provided two-factor code was invalid.',
        ]);
    }
}
