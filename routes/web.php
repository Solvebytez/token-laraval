<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'Token Tracker API',
        'version' => '1.0.0',
    ]);
});
