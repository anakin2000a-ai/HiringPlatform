<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        $dbStatus = 'ok';

        try {
            DB::connection()->getPdo();
        } catch (\Throwable) {
            $dbStatus = 'unavailable';
        }

        return ApiResponse::success([
            'status' => 'ok',
            'service' => 'hiring-workflow-api',
            'version' => 'v1',
            'database' => $dbStatus,
        ]);
    }
}
