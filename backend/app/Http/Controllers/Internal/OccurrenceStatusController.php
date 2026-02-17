<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessOccurrenceStatusCommand;
use App\Models\CommandInbox;
use App\Models\Occurrence;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OccurrenceStatusController extends Controller
{
    public function update(Request $request, Occurrence $occurrence): JsonResponse
    {
        $idempotencyKey = (string) $request->header('Idempotency-Key');

        if ($idempotencyKey === '') {
            return response()->json(['message' => 'Missing Idempotency-Key header'], 422);
        }

        $data = $request->validate([
            'status' => ['required', 'string', 'in:reported,in_progress,resolved,cancelled'],
        ]);

        $type = 'occurrence.status';
        $payload = [
            'occurrenceId' => $occurrence->id,
            'status' => $data['status'],
        ];

        $existing = CommandInbox::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('type', $type)
            ->first();

        if ($existing) {
            $existingOccId = (string) (($existing->payload ?? [])['occurrenceId'] ?? '');
            $existingStatus = (string) (($existing->payload ?? [])['status'] ?? '');

            if ($existingOccId !== $occurrence->id || $existingStatus !== $data['status']) {
                return response()->json([
                    'message' => 'Idempotency-Key already used for a different occurrence status payload.',
                    'commandId' => $existing->id,
                ], 409);
            }

            return response()->json(['commandId' => $existing->id, 'status' => 'accepted'], 202);
        }

        try {
            $cmd = CommandInbox::query()->create([
                'idempotency_key' => $idempotencyKey,
                'source'          => 'operador_web',
                'type'            => $type,
                'payload'         => $payload,
                'status'          => 'pending',
            ]);
        } catch (QueryException $e) {
            $cmd = CommandInbox::query()
                ->where('idempotency_key', $idempotencyKey)
                ->where('type', $type)
                ->first();

            if ($cmd) {
                return response()->json(['commandId' => $cmd->id, 'status' => 'accepted'], 202);
            }

            throw $e;
        }

        ProcessOccurrenceStatusCommand::dispatch($cmd->id);

        return response()->json(['commandId' => $cmd->id, 'status' => 'accepted'], 202);
    }
}
