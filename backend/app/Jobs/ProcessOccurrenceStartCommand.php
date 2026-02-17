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

class ProcessOccurrenceStartCommand implements ShouldQueue
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

        $occurrenceId = (string) (($command->payload ?? [])['occurrenceId'] ?? '');

        try {
            DB::transaction(function () use ($command, $occurrenceId) {
                $cmdLocked = CommandInbox::query()
                    ->whereKey($command->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($cmdLocked->status === 'processed') {
                    return;
                }

                $occ = Occurrence::query()
                    ->whereKey($occurrenceId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($occ->status !== Occurrence::STATUS_IN_PROGRESS) {
                    $before = [
                        'id' => $occ->id,
                        'external_id' => $occ->external_id,
                        'type' => $occ->type,
                        'status' => $occ->status,
                        'description' => $occ->description,
                        'reported_at' => $occ->getRawOriginal('reported_at'),
                    ];

                    $occ->transitionTo(Occurrence::STATUS_IN_PROGRESS);
                    $occ->save();

                    $after = [
                        'id' => $occ->id,
                        'external_id' => $occ->external_id,
                        'type' => $occ->type,
                        'status' => $occ->status,
                        'description' => $occ->description,
                        'reported_at' => $occ->getRawOriginal('reported_at'),
                    ];

                    AuditLog::create([
                        'entity_type' => 'occurrence',
                        'entity_id' => $occ->id,
                        'action' => 'occurrence.started',
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
