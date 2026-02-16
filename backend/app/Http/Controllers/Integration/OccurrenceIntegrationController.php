<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreIntegrationOccurrenceRequest;
use App\Jobs\ProcessOccurrenceCommand;
use App\Models\CommandInbox;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

class OccurrenceIntegrationController extends Controller
{
    public function store(StoreIntegrationOccurrenceRequest $request): JsonResponse
    {
        $idempotencyKey = (string) $request->header('Idempotency-Key');

        if ($idempotencyKey === '') {
            return response()->json([
                'message' => 'Missing Idempotency-Key header',
            ], 400);
        }

        $type = 'occurrence.created';

        $existing = CommandInbox::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('type', $type)
            ->first();

        if ($existing) {
            return response()->json([
                'commandId' => $existing->id,
                'status' => 'accepted',
            ], 202);
        }

        try {
            $command = CommandInbox::create([
                'idempotency_key' => $idempotencyKey,
                'source' => 'sistema_externo',
                'type' => $type,
                'payload' => $request->validated(),
                'status' => 'pending',
            ]);
        } catch (QueryException $e) {
            $command = CommandInbox::query()
                ->where('idempotency_key', $idempotencyKey)
                ->where('type', $type)
                ->first();

            if ($command) {
                return response()->json([
                    'commandId' => $command->id,
                    'status' => 'accepted',
                ], 202);
            }

            throw $e;
        }

        ProcessOccurrenceCommand::dispatch($command->id);

        return response()->json([
            'commandId' => $command->id,
            'status' => 'accepted',
        ], 202);
    }
}
