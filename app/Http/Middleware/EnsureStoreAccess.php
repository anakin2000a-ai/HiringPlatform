<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Services\AccessControl\StoreAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStoreAccess
{
    public function __construct(private readonly StoreAccessService $storeAccessService) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Store|null $store */
        $store = $request->route('store');

        if ($store === null) {
            return $next($request);
        }

        $user = $request->user();

        if (!$this->storeAccessService->canAccessStore($user, $store)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
