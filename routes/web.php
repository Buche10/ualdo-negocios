<?php

use App\Http\Controllers\AdminChatController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\CrmSsoController;
use App\Http\Controllers\DoctorController;
use App\Http\Controllers\InventoryItemController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\MedicalRecordController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TelegramChannelController;
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

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding');
    Route::post('/onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');

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
    })->name('dashboard');

    // Channel routes (WhatsApp tenant credentials)
    Route::get('/channels/whatsapp', [ChannelController::class, 'show'])->name('channels.whatsapp');
    Route::post('/channels/whatsapp', [ChannelController::class, 'updateWhatsApp'])
        ->name('channels.whatsapp.update')
        ->middleware('throttle:10,1');
    Route::post('/channels/whatsapp/test', [ChannelController::class, 'testWhatsApp'])
        ->name('channels.whatsapp.test')
        ->middleware('throttle:5,1');

    // Telegram Channel & Connection Code routes
    Route::get('/channels/telegram', [TelegramChannelController::class, 'show'])->name('channels.telegram');
    Route::post('/channels/telegram/code', [TelegramChannelController::class, 'generateCode'])
        ->name('channels.telegram.code')
        ->middleware('throttle:5,1');

    // Team & Invitation routes
    Route::get('/team', [InviteController::class, 'index'])->name('team.index');
    Route::post('/team/invite', [InviteController::class, 'send'])
        ->name('team.invite')
        ->middleware('throttle:10,1');
    Route::get('/invitations/accept/{token}', [InviteController::class, 'accept'])->name('invitations.accept');

    // Inbox routes (handoff humano)
    Route::get('/inbox', [ConversationController::class, 'index'])->name('inbox.index');
    Route::get('/inbox/{contact}', [ConversationController::class, 'show'])->name('inbox.show');
    Route::post('/inbox/{contact}/reply', [ConversationController::class, 'reply'])
        ->name('inbox.reply')
        ->middleware('throttle:30,1');

    // SSO CRM route
    Route::get('/sso/crm', [CrmSsoController::class, 'sso'])->name('sso.crm');

    // Admin AI Chat routes
    Route::post('/admin/chat', [AdminChatController::class, 'chat'])
        ->name('admin.chat')
        ->middleware('throttle:60,1');
    Route::post('/admin/chat/approve', [AdminChatController::class, 'approve'])
        ->name('admin.chat.approve')
        ->middleware('throttle:30,1');
    Route::post('/admin/chat/cancel', [AdminChatController::class, 'cancel'])
        ->name('admin.chat.cancel')
        ->middleware('throttle:30,1');
});

Route::get('/sso/crm/verify', [CrmSsoController::class, 'verify'])->name('sso.crm.verify');

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

Route::get('/pay/{paymentId}', [CheckoutController::class, 'show'])->name('checkout.show');

require __DIR__.'/auth.php';
