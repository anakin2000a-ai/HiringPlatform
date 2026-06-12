<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\CreateUserRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use App\Services\AccessControl\StoreAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function __construct(private readonly StoreAccessService $storeAccessService) {}

    public function index(Request $request): JsonResponse
    {
        $franchiseId = $this->storeAccessService->getFranchiseAccountIdForAdmin($request->user());

        if ($franchiseId === null) {
            return ApiResponse::forbidden('Only franchise admins can list users');
        }

        $request->validate([
            'per_page'     => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search'       => ['sometimes', 'string', 'max:255'],
            'store_id'     => ['sometimes', 'integer'],
            'role'         => ['sometimes', 'string'],
            'access_scope' => ['sometimes', 'string'],
            'status'       => ['sometimes', 'string'],
        ]);

        $franchiseStoreIds = Store::where('franchise_account_id', $franchiseId)->pluck('id');

        $query = User::query()
            ->whereHas('storeAccesses', function ($q) use ($franchiseStoreIds, $request) {
                $q->whereIn('store_id', $franchiseStoreIds);
                if ($request->filled('store_id')) {
                    $q->where('store_id', $request->integer('store_id'));
                }
                if ($request->filled('role')) {
                    $q->where('role', $request->input('role'));
                }
                if ($request->filled('access_scope')) {
                    $q->where('access_scope', $request->input('access_scope'));
                }
                if ($request->filled('status')) {
                    $q->where('status', $request->input('status'));
                }
            })
            ->orderBy('name')
            ->orderBy('id');

        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                  ->orWhere('email', 'like', $term);
            });
        }

        $users = $query->paginate($request->integer('per_page', 20));

        return ApiResponse::success(
            UserResource::collection($users)->response()->getData(true)
        );
    }

    public function store(CreateUserRequest $request): JsonResponse
    {
        $franchiseId = $this->storeAccessService->getFranchiseAccountIdForAdmin($request->user());

        if ($franchiseId === null) {
            return ApiResponse::forbidden('Only franchise admins can create users');
        }

        $user = DB::transaction(function () use ($request, $franchiseId): User {
            $user = User::create([
                'name'     => $request->name,
                'email'    => $request->email,
                'password' => $request->password,
            ]);

            if ($request->filled('store_ids')) {
                $franchiseStoreIds = Store::where('franchise_account_id', $franchiseId)
                    ->whereIn('id', $request->store_ids)
                    ->pluck('id');

                $role  = $request->role ?? 'viewer';
                $scope = $request->access_scope ?? 'store';

                foreach ($franchiseStoreIds as $storeId) {
                    UserStoreAccess::create([
                        'user_id'      => $user->id,
                        'store_id'     => $storeId,
                        'role'         => $role,
                        'access_scope' => $scope,
                        'status'       => 'active',
                    ]);
                }
            }

            return $user;
        });

        return ApiResponse::created(new UserResource($user), 'User created');
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $franchiseId = $this->storeAccessService->getFranchiseAccountIdForAdmin($request->user());

        if ($franchiseId === null) {
            return ApiResponse::forbidden('Only franchise admins can view user details');
        }

        $storeIds = Store::where('franchise_account_id', $franchiseId)->pluck('id');

        $userInFranchise = UserStoreAccess::where('user_id', $user->id)
            ->whereIn('store_id', $storeIds)
            ->exists();

        if (! $userInFranchise) {
            return ApiResponse::forbidden('Cannot view a user from another franchise');
        }

        return ApiResponse::success(new UserResource($user));
    }
}
