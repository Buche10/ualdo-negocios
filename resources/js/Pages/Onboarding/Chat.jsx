import React, { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import UaldoLogo from '@/Components/UaldoLogo';
import UaldoMascot from '@/Components/UaldoMascot';

export default function OnboardingChat() {
    const [step, setStep] = useState(1);

    const { data, setData, post, processing, errors } = useForm({
        name: '',
        vertical: 'health',
        working_days: ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
        business_hours_start: '09:00',
        business_hours_end: '18:00',
        slot_duration_minutes: 45,
    });

    const verticals = [
        { id: 'health', label: 'Salud / Consultorio Dental', icon: '🩺' },
        { id: 'aesthetic', label: 'Estética / Spa / Spa Facial', icon: '✨' },
        { id: 'barbershop', label: 'Barbería / Peluquería', icon: '💈' },
        { id: 'restaurant', label: 'Restaurante / Café / Gastro', icon: '🍽️' },
        { id: 'retail', label: 'Retail / Comercio / Tienda', icon: '🛍️' },
        { id: 'other', label: 'Otro Rubro o Servicio', icon: '💼' },
    ];

    const daysList = [
        { id: 'monday', label: 'Lun' },
        { id: 'tuesday', label: 'Mar' },
        { id: 'wednesday', label: 'Mié' },
        { id: 'thursday', label: 'Jue' },
        { id: 'friday', label: 'Vie' },
        { id: 'saturday', label: 'Sáb' },
        { id: 'sunday', label: 'Dom' },
    ];

    const toggleDay = (dayId) => {
        if (data.working_days.includes(dayId)) {
            if (data.working_days.length > 1) {
                setData('working_days', data.working_days.filter(d => d !== dayId));
            }
        } else {
            setData('working_days', [...data.working_days, dayId]);
        }
    };

    const handleNext = (e) => {
        if (e) e.preventDefault();
        if (step === 1 && !data.name.trim()) return;
        if (step < 6) {
            setStep(prev => prev + 1);
        }
    };

    const handleBack = () => {
        if (step > 1) {
            setStep(prev => prev - 1);
        }
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('onboarding.store'));
    };

    return (
        <div className="min-h-screen bg-gradient-to-br from-ualdo-profundo via-ualdo-tinta to-black text-white font-sans flex flex-col">
            <Head title="Configuración de tu Negocio - Ualdo" />

            {/* Header / Brand */}
            <header className="p-6 max-w-7xl w-full mx-auto flex items-center justify-between">
                <UaldoLogo variant="light" className="h-10" />
                <div className="flex items-center space-x-2 text-sm text-ualdo-turquesa font-medium bg-ualdo-tinta/60 border border-ualdo-teal/30 px-4 py-1.5 rounded-full backdrop-blur-md">
                    <span>Paso {step} de 6</span>
                    <div className="w-16 bg-gray-700 h-1.5 rounded-full overflow-hidden">
                        <div
                            className="bg-ualdo-turquesa h-full transition-all duration-500"
                            style={{ width: `${(step / 6) * 100}%` }}
                        />
                    </div>
                </div>
            </header>

            {/* Main Layout */}
            <main className="flex-1 max-w-5xl w-full mx-auto px-4 py-8 flex flex-col md:flex-row items-center gap-8 md:gap-12">
                {/* Mascot & Intro (Left) */}
                <div className="md:w-1/3 flex flex-col items-center text-center">
                    <UaldoMascot className="w-40 h-40 md:w-56 md:h-56 mb-4" />
                    <h2 className="text-xl font-bold text-ualdo-turquesa">Ualdo AI</h2>
                    <p className="text-sm text-gray-300 mt-1 max-w-xs">
                        Tu asistente virtual inteligente. Configuremos tu negocio en menos de 1 minuto.
                    </p>
                </div>

                {/* Interactive Chat Card (Right) */}
                <div className="md:w-2/3 w-full bg-ualdo-tinta/80 border border-ualdo-teal/30 rounded-3xl p-6 sm:p-8 shadow-2xl backdrop-blur-xl flex flex-col justify-between min-h-[420px]">
                    <div>
                        {/* Question 1: Name */}
                        {step === 1 && (
                            <div className="space-y-6 animate-fadeIn">
                                <div className="flex items-start space-x-3">
                                    <div className="p-2 bg-ualdo-teal/20 rounded-2xl border border-ualdo-teal/30 text-2xl">
                                        👋
                                    </div>
                                    <div>
                                        <h3 className="text-2xl font-bold text-white">¡Hola! Soy Ualdo</h3>
                                        <p className="text-gray-300 mt-1 text-lg">¿Cómo se llama tu negocio o consultorio?</p>
                                    </div>
                                </div>

                                <div className="mt-4">
                                    <input
                                        type="text"
                                        value={data.name}
                                        onChange={e => setData('name', e.target.value)}
                                        onKeyDown={e => e.key === 'Enter' && handleNext(e)}
                                        placeholder="Ej: Clínica Dental Smile, Barbería Capital..."
                                        className="w-full bg-black/40 border border-ualdo-teal/40 rounded-2xl px-5 py-4 text-white text-lg placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-ualdo-turquesa"
                                        autoFocus
                                    />
                                    {errors.name && <p className="text-red-400 text-sm mt-2">{errors.name}</p>}
                                </div>
                            </div>
                        )}

                        {/* Question 2: Vertical */}
                        {step === 2 && (
                            <div className="space-y-6 animate-fadeIn">
                                <div className="flex items-start space-x-3">
                                    <div className="p-2 bg-ualdo-teal/20 rounded-2xl border border-ualdo-teal/30 text-2xl">
                                        🏷️
                                    </div>
                                    <div>
                                        <h3 className="text-2xl font-bold text-white">¿A qué rubro se dedica <span className="text-ualdo-turquesa">{data.name}</span>?</h3>
                                        <p className="text-gray-300 mt-1">Selecciona el área principal de tu actividad:</p>
                                    </div>
                                </div>

                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-4">
                                    {verticals.map(v => (
                                        <button
                                            key={v.id}
                                            type="button"
                                            onClick={() => {
                                                setData('vertical', v.id);
                                                setStep(3);
                                            }}
                                            className={`p-4 rounded-2xl border text-left flex items-center space-x-3 transition-all duration-200 ${
                                                data.vertical === v.id
                                                    ? 'bg-ualdo-profundo/60 border-ualdo-turquesa shadow-lg shadow-ualdo-turquesa/10'
                                                    : 'bg-black/30 border-white/10 hover:border-ualdo-teal/50 hover:bg-black/50'
                                            }`}
                                        >
                                            <span className="text-2xl">{v.icon}</span>
                                            <span className="font-medium text-white">{v.label}</span>
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* Question 3: Working Days */}
                        {step === 3 && (
                            <div className="space-y-6 animate-fadeIn">
                                <div className="flex items-start space-x-3">
                                    <div className="p-2 bg-ualdo-teal/20 rounded-2xl border border-ualdo-teal/30 text-2xl">
                                        📅
                                    </div>
                                    <div>
                                        <h3 className="text-2xl font-bold text-white">¿Qué días de la semana atiendes?</h3>
                                        <p className="text-gray-300 mt-1">Marca los días en los que tu negocio está abierto:</p>
                                    </div>
                                </div>

                                <div className="flex flex-wrap gap-2.5 mt-4">
                                    {daysList.map(d => {
                                        const isSelected = data.working_days.includes(d.id);
                                        return (
                                            <button
                                                key={d.id}
                                                type="button"
                                                onClick={() => toggleDay(d.id)}
                                                className={`px-5 py-3 rounded-2xl border text-base font-semibold transition-all duration-200 ${
                                                    isSelected
                                                        ? 'bg-ualdo-turquesa text-ualdo-tinta border-ualdo-turquesa shadow-md shadow-ualdo-turquesa/20'
                                                        : 'bg-black/30 text-gray-400 border-white/10 hover:border-white/30'
                                                }`}
                                            >
                                                {d.label}
                                            </button>
                                        );
                                    })}
                                </div>
                                {errors.working_days && <p className="text-red-400 text-sm mt-2">{errors.working_days}</p>}
                            </div>
                        )}

                        {/* Question 4: Business Hours */}
                        {step === 4 && (
                            <div className="space-y-6 animate-fadeIn">
                                <div className="flex items-start space-x-3">
                                    <div className="p-2 bg-ualdo-teal/20 rounded-2xl border border-ualdo-teal/30 text-2xl">
                                        ⏰
                                    </div>
                                    <div>
                                        <h3 className="text-2xl font-bold text-white">¿Cuál es tu horario de atención?</h3>
                                        <p className="text-gray-300 mt-1">Define la hora de apertura y cierre diario:</p>
                                    </div>
                                </div>

                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-6 mt-4">
                                    <div>
                                        <label className="block text-sm font-medium text-gray-300 mb-2">Hora de Apertura</label>
                                        <input
                                            type="time"
                                            value={data.business_hours_start}
                                            onChange={e => setData('business_hours_start', e.target.value)}
                                            className="w-full bg-black/40 border border-ualdo-teal/40 rounded-2xl px-5 py-4 text-white text-lg focus:outline-none focus:ring-2 focus:ring-ualdo-turquesa"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-300 mb-2">Hora de Cierre</label>
                                        <input
                                            type="time"
                                            value={data.business_hours_end}
                                            onChange={e => setData('business_hours_end', e.target.value)}
                                            className="w-full bg-black/40 border border-ualdo-teal/40 rounded-2xl px-5 py-4 text-white text-lg focus:outline-none focus:ring-2 focus:ring-ualdo-turquesa"
                                        />
                                    </div>
                                </div>
                                {errors.business_hours_end && <p className="text-red-400 text-sm mt-2">{errors.business_hours_end}</p>}
                            </div>
                        )}

                        {/* Question 5: Slot Duration */}
                        {step === 5 && (
                            <div className="space-y-6 animate-fadeIn">
                                <div className="flex items-start space-x-3">
                                    <div className="p-2 bg-ualdo-teal/20 rounded-2xl border border-ualdo-teal/30 text-2xl">
                                        ⏱️
                                    </div>
                                    <div>
                                        <h3 className="text-2xl font-bold text-white">¿Cuánto dura una cita o turno promedio?</h3>
                                        <p className="text-gray-300 mt-1">Esto nos servirá para calcular la disponibilidad automática:</p>
                                    </div>
                                </div>

                                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4">
                                    {[15, 30, 45, 60].map(mins => (
                                        <button
                                            key={mins}
                                            type="button"
                                            onClick={() => {
                                                setData('slot_duration_minutes', mins);
                                                setStep(6);
                                            }}
                                            className={`p-4 rounded-2xl border text-center transition-all duration-200 ${
                                                data.slot_duration_minutes === mins
                                                    ? 'bg-ualdo-profundo/60 border-ualdo-turquesa text-ualdo-turquesa font-bold shadow-lg'
                                                    : 'bg-black/30 border-white/10 text-white hover:border-ualdo-teal/50'
                                            }`}
                                        >
                                            <span className="text-2xl font-bold block">{mins}</span>
                                            <span className="text-xs text-gray-400">minutos</span>
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* Step 6: Summary & Submit */}
                        {step === 6 && (
                            <div className="space-y-6 animate-fadeIn">
                                <div className="flex items-start space-x-3">
                                    <div className="p-2 bg-ualdo-teal/20 rounded-2xl border border-ualdo-teal/30 text-2xl">
                                        🚀
                                    </div>
                                    <div>
                                        <h3 className="text-2xl font-bold text-white">¡Todo listo para despegar!</h3>
                                        <p className="text-gray-300 mt-1">Revisa el resumen de tu negocio antes de confirmar:</p>
                                    </div>
                                </div>

                                <div className="bg-black/40 border border-white/10 rounded-2xl p-5 space-y-3 text-sm">
                                    <div className="flex justify-between border-b border-white/10 pb-2">
                                        <span className="text-gray-400">Negocio:</span>
                                        <span className="font-semibold text-white">{data.name}</span>
                                    </div>
                                    <div className="flex justify-between border-b border-white/10 pb-2">
                                        <span className="text-gray-400">Rubro:</span>
                                        <span className="font-semibold text-ualdo-turquesa capitalize">{data.vertical}</span>
                                    </div>
                                    <div className="flex justify-between border-b border-white/10 pb-2">
                                        <span className="text-gray-400">Días de Atención:</span>
                                        <span className="font-semibold text-white">{data.working_days.length} días a la semana</span>
                                    </div>
                                    <div className="flex justify-between border-b border-white/10 pb-2">
                                        <span className="text-gray-400">Horario:</span>
                                        <span className="font-semibold text-white">{data.business_hours_start} a {data.business_hours_end}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-gray-400">Duración Cita:</span>
                                        <span className="font-semibold text-white">{data.slot_duration_minutes} min</span>
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Footer Actions */}
                    <div className="mt-8 pt-4 border-t border-white/10 flex items-center justify-between">
                        {step > 1 ? (
                            <button
                                type="button"
                                onClick={handleBack}
                                className="px-5 py-2.5 rounded-xl border border-white/20 text-gray-300 hover:text-white hover:bg-white/5 transition-all text-sm font-medium"
                            >
                                ← Atrás
                            </button>
                        ) : <div />}

                        {step < 6 ? (
                            <button
                                type="button"
                                onClick={handleNext}
                                disabled={step === 1 && !data.name.trim()}
                                className="px-7 py-3 rounded-xl bg-gradient-to-r from-ualdo-turquesa to-ualdo-teal text-ualdo-tinta font-bold hover:brightness-110 disabled:opacity-50 transition-all shadow-lg shadow-ualdo-turquesa/20"
                            >
                                Siguiente →
                            </button>
                        ) : (
                            <button
                                type="button"
                                onClick={handleSubmit}
                                disabled={processing}
                                className="px-8 py-3.5 rounded-xl bg-gradient-to-r from-ualdo-turquesa via-ualdo-teal to-ualdo-profundo text-white font-extrabold hover:brightness-110 disabled:opacity-50 transition-all shadow-xl shadow-ualdo-turquesa/30 animate-pulse"
                            >
                                {processing ? 'Configurando Ualdo AI...' : '¡Confirmar y Crear Negocio! 🚀'}
                            </button>
                        )}
                    </div>
                </div>
            </main>
        </div>
    );
}
