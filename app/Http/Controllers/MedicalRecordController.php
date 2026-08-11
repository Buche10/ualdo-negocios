<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MedicalRecordController extends Controller
{
    public function index(Contact $contact): Response
    {
        $records = $contact->medicalRecords()->with('doctor')->orderBy('created_at', 'desc')->get();

        return Inertia::render('MedicalRecords/Index', [
            'patient' => $contact,
            'records' => $records,
        ]);
    }

    public function store(Request $request, Contact $contact): RedirectResponse
    {
        $validated = $request->validate([
            'appointment_id' => 'nullable|exists:appointments,id',
            'doctor_id' => 'nullable|exists:doctors,id',
            'diagnosis' => 'nullable|string',
            'treatment_plan' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $contact->medicalRecords()->create($validated);

        return redirect()->back()->with('message', 'Ficha médica actualizada');
    }
}
