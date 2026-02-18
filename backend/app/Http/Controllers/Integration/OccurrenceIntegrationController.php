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
                'message' => 'Cabeçalho da chave de idempotência ausente',
            ], 400);
        }

        $type = 'occurrence.created';
        $payload = $request->validated();
        $existing = CommandInbox::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('type', $type)
            ->first();

        if ($existing) {
            if ($this->payloadSignature((array) $existing->payload) !== $this->payloadSignature($payload)) {
                return response()->json([
                    'message' => 'A chave de idempotência já foi usada com uma carga útil diferente.',
                    'commandId' => $existing->id,
                ], 409);
            }

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
                'payload' => $payload,
                'status' => 'pending',
            ]);
        } catch (QueryException $e) {
            // corrida (dois requests ao mesmo tempo)
            $command = CommandInbox::query()
                ->where('idempotency_key', $idempotencyKey)
                ->where('type', $type)
                ->first();

            if ($command) {
                if ($this->payloadSignature((array) $command->payload) !== $this->payloadSignature($payload)) {
                    return response()->json([
                        'message' => 'A chave de idempotência já foi usada com uma carga útil diferente.',
                        'commandId' => $command->id,
                    ], 409);
                }

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

    private function payloadSignature(array $payload): string
    {
        $normalized = $this->normalize($payload);

        return hash('sha256', json_encode(
            $normalized,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    private function normalize(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = $this->normalize($v);
            }
        }

        ksort($data);

        return $data;
    }
}
