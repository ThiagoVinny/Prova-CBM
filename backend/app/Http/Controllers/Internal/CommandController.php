<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\CommandInbox;
use Illuminate\Http\JsonResponse;

class CommandController extends Controller
{
    public function show(string $commandId): JsonResponse
    {
        $command = CommandInbox::query()->find($commandId);

        if (!$command) {
            return response()->json(['message' => 'Command not found'], 404);
        }

        return response()->json([
            'commandId'    => $command->id,
            'status'       => $command->status,
            'type'         => $command->type,
            'source'       => $command->source,
            'processedAt'  => optional($command->processed_at)->toIso8601String(),
            'error'        => $command->error,
        ]);
    }
}
