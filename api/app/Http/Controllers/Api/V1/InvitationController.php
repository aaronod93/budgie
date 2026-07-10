<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\BudgetInvitationMail;
use App\Models\Budget;
use App\Services\RecordActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/** Owner-side invitation management, scoped to a budget. */
class InvitationController extends Controller
{
    public function index(Budget $budget)
    {
        Gate::authorize('share', $budget);

        return response()->json(['data' => $budget->invitations()
            ->whereNull('accepted_at')
            ->latest()
            ->get()
            ->map(fn ($invitation) => [
                'uuid' => $invitation->uuid,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'created_at' => $invitation->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function store(Request $request, Budget $budget, RecordActivity $activity)
    {
        Gate::authorize('share', $budget);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'in:editor,viewer'],
        ]);
        $email = strtolower($data['email']);

        if ($email === strtolower($budget->user->email)) {
            throw ValidationException::withMessages(['email' => 'That is the budget owner.']);
        }

        $alreadyMember = $budget->memberships()
            ->whereHas('user', fn ($q) => $q->where('email', $email))
            ->exists();
        if ($alreadyMember) {
            throw ValidationException::withMessages(['email' => 'Already a member of this budget.']);
        }

        // Re-inviting refreshes the role and resends the email.
        $invitation = $budget->invitations()->where('email', $email)->whereNull('accepted_at')->first()
            ?? $budget->invitations()->make();
        $invitation->email = $email;
        $invitation->role = $data['role'];
        $invitation->invited_by = $request->user()->id;
        $invitation->save();

        Mail::to($email)->send(new BudgetInvitationMail($invitation->load(['budget', 'inviter'])));

        $activity($budget, $request->user(), 'member.invited', "Invited $email as {$data['role']}", $invitation->uuid);

        return response()->json(['data' => [
            'uuid' => $invitation->uuid,
            'email' => $invitation->email,
            'role' => $invitation->role,
        ]], 201);
    }

    public function destroy(Budget $budget, string $invitationUuid)
    {
        Gate::authorize('share', $budget);

        $budget->invitations()->where('uuid', $invitationUuid)->whereNull('accepted_at')->firstOrFail()->delete();

        return response()->noContent();
    }
}
