<?php

use App\Http\Controllers\DoctorController;
use App\Http\Controllers\InventoryItemController;
use App\Http\Controllers\MedicalRecordController;
use App\Http\Controllers\ProfileController;
use App\Models\Appointment;
use App\Models\Contact;
use App\Models\Doctor;
use App\Models\InventoryItem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    $totalAppointments = Appointment::count();
    $scheduledAppointments = Appointment::where('status', 'scheduled')->count();
    $totalPatients = Contact::count();
    $totalDoctors = Doctor::count();
    $lowStockCount = InventoryItem::supplies()->get()->filter(fn ($i) => $i->isLowStock())->count();
    $recentAppointments = Appointment::with(['contact', 'doctor'])->orderBy('start_time', 'desc')->take(5)->get();

    return Inertia::render('Dashboard', [
        'metrics' => [
            'totalAppointments' => $totalAppointments,
            'scheduledAppointments' => $scheduledAppointments,
            'totalPatients' => $totalPatients,
            'totalDoctors' => $totalDoctors,
            'lowStockCount' => $lowStockCount,
        ],
        'recentAppointments' => $recentAppointments,
    ]);
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    
    // Inventory routes
    Route::resource('/inventory', InventoryItemController::class);

    // Doctor routes
    Route::resource('/doctors', DoctorController::class)->only(['index', 'store', 'destroy']);

    // Medical Records routes
    Route::get('/patients/{contact}/records', [MedicalRecordController::class, 'index'])->name('medical-records.index');
    Route::post('/patients/{contact}/records', [MedicalRecordController::class, 'store'])->name('medical-records.store');
});

require __DIR__.'/auth.php';
