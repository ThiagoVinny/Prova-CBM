<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\CommandInbox;
use App\Models\Dispatch;
use DomainException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessDispatchStatusCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $commandId) {}

    public function handle(): void
    {
        /** @var CommandInbox $command */
        $command = CommandInbox::query()->findOrFail($this->commandId);

        if ($command->status === 'processed') {
            return;
        }

        $dispatchId = (string) (($command->payload ?? [])['dispatchId'] ?? '');
        $newStatus  = (string) (($command->payload ?? [])['status'] ?? '');

        try {
            DB::transaction(function () use ($command, $dispatchId, $newStatus) {
                $cmdLocked = CommandInbox::query()
                    ->whereKey($command->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($cmdLocked->status === 'processed') {
                    return;
                }

                $locked = Dispatch::query()
                    ->whereKey($dispatchId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->status !== $newStatus) {
                    $before = [
                        'id' => $locked->id,
                        'occurrence_id' => $locked->occurrence_id,
                        'resource_code' => $locked->resource_code,
                        'status' => $locked->status,
                    ];

                    $locked->transitionTo($newStatus);
                    $locked->save();

                    $after = [
                        'id' => $locked->id,
                        'occurrence_id' => $locked->occurrence_id,
                        'resource_code' => $locked->resource_code,
                        'status' => $locked->status,
                    ];

                    AuditLog::create([
                        'entity_type' => 'dispatch',
                        'entity_id' => $locked->id,
                        'action' => 'dispatch.status_changed',
                        'before' => $before,
                        'after' => $after,
                        'meta' => [
                            'source' => $cmdLocked->source,
                            'commandId' => $cmdLocked->id,
                            'idempotencyKey' => $cmdLocked->idempotency_key,
                            'occurrenceId' => $locked->occurrence_id,
                        ],
                    ]);
                }

                $cmdLocked->status = 'processed';
                $cmdLocked->processed_at = now();
                $cmdLocked->error = null;
                $cmdLocked->save();
            });
        } catch (DomainException $e) {
            CommandInbox::query()
                ->whereKey($command->id)
                ->update([
                    'status' => 'failed',
                    'processed_at' => now(),
                    'error' => $e->getMessage(),
                ]);

            return;
        }
    }

    public function failed(Throwable $e): void
    {
        CommandInbox::query()
            ->whereKey($this->commandId)
            ->update([
                'status' => 'failed',
                'processed_at' => now(),
                'error' => $e->getMessage(),
            ]);
    }
}
