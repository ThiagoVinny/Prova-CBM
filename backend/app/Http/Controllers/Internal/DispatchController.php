<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDispatchCreateCommand;
use App\Models\CommandInbox;
use App\Models\Occurrence;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DispatchController extends Controller
{
    public function store(Request $request, Occurrence $occurrence): JsonResponse
    {
        $idempotencyKey = (string) $request->header('Idempotency-Key');

        if ($idempotencyKey === '') {
            return response()->json(['message' => 'Cabeçalho da Idempotency-Key ausente'], 422);
        }

        $data = $request->validate([
            'resourceCode' => ['required', 'string', 'max:255'],
        ]);

        $type = 'dispatch.create';
        $payload = [
            'occurrenceId' => $occurrence->id,
            'resourceCode' => $data['resourceCode'],
        ];

        $existing = CommandInbox::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('type', $type)
            ->first();

        if ($existing) {
            $existingOccId = (string) (($existing->payload ?? [])['occurrenceId'] ?? '');
            $existingRes   = (string) (($existing->payload ?? [])['resourceCode'] ?? '');

            if ($existingOccId !== $occurrence->id || $existingRes !== $data['resourceCode']) {
                return response()->json([
                    'message' => 'A Idempotency-Key já foi usada para uma carga útil de despacho diferente.',
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

        ProcessDispatchCreateCommand::dispatch($cmd->id);

        return response()->json(['commandId' => $cmd->id, 'status' => 'accepted'], 202);
    }
}
