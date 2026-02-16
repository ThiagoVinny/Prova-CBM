<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Occurrence extends Model
{
    public function canTransitionTo(string $nextStatus): bool
    {
        $current = (string) $this->status;

        return in_array($nextStatus, self::TRANSITIONS[$current] ?? [], true);
    }

    public function transitionTo(string $nextStatus): void
    {
        if (!in_array($nextStatus, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid status: {$nextStatus}");
        }

        if (!$this->canTransitionTo($nextStatus)) {
            throw new \DomainException("Invalid transition: {$this->status} -> {$nextStatus}");
        }

        $this->status = $nextStatus;
    }
    public const STATUS_REPORTED    = 'reported';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RESOLVED    = 'resolved';
    public const STATUS_CANCELLED   = 'cancelled';

    public const STATUSES = [
        self::STATUS_REPORTED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_RESOLVED,
        self::STATUS_CANCELLED,
    ];

    private const TRANSITIONS = [
        self::STATUS_REPORTED => [
            self::STATUS_IN_PROGRESS,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_IN_PROGRESS => [
            self::STATUS_RESOLVED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_RESOLVED => [],
        self::STATUS_CANCELLED => [],
    ];
    use HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'external_id',
        'type',
        'status',
        'description',
        'reported_at',
    ];

    protected $casts = [
        'reported_at' => 'datetime',
    ];

    public function dispatches(): HasMany
    {
        return $this->hasMany(Dispatch::class);
    }
}
