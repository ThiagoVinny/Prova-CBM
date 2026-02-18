<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessOccurrenceStartCommand;
use App\Models\CommandInbox;
use App\Models\Occurrence;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OccurrenceStartController extends Controller
{
    public function store(Request $request, Occurrence $occurrence): JsonResponse
    {
        $idempotencyKey = (string) $request->header('Idempotency-Key');

        if ($idempotencyKey === '') {
            return response()->json(['message' => 'Idempotency-Key ausente'], 422);
        }

        $type = 'occurrence.start';
        $payload = ['occurrenceId' => $occurrence->id];

        $existing = CommandInbox::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('type', $type)
            ->first();

        if ($existing) {
            $existingOccId = (string) (($existing->payload ?? [])['occurrenceId'] ?? '');

            if ($existingOccId !== $occurrence->id) {
                return response()->json([
                    'message' => 'Idempotency-Key já utilizado para uma ocorrência diferente.',
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
                $existingOccId = (string) (($cmd->payload ?? [])['occurrenceId'] ?? '');
                if ($existingOccId !== $occurrence->id) {
                    return response()->json([
                        'message' => 'Idempotency-Key já utilizado para uma ocorrência diferente.',
                        'commandId' => $cmd->id,
                    ], 409);
                }

                return response()->json(['commandId' => $cmd->id, 'status' => 'accepted'], 202);
            }

            throw $e;
        }

        ProcessOccurrenceStartCommand::dispatch($cmd->id);

        return response()->json(['commandId' => $cmd->id, 'status' => 'accepted'], 202);
    }
}
