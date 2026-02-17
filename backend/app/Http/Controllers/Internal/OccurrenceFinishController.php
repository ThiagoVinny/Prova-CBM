<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessOccurrenceFinishCommand;
use App\Models\CommandInbox;
use App\Models\Occurrence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OccurrenceFinishController extends Controller
{
    public function store(Request $request, Occurrence $occurrence): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (!$idempotencyKey) {
            return response()->json(['message' => 'Missing Idempotency-Key header'], 422);
        }

        $existing = CommandInbox::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('type', 'occurrence.finish')
            ->where('payload->occurrenceId', $occurrence->id)
            ->first();

        if ($existing) {
            return response()->json(['commandId' => $existing->id, 'status' => 'accepted'], 202);
        }

        $cmd = DB::transaction(function () use ($idempotencyKey, $occurrence) {
            return CommandInbox::query()->create([
                'idempotency_key' => $idempotencyKey,
                'source'          => 'operador_web',
                'type'            => 'occurrence.finish',
                'payload'         => ['occurrenceId' => $occurrence->id],
                'status'          => 'pending',
            ]);
        });

        ProcessOccurrenceFinishCommand::dispatch($cmd->id);

        return response()->json(['commandId' => $cmd->id, 'status' => 'accepted'], 202);
    }
}
