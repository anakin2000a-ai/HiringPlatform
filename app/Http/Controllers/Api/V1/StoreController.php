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

class StoreController extends Controller
{
    public function __construct(private readonly StoreAccessService $storeAccessService) {}

    public function index(Request $request): JsonResponse
    {
        $query = Store::query()->with('franchiseAccount')->orderBy('store_name');

        $this->storeAccessService->scopeQueryToAccessibleStores($query, $request->user(), 'id');

        $stores = $query->paginate(20);

        return ApiResponse::success(
            StoreResource::collection($stores)->response()->getData(true)
        );
    }

    public function store(CreateStoreRequest $request): JsonResponse
    {
        if (!$request->user()->isFranchiseAdmin()) {
            return ApiResponse::forbidden('Only franchise admins can create stores');
        }

        $store = Store::create([
            ...$request->validated(),
            'franchise_account_id' => $request->user()->franchise_account_id,
        ]);

        return ApiResponse::created(
            new StoreResource($store->load('franchiseAccount')),
            'Store created'
        );
    }

    public function show(Store $store): JsonResponse
    {
        // Access already verified by EnsureStoreAccess middleware
        return ApiResponse::success(new StoreResource($store->load('franchiseAccount')));
    }

    public function update(UpdateStoreRequest $request, Store $store): JsonResponse
    {
        // Access verified by middleware; write operations require franchise_admin
        if (!$request->user()->isFranchiseAdmin()) {
            return ApiResponse::forbidden('Only franchise admins can update stores');
        }

        if ($request->user()->franchise_account_id !== $store->franchise_account_id) {
            return ApiResponse::forbidden('Cannot update a store from another franchise');
        }

        $store->update($request->validated());

        return ApiResponse::success(new StoreResource($store->fresh()->load('franchiseAccount')));
    }

    public function destroy(Request $request, Store $store): JsonResponse
    {
        // Access verified by middleware; write operations require franchise_admin
        if (!$request->user()->isFranchiseAdmin()) {
            return ApiResponse::forbidden('Only franchise admins can delete stores');
        }

        if ($request->user()->franchise_account_id !== $store->franchise_account_id) {
            return ApiResponse::forbidden('Cannot delete a store from another franchise');
        }

        $store->delete();

        return ApiResponse::success(message: 'Store deleted');
    }
}
