<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DoctorController extends Controller
{
    public function index(): Response
    {
        $doctors = Doctor::withCount('appointments')->orderBy('name')->get();

        return Inertia::render('Doctors/Index', [
            'doctors' => $doctors,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'specialty' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'google_calendar_id' => 'nullable|string',
        ]);

        Doctor::create($validated);

        return redirect()->back()->with('message', 'Doctor creado exitosamente');
    }

    public function destroy(Doctor $doctor): RedirectResponse
    {
        $doctor->delete();

        return redirect()->back()->with('message', 'Doctor eliminado');
    }
}
