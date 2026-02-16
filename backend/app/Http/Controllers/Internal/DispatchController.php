<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Dispatch;
use App\Models\Occurrence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DispatchController extends Controller
{
    public function store(Request $request, Occurrence $occurrence): JsonResponse
    {
        $data = $request->validate([
            'resourceCode' => ['required', 'string', 'max:255'],
        ]);

        $dispatch = DB::transaction(function () use ($occurrence, $data) {
            $occ = Occurrence::query()->where('id', $occurrence->id)->lockForUpdate()->firstOrFail();

            $dispatch = Dispatch::create([
                'occurrence_id' => $occ->id,
                'resource_code' => $data['resourceCode'],
                'status' => Dispatch::STATUS_ASSIGNED,
            ]);

            if ($occ->status === Occurrence::STATUS_REPORTED) {
                $beforeOcc = [
                    'id' => $occ->id,
                    'external_id' => $occ->external_id,
                    'type' => $occ->type,
                    'status' => $occ->status,
                    'description' => $occ->description,
                    'reported_at' => $occ->getRawOriginal('reported_at'),
                ];

                $occ->transitionTo(Occurrence::STATUS_IN_PROGRESS);
                $occ->save();

                $afterOcc = [
                    'id' => $occ->id,
                    'external_id' => $occ->external_id,
                    'type' => $occ->type,
                    'status' => $occ->status,
                    'description' => $occ->description,
                    'reported_at' => $occ->getRawOriginal('reported_at'),
                ];

                AuditLog::create([
                    'entity_type' => 'occurrence',
                    'entity_id' => $occ->id,
                    'action' => 'occurrence.status_changed_by_dispatch',
                    'before' => $beforeOcc,
                    'after' => $afterOcc,
                    'meta' => [
                        'source' => 'operador_web',
                        'dispatchId' => $dispatch->id,
                    ],
                ]);
            }

            AuditLog::create([
                'entity_type' => 'dispatch',
                'entity_id' => $dispatch->id,
                'action' => 'dispatch.created',
                'before' => null,
                'after' => [
                    'id' => $dispatch->id,
                    'occurrence_id' => $dispatch->occurrence_id,
                    'resource_code' => $dispatch->resource_code,
                    'status' => $dispatch->status,
                ],
                'meta' => [
                    'source' => 'operador_web',
                    'occurrenceId' => $occ->id,
                ],
            ]);

            return $dispatch;
        });

        return response()->json([
            'id' => $dispatch->id,
            'occurrenceId' => $dispatch->occurrence_id,
            'resourceCode' => $dispatch->resource_code,
            'status' => $dispatch->status,
            'createdAt' => $dispatch->created_at?->toISOString(),
        ], 201);
    }
}
