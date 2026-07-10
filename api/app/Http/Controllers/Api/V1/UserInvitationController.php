<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BudgetResource;
use App\Models\BudgetInvitation;
use App\Services\RecordActivity;
use Illuminate\Http\Request;

/** The invited user's side: see pending invitations, accept or decline. */
class UserInvitationController extends Controller
{
    public function index(Request $request)
    {
        $invitations = BudgetInvitation::query()
            ->where('email', strtolower($request->user()->email))
            ->whereNull('accepted_at')
            ->with(['budget', 'inviter'])
            ->latest()
            ->get();

        return response()->json(['data' => $invitations->map(fn ($invitation) => [
            'uuid' => $invitation->uuid,
            'budget_name' => $invitation->budget->name,
            'invited_by' => $invitation->inviter->name,
            'role' => $invitation->role,
        ])]);
    }

    public function accept(Request $request, string $uuid, RecordActivity $activity)
    {
        $invitation = $this->pendingFor($request, $uuid);

        $budget = $invitation->budget;
        $member = $budget->memberships()->make(['role' => $invitation->role]);
        $member->user_id = $request->user()->id;
        $member->save();

        $invitation->accepted_at = now();
        $invitation->save();

        $activity($budget, $request->user(), 'member.joined', "{$request->user()->name} joined as $invitation->role");

        return new BudgetResource($budget);
    }

    public function decline(Request $request, string $uuid)
    {
        $this->pendingFor($request, $uuid)->delete();

        return response()->noContent();
    }

    private function pendingFor(Request $request, string $uuid): BudgetInvitation
    {
        return BudgetInvitation::query()
            ->where('uuid', $uuid)
            ->where('email', strtolower($request->user()->email))
            ->whereNull('accepted_at')
            ->firstOrFail();
    }
}
