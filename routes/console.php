<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Programar recordatorios automáticos de citas por WhatsApp todos los días a las 08:00 AM
Schedule::command('appointments:send-reminders')->dailyAt('08:00');

// Programar recordatorios proactivos de tareas y memorias por Telegram cada 5 minutos
Schedule::command('app:send-task-reminders')->everyFiveMinutes();

// Programar sincronización de inventario Doble Filo cada 15 minutos (Desactivado hasta credenciales reales)
// Schedule::command('integrations:sync-doblefilo')->everyFifteenMinutes();
