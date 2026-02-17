<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Occurrence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OccurrenceController extends Controller
{

    /**
     * GET /api/occurrences?status=&type=
     */
    public function show(\App\Models\Occurrence $occurrence): \Illuminate\Http\JsonResponse
    {
        $occurrence->load(['dispatches' => function ($q) {
            $q->orderBy('created_at', 'asc');
        }]);

        return response()->json([
            'id' => $occurrence->id,
            'externalId' => $occurrence->external_id,
            'type' => $occurrence->type,
            'status' => $occurrence->status,
            'description' => $occurrence->description,
            'reportedAt' => optional($occurrence->reported_at)->toIso8601String(),
            'dispatches' => $occurrence->dispatches->map(fn($d) => [
                'id' => $d->id,
                'resourceCode' => $d->resource_code,
                'status' => $d->status,
                'createdAt' => optional($d->created_at)->toIso8601String(),
                'updatedAt' => optional($d->updated_at)->toIso8601String(),
            ]),
        ]);
    }
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $type   = $request->query('type');

        $perPage = (int) $request->query('perPage', 15);
        $perPage = $perPage > 0 ? min($perPage, 100) : 15;

        $query = Occurrence::query()
            ->with(['dispatches' => fn ($q) => $q->orderBy('created_at')])
            ->orderByDesc('reported_at');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        if (is_string($type) && $type !== '') {
            $query->where('type', $type);
        }

        $paginator = $query->paginate($perPage)->appends($request->query());

        $data = $paginator->getCollection()
            ->map(fn (Occurrence $occ) => [
                'id'          => $occ->id,
                'externalId'  => $occ->external_id,
                'type'        => $occ->type,
                'status'      => $occ->status,
                'description' => $occ->description,
                'reportedAt'  => optional($occ->reported_at)?->toIso8601String(),
                'dispatches'  => $occ->dispatches->map(fn ($d) => [
                    'id'           => $d->id,
                    'occurrenceId' => $d->occurrence_id,
                    'resourceCode' => $d->resource_code,
                    'status'       => $d->status,
                    'createdAt'    => optional($d->created_at)?->toIso8601String(),
                ])->values(),
            ])->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage'     => $paginator->perPage(),
                'total'       => $paginator->total(),
                'lastPage'    => $paginator->lastPage(),
                'from'        => $paginator->firstItem(),
                'to'          => $paginator->lastItem(),
            ],
            'links' => [
                'next' => $paginator->nextPageUrl(),
                'prev' => $paginator->previousPageUrl(),
            ],
        ]);
    }
}
