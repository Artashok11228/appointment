<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildAdminStats;
use App\Enums\Role;
use App\Http\Resources\AdminStatsResource;
use Illuminate\Http\Request;

class AdminStatsController extends Controller
{
    public function __invoke(Request $request, BuildAdminStats $stats): AdminStatsResource
    {
        abort_unless($request->user()->role === Role::Admin, 403);

        return AdminStatsResource::make($stats->handle());
    }
}
