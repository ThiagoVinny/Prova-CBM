<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\CommandInbox;
use App\Models\Occurrence;
use DomainException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessOccurrenceStatusCommand implements ShouldQueue
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

        $occurrenceId = (string) (($command->payload ?? [])['occurrenceId'] ?? '');
        $newStatus    = (string) (($command->payload ?? [])['status'] ?? '');

        try {
            DB::transaction(function () use ($command, $occurrenceId, $newStatus) {
                $cmdLocked = CommandInbox::query()
                    ->whereKey($command->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($cmdLocked->status === 'processed') {
                    return;
                }

                $locked = Occurrence::query()
                    ->whereKey($occurrenceId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->status !== $newStatus) {
                    $before = [
                        'id' => $locked->id,
                        'external_id' => $locked->external_id,
                        'type' => $locked->type,
                        'status' => $locked->status,
                        'description' => $locked->description,
                        'reported_at' => $locked->getRawOriginal('reported_at'),
                    ];

                    $locked->transitionTo($newStatus);
                    $locked->save();

                    $after = [
                        'id' => $locked->id,
                        'external_id' => $locked->external_id,
                        'type' => $locked->type,
                        'status' => $locked->status,
                        'description' => $locked->description,
                        'reported_at' => $locked->getRawOriginal('reported_at'),
                    ];

                    AuditLog::create([
                        'entity_type' => 'occurrence',
                        'entity_id' => $locked->id,
                        'action' => 'occurrence.status_changed',
                        'before' => $before,
                        'after' => $after,
                        'meta' => [
                            'source' => $cmdLocked->source,
                            'commandId' => $cmdLocked->id,
                            'idempotencyKey' => $cmdLocked->idempotency_key,
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
