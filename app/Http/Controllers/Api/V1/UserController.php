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

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (!$request->user()->isFranchiseAdmin()) {
            return ApiResponse::forbidden('Only franchise admins can list users');
        }

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
        if (!$request->user()->isFranchiseAdmin()) {
            return ApiResponse::forbidden('Only franchise admins can create users');
        }

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

    public function show(Request $request, User $user): JsonResponse
    {
        if (!$request->user()->isFranchiseAdmin()) {
            return ApiResponse::forbidden('Only franchise admins can view user details');
        }

        if ($request->user()->franchise_account_id !== $user->franchise_account_id) {
            return ApiResponse::forbidden('Cannot view a user from another franchise');
        }

        return ApiResponse::success(new UserResource($user));
    }
}
