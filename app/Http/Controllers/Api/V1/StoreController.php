<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stores\CreateStoreRequest;
use App\Http\Requests\Stores\UpdateStoreRequest;
use App\Http\Resources\StoreResource;
use App\Http\Responses\ApiResponse;
use App\Models\Store;
use App\Services\AccessControl\StoreAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StoreController extends Controller
{
    public function __construct(private readonly StoreAccessService $storeAccessService) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Store::class);

        $query = Store::query()->with('franchiseAccount')->orderBy('name');

        $this->storeAccessService->scopeQueryToAccessibleStores($query, $request->user(), 'id');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('slug', 'like', "%{$term}%")
                    ->orWhere('city', 'like', "%{$term}%");
            });
        }

        $stores = $query->paginate(20);

        return ApiResponse::success(
            StoreResource::collection($stores)->response()->getData(true)
        );
    }

    public function store(CreateStoreRequest $request): JsonResponse
    {
        Gate::authorize('create', Store::class);

        $store = Store::create([
            ...$request->validated(),
            'franchise_account_id' => $request->user()->franchise_account_id,
        ]);

        return ApiResponse::created(new StoreResource($store->load('franchiseAccount')), 'Store created');
    }

    public function show(Store $store): JsonResponse
    {
        Gate::authorize('view', $store);

        return ApiResponse::success(new StoreResource($store->load('franchiseAccount')));
    }

    public function update(UpdateStoreRequest $request, Store $store): JsonResponse
    {
        Gate::authorize('update', $store);

        $store->update($request->validated());

        return ApiResponse::success(new StoreResource($store->fresh()->load('franchiseAccount')));
    }

    public function destroy(Store $store): JsonResponse
    {
        Gate::authorize('delete', $store);

        $store->delete();

        return ApiResponse::success(message: 'Store deleted');
    }
}
