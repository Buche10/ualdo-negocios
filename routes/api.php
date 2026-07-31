<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// WhatsApp Webhook Routes
Route::get('/whatsapp/webhook', [\App\Http\Controllers\WhatsAppController::class, 'verifyWebhook']);
Route::post('/whatsapp/webhook', [\App\Http\Controllers\WhatsAppController::class, 'handleWebhook']);
