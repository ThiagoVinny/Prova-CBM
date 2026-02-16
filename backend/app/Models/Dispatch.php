<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dispatch extends Model
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

    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_EN_ROUTE = 'en_route';
    public const STATUS_ON_SITE  = 'on_site';
    public const STATUS_CLOSED   = 'closed';

    public const STATUSES = [
        self::STATUS_ASSIGNED,
        self::STATUS_EN_ROUTE,
        self::STATUS_ON_SITE,
        self::STATUS_CLOSED,
    ];

    private const TRANSITIONS = [
        self::STATUS_ASSIGNED => [self::STATUS_EN_ROUTE],
        self::STATUS_EN_ROUTE => [self::STATUS_ON_SITE],
        self::STATUS_ON_SITE  => [self::STATUS_CLOSED],
        self::STATUS_CLOSED   => [],
    ];
    use HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'occurrence_id',
        'resource_code',
        'status',
    ];

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(Occurrence::class);
    }
}
