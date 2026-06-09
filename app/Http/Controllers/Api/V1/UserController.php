<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\CreateUserRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $users = User::query()
            ->where('franchise_account_id', $request->user()->franchise_account_id)
            ->orderBy('name')
            ->paginate(20);

        return ApiResponse::success(
            UserResource::collection($users)->response()->getData(true)
        );
    }

    public function store(CreateUserRequest $request): JsonResponse
    {
        Gate::authorize('create', User::class);

        $user = DB::transaction(function () use ($request): User {
            $user = User::create([
                'franchise_account_id' => $request->user()->franchise_account_id,
                'name' => $request->name,
                'email' => $request->email,
                'password' => $request->password,
                'role' => $request->role,
                'access_scope' => $request->access_scope,
            ]);

            if ($request->filled('store_ids')) {
                // Validate all stores belong to the same franchise before assigning
                $franchiseStoreIds = Store::where('franchise_account_id', $request->user()->franchise_account_id)
                    ->whereIn('id', $request->store_ids)
                    ->pluck('id');

                foreach ($franchiseStoreIds as $storeId) {
                    UserStoreAccess::create(['user_id' => $user->id, 'store_id' => $storeId]);
                }
            }

            return $user;
        });

        return ApiResponse::created(new UserResource($user), 'User created');
    }

    public function show(User $user): JsonResponse
    {
        Gate::authorize('update', $user); // reuse update ability for viewing a specific user

        return ApiResponse::success(new UserResource($user));
    }
}
