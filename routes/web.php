<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DeepSeekController;

Route::post('/process-fixed-document', [DeepSeekController::class, 'processFixedDocument']);
Route::post('/clear-session', [DeepSeekController::class, 'clearSession']);
Route::get('/debug-info', [DeepSeekController::class, 'getDebugInfo']);

// Test route to verify cache configuration
Route::get('/test-cache', function () {
    try {
        $testKey = 'test_' . time();
        $testValue = 'Cache is working!';
        
        // Test file cache specifically
        Cache::store('file')->put($testKey, $testValue, 60);
        $retrieved = Cache::store('file')->get($testKey);
        
        return response()->json([
            'status' => 'success',
            'message' => 'Cache test successful',
            'cache_driver' => config('cache.default'),
            'test_key' => $testKey,
            'stored_value' => $testValue,
            'retrieved_value' => $retrieved,
            'match' => $testValue === $retrieved
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Cache test failed',
            'error' => $e->getMessage(),
            'cache_driver' => config('cache.default')
        ], 500);
    }
});

Route::get('/', function () {
    return view('deepseek-chat-blade');
});