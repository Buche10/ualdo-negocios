import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';

export default function WhatsAppChannel({ channel = {} }) {
    const { data, setData, post, processing, errors } = useForm({
        whatsapp_phone_number_id: channel.whatsapp_phone_number_id || '',
        whatsapp_phone_number: channel.whatsapp_phone_number || '',
        whatsapp_access_token: channel.masked_token || '',
    });

    const testForm = useForm({
        phone_number: '',
    });

    const handleSave = (e) => {
        e.preventDefault();
        post(route('channels.whatsapp.update'));
    };

    const handleTest = (e) => {
        e.preventDefault();
        testForm.post(route('channels.whatsapp.test'));
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-2xl font-extrabold text-white">
                    Canal WhatsApp de tu Negocio
                </h2>
            }
        >
            <Head title="Canal WhatsApp - Ualdo Business" />

            <div className="py-10 bg-ualdo-tinta min-h-screen font-sans">
                <div className="mx-auto max-w-4xl sm:px-6 lg:px-8 space-y-8">
                    
                    {/* Config Card */}
                    <div className="bg-black/40 border border-ualdo-teal/30 rounded-3xl p-8 shadow-2xl backdrop-blur-xl">
                        <div className="flex items-center space-x-4 mb-6 border-b border-white/10 pb-4">
                            <div className="p-3 bg-emerald-500/20 text-emerald-400 rounded-2xl text-3xl">
                                💬
                            </div>
                            <div>
                                <h3 className="text-xl font-bold text-white">Configuración Meta WhatsApp Cloud API</h3>
                                <p className="text-sm text-gray-400">
                                    Conecta el número oficial de tu negocio para responder citas e interactuar automáticamente con tus clientes.
                                </p>
                            </div>
                        </div>

                        <form onSubmit={handleSave} className="space-y-6">
                            <div>
                                <label className="block text-sm font-semibold text-gray-300 mb-2">
                                    WhatsApp Phone Number ID
                                </label>
                                <input
                                    type="text"
                                    value={data.whatsapp_phone_number_id}
                                    onChange={(e) => setData('whatsapp_phone_number_id', e.target.value)}
                                    placeholder="Ej: 109283746591023"
                                    className="w-full bg-black/50 border border-ualdo-teal/40 rounded-xl px-4 py-3 text-white placeholder-gray-500 focus:ring-2 focus:ring-ualdo-turquesa focus:outline-none"
                                />
                                {errors.whatsapp_phone_number_id && (
                                    <p className="text-red-400 text-xs mt-1">{errors.whatsapp_phone_number_id}</p>
                                )}
                            </div>

                            <div>
                                <label className="block text-sm font-semibold text-gray-300 mb-2">
                                    Número de WhatsApp del Negocio (Opcional)
                                </label>
                                <input
                                    type="text"
                                    value={data.whatsapp_phone_number}
                                    onChange={(e) => setData('whatsapp_phone_number', e.target.value)}
                                    placeholder="Ej: 593991112223"
                                    className="w-full bg-black/50 border border-ualdo-teal/40 rounded-xl px-4 py-3 text-white placeholder-gray-500 focus:ring-2 focus:ring-ualdo-turquesa focus:outline-none"
                                />
                                {errors.whatsapp_phone_number && (
                                    <p className="text-red-400 text-xs mt-1">{errors.whatsapp_phone_number}</p>
                                )}
                            </div>

                            <div>
                                <div className="flex justify-between items-center mb-2">
                                    <label className="block text-sm font-semibold text-gray-300">
                                        Meta Permanent Access Token (Cifrado en Reposo)
                                    </label>
                                    {channel.has_token && (
                                        <span className="text-xs text-emerald-400 bg-emerald-950/60 border border-emerald-500/40 px-2.5 py-0.5 rounded-full font-bold">
                                            ✓ Token Guardado Cifrado
                                        </span>
                                    )}
                                </div>
                                <input
                                    type="password"
                                    value={data.whatsapp_access_token}
                                    onChange={(e) => setData('whatsapp_access_token', e.target.value)}
                                    placeholder="EAAG..."
                                    className="w-full bg-black/50 border border-ualdo-teal/40 rounded-xl px-4 py-3 text-white placeholder-gray-500 focus:ring-2 focus:ring-ualdo-turquesa focus:outline-none"
                                />
                                <p className="text-xs text-gray-400 mt-1">
                                    El token se encripta automáticamente con AES-256 en la base de datos y nunca se expone al cliente.
                                </p>
                                {errors.whatsapp_access_token && (
                                    <p className="text-red-400 text-xs mt-1">{errors.whatsapp_access_token}</p>
                                )}
                            </div>

                            <div className="pt-4 border-t border-white/10 flex justify-end">
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="px-6 py-3 rounded-xl bg-gradient-to-r from-ualdo-turquesa to-ualdo-teal text-ualdo-tinta font-bold hover:brightness-110 disabled:opacity-50 transition-all shadow-lg shadow-ualdo-turquesa/20"
                                >
                                    {processing ? 'Guardando...' : 'Guardar Credenciales'}
                                </button>
                            </div>
                        </form>
                    </div>

                    {/* Test Card */}
                    <div className="bg-black/40 border border-ualdo-teal/30 rounded-3xl p-8 shadow-2xl backdrop-blur-xl">
                        <h4 className="text-lg font-bold text-white mb-2">Prueba de Conexión en Vivo</h4>
                        <p className="text-sm text-gray-400 mb-6">
                            Envía un mensaje de prueba para validar que Ualdo puede conectarse y responder desde tu número configurado.
                        </p>

                        <form onSubmit={handleTest} className="flex flex-col sm:flex-row gap-4">
                            <input
                                type="text"
                                value={testForm.data.phone_number}
                                onChange={(e) => testForm.setData('phone_number', e.target.value)}
                                placeholder="Número WhatsApp destino (Ej: 593999888777)"
                                className="flex-1 bg-black/50 border border-ualdo-teal/40 rounded-xl px-4 py-3 text-white placeholder-gray-500 focus:ring-2 focus:ring-ualdo-turquesa focus:outline-none"
                            />
                            <button
                                type="submit"
                                disabled={testForm.processing || !testForm.data.phone_number.trim()}
                                className="px-6 py-3 rounded-xl bg-ualdo-profundo text-white font-bold hover:bg-ualdo-teal disabled:opacity-50 transition-all border border-ualdo-turquesa/40"
                            >
                                {testForm.processing ? 'Enviando...' : 'Enviar Prueba'}
                            </button>
                        </form>
                        {testForm.errors.test && (
                            <p className="text-red-400 text-sm mt-3">{testForm.errors.test}</p>
                        )}
                    </div>

                </div>
            </div>
        </AuthenticatedLayout>
    );
}
