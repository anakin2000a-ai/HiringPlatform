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
        $request->validate([
            'per_page'           => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search'             => ['sometimes', 'string', 'max:255'],
            'franchise_account_id' => ['sometimes', 'integer'],
        ]);

        $query = Store::query()->with('franchiseAccount');

        $this->storeAccessService->scopeQueryToAccessibleStores($query, $request->user(), 'id');

        if ($request->filled('search')) {
            $query->where('store_name', 'like', '%' . $request->input('search') . '%');
        }

        if ($request->filled('franchise_account_id')) {
            $query->where('franchise_account_id', $request->integer('franchise_account_id'));
        }

        $query->orderBy('store_name')->orderBy('id');

        $stores = $query->paginate($request->integer('per_page', 20));

        return ApiResponse::success(
            StoreResource::collection($stores)->response()->getData(true)
        );
    }

    public function store(CreateStoreRequest $request): JsonResponse
    {
        $franchiseId = $this->storeAccessService->getFranchiseAccountIdForAdmin($request->user());

        if ($franchiseId === null) {
            return ApiResponse::forbidden('Only franchise admins can create stores');
        }

        $store = Store::create([
            ...$request->validated(),
            'franchise_account_id' => $franchiseId,
        ]);

        return ApiResponse::created(
            new StoreResource($store->load('franchiseAccount')),
            'Store created'
        );
    }

    public function show(Store $store): JsonResponse
    {
        return ApiResponse::success(new StoreResource($store->load('franchiseAccount')));
    }

    public function update(UpdateStoreRequest $request, Store $store): JsonResponse
    {
        $role = $this->storeAccessService->getUserRoleAtStore($request->user(), $store);

        if ($role !== 'franchise_admin') {
            return ApiResponse::forbidden('Only franchise admins can update stores');
        }

        $store->update($request->validated());

        return ApiResponse::success(new StoreResource($store->fresh()->load('franchiseAccount')));
    }

    public function destroy(Request $request, Store $store): JsonResponse
    {
        $role = $this->storeAccessService->getUserRoleAtStore($request->user(), $store);

        if ($role !== 'franchise_admin') {
            return ApiResponse::forbidden('Only franchise admins can delete stores');
        }

        $store->delete();

        return ApiResponse::success(message: 'Store deleted');
    }
}
