<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildAdminStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class AdminStatsController extends Controller
{
    public function __invoke(BuildAdminStats $stats): JsonResponse
    {
        Gate::authorize('view-admin-stats');

        return response()->json(['data' => $stats->handle()]);
    }
}
