<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\CommandInbox;
use App\Models\Occurrence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OccurrenceStartController extends Controller
{
    public function store(Request $request, Occurrence $occurrence): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (!$idempotencyKey) {
            return response()->json(['message' => 'Missing Idempotency-Key header'], 422);
        }

        $existing = CommandInbox::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('type', 'occurrence.start')
            ->where('payload->occurrenceId', $occurrence->id)
            ->first();

        if ($existing) {
            return response()->json(['commandId' => $existing->id, 'status' => 'accepted'], 202);
        }

        $cmd = DB::transaction(function () use ($occurrence, $idempotencyKey) {
            $cmd = CommandInbox::query()->create([
                'idempotency_key' => $idempotencyKey,
                'source'          => 'operador_web',
                'type'            => 'occurrence.start',
                'payload'         => ['occurrenceId' => $occurrence->id],
                'status'          => 'pending',
            ]);

            $locked = Occurrence::query()
                ->whereKey($occurrence->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== Occurrence::STATUS_IN_PROGRESS) {
                $locked->transitionTo(Occurrence::STATUS_IN_PROGRESS);
                $locked->save();
            }

            $cmd->status = 'processed';
            $cmd->processed_at = now();
            $cmd->error = null;
            $cmd->save();

            return $cmd;
        });

        return response()->json(['commandId' => $cmd->id, 'status' => 'accepted'], 202);
    }
}
