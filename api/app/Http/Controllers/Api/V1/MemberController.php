<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Services\RecordActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MemberController extends Controller
{
    /** Everyone with access can see who shares the budget. */
    public function index(Budget $budget)
    {
        Gate::authorize('view', $budget);

        $members = $budget->memberships()->with('user')->get()->map(fn ($member) => [
            'uuid' => $member->uuid,
            'name' => $member->user->name,
            'email' => $member->user->email,
            'role' => $member->role,
        ]);

        return response()->json(['data' => [
            [
                'uuid' => null,
                'name' => $budget->user->name,
                'email' => $budget->user->email,
                'role' => 'owner',
            ],
            ...$members,
        ]]);
    }

    public function update(Request $request, Budget $budget, string $memberUuid, RecordActivity $activity)
    {
        Gate::authorize('share', $budget);

        $data = $request->validate(['role' => ['required', 'in:editor,viewer']]);

        $member = $budget->memberships()->where('uuid', $memberUuid)->with('user')->firstOrFail();
        $member->role = $data['role'];
        $member->save();

        $activity($budget, $request->user(), 'member.role_changed', "Changed {$member->user->name}'s role to {$data['role']}");

        return response()->json(['data' => [
            'uuid' => $member->uuid,
            'name' => $member->user->name,
            'email' => $member->user->email,
            'role' => $member->role,
        ]]);
    }

    /** The owner can remove anyone; a member can remove themself (leave). */
    public function destroy(Request $request, Budget $budget, string $memberUuid, RecordActivity $activity)
    {
        $member = $budget->memberships()->where('uuid', $memberUuid)->with('user')->firstOrFail();

        if ($member->user_id !== $request->user()->id) {
            Gate::authorize('share', $budget);
        }

        $member->delete();

        $activity(
            $budget,
            $request->user(),
            'member.removed',
            $member->user_id === $request->user()->id
                ? "{$member->user->name} left the budget"
                : "Removed {$member->user->name}",
        );

        return response()->noContent();
    }
}
