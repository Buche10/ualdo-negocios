<?php

use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\WhatsAppController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// WhatsApp Webhook Routes with Rate Limiting (60 requests / min)
Route::get('/whatsapp/webhook', [WhatsAppController::class, 'verifyWebhook'])->middleware('throttle:60,1');
Route::post('/whatsapp/webhook', [WhatsAppController::class, 'handleWebhook'])->middleware('throttle:60,1');

// Telegram Webhook Route for Staff Members
Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle'])->middleware('throttle:60,1');

// Payment Webhook Route for Multi-Gateway Providers
Route::post('/payments/{provider}/webhook', [PaymentWebhookController::class, 'handle'])->middleware('throttle:60,1');
