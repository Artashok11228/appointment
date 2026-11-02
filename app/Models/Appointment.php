<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use Carbon\CarbonInterface;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable(['user_id', 'service_id', 'starts_at', 'ends_at', 'status', 'reminded_at'])]
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => AppointmentStatus::class,
            'reminded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @param  Builder<Appointment>  $query
     * @return Builder<Appointment>
     */
    public function scopeOverlapping(
        Builder $query,
        int $serviceId,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?int $exceptId = null,
    ): Builder {
        return $query
            ->where('service_id', $serviceId)
            ->where('status', '!=', AppointmentStatus::Cancelled)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->when($exceptId !== null, fn (Builder $query): Builder => $query->whereKeyNot($exceptId));
    }

    /**
     * @param  Builder<Appointment>  $query
     * @return Builder<Appointment>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === Role::Admin) {
            return $query;
        }

        return $query->whereBelongsTo($user);
    }

    public function scopeFiltered(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                $filters['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('status', $status),
            )
            ->when(
                $filters['service_id'] ?? null,
                fn (Builder $query, int|string $serviceId): Builder => $query->where('service_id', $serviceId),
            )
            ->when(
                $filters['from'] ?? null,
                fn (Builder $query, string $from): Builder => $query->where('starts_at', '>=', Carbon::parse($from)->startOfDay()),
            )
            ->when(
                $filters['to'] ?? null,
                fn (Builder $query, string $to): Builder => $query->where('starts_at', '<=', Carbon::parse($to)->endOfDay()),
            );
    }

    public function scopeFutureConfirmed(Builder $query): Builder
    {
        return $query
            ->where('status', AppointmentStatus::Confirmed)
            ->where('starts_at', '>', now());
    }
}
