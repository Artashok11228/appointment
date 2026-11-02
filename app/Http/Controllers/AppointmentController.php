<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CancelAppointment;
use App\Actions\CreateAppointment;
use App\Actions\RescheduleAppointment;
use App\Http\Requests\IndexAppointmentRequest;
use App\Http\Requests\RescheduleAppointmentRequest;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class AppointmentController extends Controller
{
    public function index(IndexAppointmentRequest $request): AnonymousResourceCollection
    {
        $appointments = Appointment::query()
            ->with(['service', 'user'])
            ->visibleTo($request->user())
            ->filtered($request->validated())
            ->orderBy('starts_at')
            ->paginate((int) config('booking.appointments_per_page'))
            ->withQueryString();

        return AppointmentResource::collection($appointments);
    }

    public function show(Appointment $appointment): AppointmentResource
    {
        $this->authorize('view', $appointment);

        return AppointmentResource::make($appointment->load(['service', 'user']));
    }

    public function store(StoreAppointmentRequest $request, CreateAppointment $create): JsonResponse
    {
        $appointment = $create->handle(
            $request->user(),
            Service::query()->findOrFail($request->validated('service_id')),
            Carbon::parse($request->validated('starts_at')),
        );

        return AppointmentResource::make($appointment->load(['service', 'user']))
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        RescheduleAppointmentRequest $request,
        Appointment $appointment,
        RescheduleAppointment $reschedule,
    ): AppointmentResource {
        $updated = $reschedule->handle(
            $appointment,
            Carbon::parse($request->validated('starts_at')),
        );

        return AppointmentResource::make($updated->load(['service', 'user']));
    }

    public function cancel(Appointment $appointment, CancelAppointment $cancel): AppointmentResource
    {
        $this->authorize('cancel', $appointment);

        return AppointmentResource::make(
            $cancel->handle($appointment)->load(['service', 'user']),
        );
    }
}
