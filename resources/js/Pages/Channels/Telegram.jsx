import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, usePage } from '@inertiajs/react';

export default function TelegramChannel({ status = {}, bot_username = 'UaldoBot' }) {
    const { flash } = usePage().props;
    const { post, processing } = useForm({});

    const handleGenerateCode = (e) => {
        e.preventDefault();
        post(route('channels.telegram.code'));
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-2xl font-extrabold text-white">
                    Conexión Telegram Staff
                </h2>
            }
        >
            <Head title="Telegram Staff - Ualdo Business" />

            <div className="py-10 bg-ualdo-tinta min-h-screen font-sans">
                <div className="mx-auto max-w-4xl sm:px-6 lg:px-8 space-y-8">
                    <div className="bg-black/40 border border-ualdo-teal/30 rounded-3xl p-8 shadow-2xl backdrop-blur-xl">
                        <div className="flex items-center space-x-4 mb-6 border-b border-white/10 pb-4">
                            <div className="p-3 bg-sky-500/20 text-sky-400 rounded-2xl text-3xl">
                                ✈️
                            </div>
                            <div>
                                <h3 className="text-xl font-bold text-white">Vinculación de Telegram Staff</h3>
                                <p className="text-sm text-gray-400">
                                    Conecta tu cuenta personal de Telegram para gestionar tareas, recibir recordatorios y notas con Ualdo.
                                </p>
                            </div>
                        </div>

                        {status.is_connected ? (
                            <div className="p-6 bg-emerald-950/40 border border-emerald-500/40 rounded-2xl space-y-2">
                                <div className="flex items-center space-x-2 text-emerald-400 font-bold text-lg">
                                    <span>✓ Cuenta de Telegram Conectada</span>
                                </div>
                                <p className="text-sm text-gray-300">
                                    Chat ID registrado: <code className="bg-black/60 px-2 py-1 rounded text-white">{status.telegram_chat_id}</code>
                                </p>
                            </div>
                        ) : (
                            <div className="space-y-6">
                                <div className="p-4 bg-amber-500/10 border border-amber-500/30 rounded-2xl text-amber-300 text-sm">
                                    Aún no has conectado tu cuenta de Telegram. Genera un código de conexión a continuación.
                                </div>

                                {flash && flash.connect_code && (
                                    <div className="p-6 bg-ualdo-profundo/60 border border-ualdo-turquesa/50 rounded-2xl text-center space-y-3">
                                        <p className="text-xs font-bold uppercase tracking-wider text-ualdo-turquesa">Tu código de conexión temporal (15 min):</p>
                                        <div className="text-4xl font-black text-white tracking-widest bg-black/60 py-3 rounded-xl inline-block px-8 border border-ualdo-turquesa/30">
                                            {flash.connect_code}
                                        </div>
                                        <p className="text-sm text-gray-300">
                                            Abre Telegram, busca al bot <strong className="text-white">@{bot_username}</strong> y envía:
                                        </p>
                                        <code className="block bg-black/80 text-emerald-400 py-2 px-4 rounded-xl text-sm font-mono font-bold">
                                            /connect {flash.connect_code}
                                        </code>
                                    </div>
                                )}

                                <form onSubmit={handleGenerateCode} className="pt-2">
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="px-6 py-3 rounded-xl bg-gradient-to-r from-ualdo-turquesa to-ualdo-teal text-ualdo-tinta font-bold hover:brightness-110 disabled:opacity-50 transition-all shadow-lg shadow-ualdo-turquesa/20"
                                    >
                                        {processing ? 'Generando...' : 'Generar Código de Conexión 🔑'}
                                    </button>
                                </form>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
