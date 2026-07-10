<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryGroupResource;
use App\Models\Budget;
use App\Models\CategoryGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CategoryGroupController extends Controller
{
    public function index(Budget $budget)
    {
        Gate::authorize('view', $budget);

        $groups = $budget->categoryGroups()
            ->where('internal', false)
            ->with(['categories' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        return CategoryGroupResource::collection($groups);
    }

    public function store(Request $request, Budget $budget)
    {
        Gate::authorize('update', $budget);

        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $group = $budget->categoryGroups()->create([
            ...$data,
            'sort_order' => ($budget->categoryGroups()->where('internal', false)->max('sort_order') ?? -1) + 1,
        ]);

        $group->load('categories');

        return (new CategoryGroupResource($group))->response()->setStatusCode(201);
    }

    public function update(Request $request, Budget $budget, CategoryGroup $categoryGroup)
    {
        Gate::authorize('update', $budget);

        abort_if($categoryGroup->internal, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'hidden' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $categoryGroup->update($data);
        $categoryGroup->load(['categories' => fn ($q) => $q->orderBy('sort_order')]);

        return new CategoryGroupResource($categoryGroup);
    }

    /** A group can only be removed once it holds no categories. */
    public function destroy(Budget $budget, CategoryGroup $categoryGroup)
    {
        Gate::authorize('update', $budget);

        abort_if($categoryGroup->internal, 404);

        if ($categoryGroup->categories()->exists()) {
            throw ValidationException::withMessages([
                'group' => 'Move or delete its categories first.',
            ]);
        }

        $categoryGroup->delete();

        return response()->noContent();
    }

    /** Reorder groups (order = array of group uuids). */
    public function reorder(Request $request, Budget $budget)
    {
        Gate::authorize('update', $budget);

        $data = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['uuid'],
        ]);

        DB::transaction(function () use ($budget, $data): void {
            foreach ($data['order'] as $index => $uuid) {
                $budget->categoryGroups()
                    ->where('internal', false)
                    ->where('uuid', $uuid)
                    ->update(['sort_order' => $index]);
            }
        });

        return response()->noContent();
    }
}
