<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Integration\OccurrenceIntegrationController;
use App\Http\Controllers\Internal\DispatchController;
use App\Http\Controllers\Internal\DispatchStatusController;
use App\Http\Controllers\Internal\OccurrenceStatusController;
use App\Http\Controllers\Internal\OccurrenceStartController;
use App\Http\Controllers\Internal\OccurrenceFinishController;

Route::middleware('api.key')->group(function () {

    // Integração externa
    Route::post('integrations/occurrences', [OccurrenceIntegrationController::class, 'store']);

    // API interna
    Route::post('occurrences/{occurrence}/dispatches', [DispatchController::class, 'store']);

    Route::patch('dispatches/{dispatch}/status', [DispatchStatusController::class, 'update']);
    Route::patch('occurrences/{occurrence}/status', [OccurrenceStatusController::class, 'update']);

    Route::post('occurrences/{occurrence}/start', [OccurrenceStartController::class, 'store']);
    Route::post('occurrences/{occurrence}/resolve', [OccurrenceFinishController::class, 'store']);
});
