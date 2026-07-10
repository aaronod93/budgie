<?php

namespace App\Policies;

use App\Models\Budget;
use App\Models\User;

class BudgetPolicy
{
    /** Owner or any member (editor/viewer) may read. */
    public function view(User $user, Budget $budget): bool
    {
        return $budget->roleOf($user) !== null;
    }

    /** Owner or editor may mutate budget data. */
    public function update(User $user, Budget $budget): bool
    {
        return in_array($budget->roleOf($user), ['owner', 'editor'], true);
    }

    /** Only the owner may delete the budget. */
    public function delete(User $user, Budget $budget): bool
    {
        return $budget->user_id === $user->id;
    }

    /** Only the owner may manage members and invitations. */
    public function share(User $user, Budget $budget): bool
    {
        return $budget->user_id === $user->id;
    }
}
