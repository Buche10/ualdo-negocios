import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';

export default function InboxShow({ contact = {}, messages = [] }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        message: '',
    });

    const handleReply = (e) => {
        e.preventDefault();
        post(route('inbox.reply', contact.id), {
            onSuccess: () => reset('message'),
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-2xl font-extrabold text-white">
                    Chat con {contact.name || contact.phone_number}
                </h2>
            }
        >
            <Head title={`Chat con ${contact.name || contact.phone_number} - Ualdo`} />

            <div className="py-8 bg-ualdo-tinta min-h-screen font-sans">
                <div className="mx-auto max-w-4xl sm:px-6 lg:px-8 space-y-6">

                    {/* Messages Container */}
                    <div className="bg-black/40 border border-ualdo-teal/30 rounded-3xl p-6 shadow-2xl backdrop-blur-xl min-h-[400px] flex flex-col justify-between">
                        <div className="space-y-4 max-h-[500px] overflow-y-auto pr-2">
                            {messages.map((m) => {
                                const isAssistant = m.role === 'assistant';
                                return (
                                    <div
                                        key={m.id}
                                        className={`flex flex-col ${isAssistant ? 'items-end' : 'items-start'}`}
                                    >
                                        <div
                                            className={`max-w-lg px-4 py-3 rounded-2xl text-sm ${
                                                isAssistant
                                                    ? 'bg-ualdo-profundo text-white rounded-br-none'
                                                    : 'bg-white/10 text-gray-100 rounded-bl-none'
                                            }`}
                                        >
                                            {m.content}
                                        </div>
                                        <span className="text-[10px] text-gray-400 mt-1 px-1">
                                            {isAssistant ? 'Ualdo Staff / Bot' : contact.name || 'Paciente'} • {new Date(m.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>

                        {/* Reply Form */}
                        <form onSubmit={handleReply} className="mt-6 pt-4 border-t border-white/10 flex gap-3">
                            <input
                                type="text"
                                value={data.message}
                                onChange={(e) => setData('message', e.target.value)}
                                placeholder="Escribe una respuesta como humano (pausará al bot 24h)..."
                                className="flex-1 bg-black/50 border border-ualdo-teal/40 rounded-xl px-4 py-3 text-white placeholder-gray-500 focus:ring-2 focus:ring-ualdo-turquesa focus:outline-none text-sm"
                            />
                            <button
                                type="submit"
                                disabled={processing || !data.message.trim()}
                                className="px-6 py-3 rounded-xl bg-gradient-to-r from-ualdo-turquesa to-ualdo-teal text-ualdo-tinta font-bold hover:brightness-110 disabled:opacity-50 transition-all text-sm shadow-lg shadow-ualdo-turquesa/20"
                            >
                                {processing ? 'Enviando...' : 'Enviar 🚀'}
                            </button>
                        </form>
                    </div>

                </div>
            </div>
        </AuthenticatedLayout>
    );
}
