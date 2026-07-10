<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryGroupResource;
use App\Models\Budget;
use App\Models\CategoryGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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

    public function destroy(Budget $budget, CategoryGroup $categoryGroup)
    {
        Gate::authorize('update', $budget);

        abort_if($categoryGroup->internal, 404);

        $categoryGroup->categories()->delete();
        $categoryGroup->delete();

        return response()->noContent();
    }
}
