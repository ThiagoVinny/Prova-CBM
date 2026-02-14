<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Integration\OccurrenceIntegrationController;

Route::post('integrations/occurrences', [OccurrenceIntegrationController::class, 'store']);
