import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

export default function InboxIndex({ conversations = { data: [] } }) {
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-2xl font-extrabold text-white">
                    Inbox de Conversaciones (Handoff Humano)
                </h2>
            }
        >
            <Head title="Inbox WhatsApp - Ualdo Business" />

            <div className="py-10 bg-ualdo-tinta min-h-screen font-sans">
                <div className="mx-auto max-w-5xl sm:px-6 lg:px-8 space-y-8">
                    <div className="bg-black/40 border border-ualdo-teal/30 rounded-3xl p-8 shadow-2xl backdrop-blur-xl">
                        <h3 className="text-xl font-bold text-white mb-4">Conversaciones con Pacientes</h3>

                        {conversations.data.length === 0 ? (
                            <div className="p-8 text-center border-2 border-dashed border-white/10 rounded-2xl">
                                <p className="text-gray-400">No hay conversaciones registradas en este negocio aún.</p>
                            </div>
                        ) : (
                            <div className="divide-y divide-white/10">
                                {conversations.data.map((c) => {
                                    const lastMsg = c.messages && c.messages[0] ? c.messages[0].content : 'Sin mensajes';
                                    return (
                                        <Link
                                            key={c.id}
                                            href={route('inbox.show', c.id)}
                                            className="flex items-center justify-between p-4 hover:bg-white/5 transition-colors rounded-xl block"
                                        >
                                            <div>
                                                <div className="font-bold text-white text-base">{c.name || 'Paciente'}</div>
                                                <div className="text-xs text-ualdo-turquesa mt-0.5">{c.phone_number}</div>
                                                <p className="text-sm text-gray-300 line-clamp-1 mt-1">{lastMsg}</p>
                                            </div>
                                            <div className="text-xs text-gray-400">
                                                {new Date(c.updated_at).toLocaleDateString()}
                                            </div>
                                        </Link>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
