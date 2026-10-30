<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreServiceRequest;
use App\Http\Requests\UpdateServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class ServiceController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Service::class);

        return ServiceResource::collection(
            Service::query()->orderBy('name')->get(),
        );
    }

    public function store(StoreServiceRequest $request): JsonResponse
    {
        $service = Service::query()->create($request->validated());

        return ServiceResource::make($service)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Service $service): ServiceResource
    {
        $this->authorize('view', $service);

        return ServiceResource::make($service);
    }

    public function update(UpdateServiceRequest $request, Service $service): ServiceResource
    {
        $service->update($request->validated());

        return ServiceResource::make($service);
    }

    public function destroy(Service $service): Response
    {
        $this->authorize('delete', $service);

        if ($service->appointments()->futureConfirmed()->exists()) {
            throw ValidationException::withMessages([
                'service' => ['This service has upcoming confirmed appointments.'],
            ]);
        }

        $service->delete();

        return response()->noContent();
    }
}
