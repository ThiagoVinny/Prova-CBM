<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Dispatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use DomainException;

class DispatchStatusController extends Controller
{
    public function update(Request $request, Dispatch $dispatch): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:assigned,en_route,on_site,closed'],
        ]);

        try {
            $updated = DB::transaction(function () use ($dispatch, $data) {
                $locked = Dispatch::query()
                    ->where('id', $dispatch->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $newStatus = $data['status'];

                if ($locked->status === $newStatus) {
                    return $locked;
                }

                $before = [
                    'id' => $locked->id,
                    'occurrence_id' => $locked->occurrence_id,
                    'resource_code' => $locked->resource_code,
                    'status' => $locked->status,
                ];

                $locked->transitionTo($newStatus);
                $locked->save();

                $after = [
                    'id' => $locked->id,
                    'occurrence_id' => $locked->occurrence_id,
                    'resource_code' => $locked->resource_code,
                    'status' => $locked->status,
                ];

                AuditLog::create([
                    'entity_type' => 'dispatch',
                    'entity_id' => $locked->id,
                    'action' => 'dispatch.status_changed',
                    'before' => $before,
                    'after' => $after,
                    'meta' => [
                        'source' => 'operador_web',
                        'occurrenceId' => $locked->occurrence_id,
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
            'occurrenceId' => $updated->occurrence_id,
            'resourceCode' => $updated->resource_code,
            'status' => $updated->status,
            'updatedAt' => $updated->updated_at?->toISOString(),
        ]);
    }
}
