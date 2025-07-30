<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DeepSeekController;

Route::post('/process-fixed-document', [DeepSeekController::class, 'processFixedDocument']);
Route::get('/', function () {
    return view('deepseek-chat-blade');
});