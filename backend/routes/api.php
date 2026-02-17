<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Integration\OccurrenceIntegrationController;
use App\Http\Controllers\Internal\DispatchController;
use App\Http\Controllers\Internal\DispatchStatusController;
use App\Http\Controllers\Internal\OccurrenceStatusController;
use App\Http\Controllers\Internal\OccurrenceStartController;
use App\Http\Controllers\Internal\OccurrenceFinishController;
use App\Http\Controllers\Internal\OccurrenceController;

Route::middleware('api.key')->group(function () {

    Route::get('occurrences', [OccurrenceController::class, 'index']);

    // Integração externa
    Route::post('integrations/occurrences', [OccurrenceIntegrationController::class, 'store']);

    // API interna
    Route::post('occurrences/{occurrence}/dispatches', [DispatchController::class, 'store']);

    Route::patch('dispatches/{dispatch}/status', [DispatchStatusController::class, 'update']);
    Route::patch('occurrences/{occurrence}/status', [OccurrenceStatusController::class, 'update']);

    Route::post('occurrences/{occurrence}/start', [OccurrenceStartController::class, 'store']);
    Route::post('occurrences/{occurrence}/resolve', [OccurrenceFinishController::class, 'store']);
});
