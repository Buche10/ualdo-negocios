<?php

namespace App\Http\Controllers;

use App\Jobs\ProvisionCrmWorkspaceJob;
use App\Models\Business;
use App\Services\VerticalSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    /**
     * Muestra la pantalla de onboarding guiado por chat si el usuario no tiene negocio.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user && $user->business_id) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Onboarding/Chat');
    }

    /**
     * Guarda la configuracion inicial del negocio y enlaza al usuario de forma atómica.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user && $user->business_id) {
            return redirect()->route('dashboard');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'vertical' => 'required|string|in:health,aesthetic,barbershop,restaurant,retail,other',
            'working_days' => 'required|array|min:1',
            'working_days.*' => 'required|string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'business_hours_start' => 'required|date_format:H:i',
            'business_hours_end' => 'required|date_format:H:i|after:business_hours_start',
            'slot_duration_minutes' => 'required|integer|in:15,30,45,60',
        ]);

        $slug = Str::slug($validated['name']);
        if (empty($slug)) {
            $slug = 'negocio-'.Str::random(6);
        }
        if (Business::where('slug', $slug)->exists()) {
            $slug .= '-'.strtolower(Str::random(4));
        }

        DB::transaction(function () use ($user, $validated, $slug) {
            if ($user->fresh()->business_id) {
                return;
            }

            // Crear Business sin contexto (la raiz del tenant)
            $business = Business::create([
                'name' => $validated['name'],
                'slug' => $slug,
                'vertical' => $validated['vertical'],
                'working_days' => $validated['working_days'],
                'business_hours_start' => $validated['business_hours_start'],
                'business_hours_end' => $validated['business_hours_end'],
                'slot_duration_minutes' => (int) $validated['slot_duration_minutes'],
                'timezone' => 'America/Guayaquil',
                'currency' => 'USD',
            ]);

            $user->update(['business_id' => $business->id]);
            $user->assignRole('owner');

            // Siembra catálogo y recursos iniciales según el vertical
            app(VerticalSeeder::class)->seed($business);

            // Despachar aprovisionamiento asíncrono del espacio CRM
            ProvisionCrmWorkspaceJob::dispatch($business->id);
        });

        return redirect()->route('dashboard')->with('message', '¡Bienvenido a Ualdo! Tu negocio ha sido configurado con éxito.');
    }
}
