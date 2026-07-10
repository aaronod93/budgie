<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Budget;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    public function store(Request $request, Budget $budget)
    {
        Gate::authorize('update', $budget);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'group_id' => ['required', 'uuid'],
        ]);

        $group = $budget->categoryGroups()
            ->where('internal', false)
            ->where('uuid', $data['group_id'])
            ->first();

        if ($group === null) {
            throw ValidationException::withMessages(['group_id' => 'Unknown group.']);
        }

        $category = $budget->categories()->create([
            'name' => $data['name'],
            'category_group_id' => $group->id,
            'sort_order' => ($group->categories()->max('sort_order') ?? -1) + 1,
        ]);

        $category->load('group');

        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    public function update(Request $request, Budget $budget, Category $category)
    {
        Gate::authorize('update', $budget);

        abort_if($category->internal_type !== null, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'hidden' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $category->update($data);
        $category->load('group');

        return new CategoryResource($category);
    }

    public function destroy(Budget $budget, Category $category)
    {
        Gate::authorize('update', $budget);

        abort_if($category->internal_type !== null, 404);

        $category->delete();

        return response()->noContent();
    }
}
