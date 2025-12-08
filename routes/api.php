<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TokenDataController;
use App\Http\Controllers\Api\AuthController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::get('/v1', function () {
    return response()->json([
        'status' => 'OK',
        'message' => 'Token Tracker API is running'
    ]);
});

// Public routes (no authentication required)
Route::prefix('v1')->group(function () {
    // Authentication routes
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
});

// Protected routes (authentication required)
Route::prefix('v1')->middleware('auth:api')->group(function () {
    // Token data routes (read operations)
    Route::get('/token-data', [TokenDataController::class, 'getAll']);
    Route::get('/token-data/date/{date}', [TokenDataController::class, 'getByDate']);
    Route::get('/token-data/range', [TokenDataController::class, 'getByDateRange']);
    
    // Token data update route
    Route::put('/token-data/{id}', [TokenDataController::class, 'update']);
    
    // Token data delete route
    Route::delete('/token-data/{id}', [TokenDataController::class, 'destroy']);
    
    // Auth routes (require authentication)
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
});

// Token data save route (handles authentication manually to accept refresh tokens)
Route::prefix('v1')->group(function () {
    Route::post('/token-data', [TokenDataController::class, 'store']);
});
