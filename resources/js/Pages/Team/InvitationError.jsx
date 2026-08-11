import React from 'react';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link } from '@inertiajs/react';

export default function InvitationError({ error }) {
    return (
        <GuestLayout>
            <Head title="Error de Invitación" />

            <div className="text-center space-y-4 py-4">
                <div className="text-4xl">⚠️</div>
                <h3 className="text-xl font-bold text-white">Invitación No Válida</h3>
                <p className="text-sm text-gray-300">
                    {error || 'No fue posible procesar la invitación. Por favor solicita un nuevo enlace.'}
                </p>
                <div className="pt-4">
                    <Link
                        href={route('dashboard')}
                        className="inline-block px-6 py-2.5 rounded-xl bg-ualdo-turquesa text-ualdo-tinta font-bold hover:brightness-110 transition-all text-sm"
                    >
                        Ir al Dashboard
                    </Link>
                </div>
            </div>
        </GuestLayout>
    );
}
