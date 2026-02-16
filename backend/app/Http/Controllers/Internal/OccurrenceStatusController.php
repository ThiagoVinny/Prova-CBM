<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Occurrence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use DomainException;

class OccurrenceStatusController extends Controller
{
    public function update(Request $request, Occurrence $occurrence): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:reported,in_progress,resolved,cancelled'],
        ]);

        try {
            $updated = DB::transaction(function () use ($occurrence, $data) {
                $locked = Occurrence::query()
                    ->where('id', $occurrence->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $newStatus = $data['status'];

                // Idempotência: se já está no status pedido, retorna 200
                if ($locked->status === $newStatus) {
                    return $locked;
                }

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
                        'source' => 'operador_web',
                    ],
                ]);

                return $locked;
            });
        } catch (DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        }

        return response()->json([
            'id' => $updated->id,
            'externalId' => $updated->external_id,
            'type' => $updated->type,
            'status' => $updated->status,
            'updatedAt' => $updated->updated_at?->toISOString(),
        ]);
    }
}
