<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

use App\Http\Requests\TestItem\StoreTestItemRequest;
use App\Http\Requests\TestItem\UpdateTestItemRequest;
use App\Http\Resources\TestItemResource;
use App\Models\TestItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TestItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TestItem::class);

        $query = TestItem::with('user')
            ->search($request->query('search'))
            ->status($request->query('status'))
            ->latest();

        if (! $request->user()->isAdminOrAbove()) {
            $query->where('user_id', $request->user()->id);
        }

        $items = $query->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => TestItemResource::collection($items),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function store(StoreTestItemRequest $request): JsonResponse
    {
        $this->authorize('create', TestItem::class);

        $item = TestItem::create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Test item created successfully.',
            'data' => new TestItemResource($item->load('user')),
        ], 201);
    }

    public function show(TestItem $testItem): JsonResponse
    {
        $this->authorize('view', $testItem);

        return response()->json([
            'success' => true,
            'data' => new TestItemResource($testItem->load('user')),
        ]);
    }

    public function update(UpdateTestItemRequest $request, TestItem $testItem): JsonResponse
    {
        $this->authorize('update', $testItem);

        $testItem->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Test item updated successfully.',
            'data' => new TestItemResource($testItem->fresh()->load('user')),
        ]);
    }

    public function destroy(TestItem $testItem): JsonResponse
    {
        $this->authorize('delete', $testItem);

        $testItem->delete();

        return response()->json([
            'success' => true,
            'message' => 'Test item deleted successfully.',
        ]);
    }
}
