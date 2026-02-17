<?php

namespace App\Jobs;

use App\Models\CommandInbox;
use App\Models\Occurrence;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessOccurrenceFinishCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $commandId)
    {
    }

    public function handle(): void
    {
        $command = CommandInbox::query()->findOrFail($this->commandId);

        if ($command->status === 'processed') {
            return;
        }

        DB::transaction(function () use ($command) {
            $cmd = CommandInbox::query()
                ->whereKey($command->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($cmd->status === 'processed') {
                return;
            }

            $occurrenceId = data_get($cmd->payload, 'occurrenceId');

            $occurrence = Occurrence::query()
                ->whereKey($occurrenceId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($occurrence->status !== Occurrence::STATUS_RESOLVED) {
                $occurrence->transitionTo(Occurrence::STATUS_RESOLVED);
                $occurrence->save();
            }

            $cmd->status = 'processed';
            $cmd->processed_at = now();
            $cmd->error = null;
            $cmd->save();
        });
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
