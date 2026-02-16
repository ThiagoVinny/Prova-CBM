<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\CommandInbox;
use App\Models\Occurrence;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class ProcessOccurrenceCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $commandId)
    {
    }

    public function handle(): void
    {
        /** @var CommandInbox $command */
        $command = CommandInbox::query()->findOrFail($this->commandId);

        if ($command->status === 'processed') {
            return;
        }

        $payload = $command->payload;
        $externalId = $payload['externalId'] ?? null;

        if (!$externalId || !is_string($externalId)) {
            throw new InvalidArgumentException('payload.externalId is required');
        }

        DB::transaction(function () use ($command, $payload, $externalId) {
            $commandLocked = CommandInbox::query()
                ->where('id', $command->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($commandLocked->status === 'processed') {
                return;
            }

            $occurrence = Occurrence::query()
                ->where('external_id', $externalId)
                ->lockForUpdate()
                ->first();

            $action = '';
            $before = null;

            if (!$occurrence) {
                try {
                    $occurrence = Occurrence::create([
                        'external_id' => $externalId,
                        'type' => $payload['type'] ?? 'unknown',
                        'status' => Occurrence::STATUS_REPORTED,
                        'description' => $payload['description'] ?? null,
                        'reported_at' => $payload['reportedAt'] ?? null,
                    ]);

                    $action = 'occurrence.created';
                } catch (QueryException $e) {
                    $occurrence = Occurrence::query()
                        ->where('external_id', $externalId)
                        ->firstOrFail();

                    $action = 'occurrence.duplicate';
                }
            } else {
                $before = [
                    'id' => $occurrence->id,
                    'external_id' => $occurrence->external_id,
                    'type' => $occurrence->type,
                    'status' => $occurrence->status,
                    'description' => $occurrence->description,
                    'reported_at' => $occurrence->getRawOriginal('reported_at'),
                ];

                $occurrence->type = $payload['type'] ?? $occurrence->type;
                $occurrence->description = $payload['description'] ?? $occurrence->description;
                $occurrence->reported_at = $payload['reportedAt'] ?? $occurrence->reported_at;
                $occurrence->save();

                $action = 'occurrence.updated';
            }

            $after = [
                'id' => $occurrence->id,
                'external_id' => $occurrence->external_id,
                'type' => $occurrence->type,
                'status' => $occurrence->status,
                'description' => $occurrence->description,
                'reported_at' => $occurrence->getRawOriginal('reported_at'),
            ];

            AuditLog::create([
                'entity_type' => 'occurrence',
                'entity_id' => $occurrence->id,
                'action' => $action,
                'before' => $before,
                'after' => $after,
                'meta' => [
                    'source' => $commandLocked->source,
                    'commandId' => $commandLocked->id,
                    'idempotencyKey' => $commandLocked->idempotency_key,
                    'commandType' => $commandLocked->type,
                ],
            ]);

            $commandLocked->status = 'processed';
            $commandLocked->processed_at = now();
            $commandLocked->error = null;
            $commandLocked->save();
        });
    }

    public function failed(Throwable $e): void
    {
        CommandInbox::query()
            ->where('id', $this->commandId)
            ->update([
                'status' => 'failed',
                'processed_at' => now(),
                'error' => $e->getMessage(),
            ]);
    }
}
