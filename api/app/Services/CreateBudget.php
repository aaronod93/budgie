<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateBudget
{
    /**
     * Every budget gets a hidden internal group holding the Ready to Assign
     * category (income flows into it), plus a starter set of envelope groups.
     */
    public function __invoke(User $user, string $name, string $currency = 'AUD'): Budget
    {
        return DB::transaction(function () use ($user, $name, $currency): Budget {
            $budget = $user->budgets()->create(['name' => $name, 'currency' => $currency]);

            $internal = $budget->categoryGroups()->create(['name' => 'Internal', 'hidden' => true]);
            $internal->forceFill(['internal' => true])->save();

            $rta = new Category(['name' => 'Ready to Assign']);
            $rta->budget_id = $budget->id;
            $rta->category_group_id = $internal->id;
            $rta->internal_type = 'ready_to_assign';
            $rta->save();

            $starters = [
                'Bills' => ['Rent/Mortgage', 'Electricity', 'Internet', 'Phone'],
                'Everyday' => ['Groceries', 'Eating Out', 'Transport', 'Fun Money'],
                'Savings' => ['Emergency Fund', 'Holiday'],
            ];

            $groupOrder = 0;
            foreach ($starters as $groupName => $categories) {
                $group = $budget->categoryGroups()->create([
                    'name' => $groupName,
                    'sort_order' => $groupOrder++,
                ]);
                foreach ($categories as $i => $categoryName) {
                    $budget->categories()->create([
                        'name' => $categoryName,
                        'category_group_id' => $group->id,
                        'sort_order' => $i,
                    ]);
                }
            }

            return $budget;
        });
    }
}
