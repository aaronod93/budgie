<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Budget;
use App\Models\Category;
use App\Models\ScheduledTransaction;
use App\Models\SubTransaction;
use App\Models\Transaction;
use App\Services\RecordActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    public function store(Request $request, Budget $budget)
    {
        Gate::authorize('update', $budget);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:16'],
            'group_id' => ['required', 'uuid'],
        ]);

        $group = $this->findGroup($budget, $data['group_id']);

        $category = $budget->categories()->create([
            'name' => $data['name'],
            'icon' => $data['icon'] ?? null,
            'category_group_id' => $group->id,
            'sort_order' => ($group->categories()->max('sort_order') ?? -1) + 1,
        ]);

        $category->load('group');

        app(RecordActivity::class)($budget, $request->user(), 'category.created',
            "Added category {$category->name} to {$group->name}", $category->uuid);

        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    public function update(Request $request, Budget $budget, Category $category)
    {
        Gate::authorize('update', $budget);

        abort_if($category->internal_type !== null, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:16'],
            'hidden' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'group_id' => ['sometimes', 'uuid'],
        ]);

        $oldName = $category->name;

        // Move to another group: land at the end of it.
        if (isset($data['group_id'])) {
            $group = $this->findGroup($budget, $data['group_id']);
            if ($group->id !== $category->category_group_id) {
                $category->category_group_id = $group->id;
                $category->sort_order = ($group->categories()->max('sort_order') ?? -1) + 1;
            }
            unset($data['group_id']);
        }

        $category->fill($data)->save();
        $category->load('group');

        if (isset($data['name']) && $data['name'] !== $oldName) {
            app(RecordActivity::class)($budget, $request->user(), 'category.renamed',
                "Renamed category $oldName to {$category->name}", $category->uuid);
        }

        return new CategoryResource($category);
    }

    /**
     * Deleting a category with history requires a migrate_to category: its
     * transactions, splits, schedules and assignments move there, so the
     * envelope math stays consistent (money never silently disappears).
     */
    public function destroy(Request $request, Budget $budget, Category $category)
    {
        Gate::authorize('update', $budget);

        abort_if($category->internal_type !== null, 404);

        $data = $request->validate(['migrate_to' => ['sometimes', 'uuid']]);

        $hasHistory = Transaction::where('category_id', $category->id)->exists()
            || SubTransaction::where('category_id', $category->id)->exists()
            || ScheduledTransaction::where('category_id', $category->id)->exists()
            || $budget->monthlyBudgets()->where('category_id', $category->id)->where('assigned', '!=', 0)->exists();

        $target = null;
        if (isset($data['migrate_to'])) {
            $target = $budget->categories()
                ->whereNull('internal_type')
                ->where('uuid', $data['migrate_to'])
                ->first();
            if ($target === null || $target->id === $category->id) {
                throw ValidationException::withMessages(['migrate_to' => 'Choose a different category.']);
            }
        }

        if ($hasHistory && $target === null) {
            throw ValidationException::withMessages([
                'migrate_to' => 'This category has history. Pick a category to move it to.',
            ]);
        }

        DB::transaction(function () use ($budget, $category, $target): void {
            if ($target !== null) {
                Transaction::where('category_id', $category->id)->update(['category_id' => $target->id]);
                SubTransaction::where('category_id', $category->id)->update(['category_id' => $target->id]);
                ScheduledTransaction::where('category_id', $category->id)->update(['category_id' => $target->id]);

                // Merge month assignments so available balances carry over.
                foreach ($budget->monthlyBudgets()->where('category_id', $category->id)->get() as $row) {
                    $existing = $budget->monthlyBudgets()->firstOrCreate(
                        ['category_id' => $target->id, 'month' => $row->month],
                    );
                    $existing->increment('assigned', $row->assigned);
                    $row->delete();
                }
            }

            $category->target?->delete();
            $budget->payees()->where('default_category_id', $category->id)
                ->update(['default_category_id' => $target?->id]);
            $category->delete();
        });

        app(RecordActivity::class)($budget, $request->user(), 'category.deleted',
            $target === null
                ? "Deleted category {$category->name}"
                : "Deleted category {$category->name} (history moved to {$target->name})",
            $category->uuid);

        return response()->noContent();
    }

    /** Reorder categories within one group (order = array of category uuids). */
    public function reorder(Request $request, Budget $budget)
    {
        Gate::authorize('update', $budget);

        $data = $request->validate([
            'group_id' => ['required', 'uuid'],
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['uuid'],
        ]);

        $group = $this->findGroup($budget, $data['group_id']);

        DB::transaction(function () use ($budget, $group, $data): void {
            foreach ($data['order'] as $index => $uuid) {
                $budget->categories()
                    ->where('category_group_id', $group->id)
                    ->where('uuid', $uuid)
                    ->update(['sort_order' => $index]);
            }
        });

        return response()->noContent();
    }

    private function findGroup(Budget $budget, string $uuid)
    {
        $group = $budget->categoryGroups()
            ->where('internal', false)
            ->where('uuid', $uuid)
            ->first();

        if ($group === null) {
            throw ValidationException::withMessages(['group_id' => 'Unknown group.']);
        }

        return $group;
    }
}
