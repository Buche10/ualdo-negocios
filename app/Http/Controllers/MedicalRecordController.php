<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\MedicalRecord;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MedicalRecordController extends Controller
{
    public function index(Contact $contact)
    {
        $records = $contact->medicalRecords()->with('doctor')->orderBy('created_at', 'desc')->get();

        return Inertia::render('MedicalRecords/Index', [
            'patient' => $contact,
            'records' => $records,
        ]);
    }

    public function store(Request $request, Contact $contact)
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
