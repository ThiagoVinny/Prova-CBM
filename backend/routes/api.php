<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Integration\OccurrenceIntegrationController;
use App\Http\Controllers\Internal\DispatchController;
use App\Http\Controllers\Internal\DispatchStatusController;
use App\Http\Controllers\Internal\OccurrenceStatusController;

Route::post('integrations/occurrences', [OccurrenceIntegrationController::class, 'store']);
Route::post('occurrences/{occurrence}/dispatches', [DispatchController::class, 'store']);

Route::patch('dispatches/{dispatch}/status', [DispatchStatusController::class, 'update']);
Route::patch('occurrences/{occurrence}/status', [OccurrenceStatusController::class, 'update']);
