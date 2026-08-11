import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';

export default function TeamIndex({ invitations = [], teamMembers = [] }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        role: 'receptionist',
    });

    const handleInvite = (e) => {
        e.preventDefault();
        post(route('team.invite'), {
            onSuccess: () => reset('email'),
        });
    };

    const roleLabels = {
        owner: 'Propietario / Admin',
        receptionist: 'Recepcionista',
        doctor: 'Doctor / Especialista',
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-2xl font-extrabold text-white">
                    Equipo y Colaboradores
                </h2>
            }
        >
            <Head title="Equipo - Ualdo Business" />

            <div className="py-10 bg-ualdo-tinta min-h-screen font-sans">
                <div className="mx-auto max-w-5xl sm:px-6 lg:px-8 space-y-8">

                    {/* Invite Member Card */}
                    <div className="bg-black/40 border border-ualdo-teal/30 rounded-3xl p-8 shadow-2xl backdrop-blur-xl">
                        <div className="flex items-center space-x-3 mb-6 border-b border-white/10 pb-4">
                            <div className="p-3 bg-ualdo-turquesa/20 text-ualdo-turquesa rounded-2xl text-2xl">
                                ✉️
                            </div>
                            <div>
                                <h3 className="text-xl font-bold text-white">Invitar Colaborador</h3>
                                <p className="text-sm text-gray-400">
                                    Asigna roles y permisos a tu personal para acceder a las funciones del negocio.
                                </p>
                            </div>
                        </div>

                        <form onSubmit={handleInvite} className="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
                            <div>
                                <label className="block text-sm font-semibold text-gray-300 mb-2">Correo Electrónico</label>
                                <input
                                    type="email"
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                    placeholder="colaborador@negocio.com"
                                    className="w-full bg-black/50 border border-ualdo-teal/40 rounded-xl px-4 py-3 text-white placeholder-gray-500 focus:ring-2 focus:ring-ualdo-turquesa focus:outline-none"
                                />
                                {errors.email && <p className="text-red-400 text-xs mt-1">{errors.email}</p>}
                            </div>

                            <div>
                                <label className="block text-sm font-semibold text-gray-300 mb-2">Rol Asignado</label>
                                <select
                                    value={data.role}
                                    onChange={(e) => setData('role', e.target.value)}
                                    className="w-full bg-black/50 border border-ualdo-teal/40 rounded-xl px-4 py-3 text-white focus:ring-2 focus:ring-ualdo-turquesa focus:outline-none"
                                >
                                    <option value="receptionist">Recepcionista</option>
                                    <option value="doctor">Doctor / Especialista</option>
                                    <option value="owner">Propietario / Admin</option>
                                </select>
                            </div>

                            <button
                                type="submit"
                                disabled={processing || !data.email.trim()}
                                className="px-6 py-3 rounded-xl bg-gradient-to-r from-ualdo-turquesa to-ualdo-teal text-ualdo-tinta font-bold hover:brightness-110 disabled:opacity-50 transition-all shadow-lg shadow-ualdo-turquesa/20"
                            >
                                {processing ? 'Enviando...' : 'Enviar Invitación 🚀'}
                            </button>
                        </form>
                    </div>

                    {/* Team Members List */}
                    <div className="bg-black/40 border border-ualdo-teal/30 rounded-3xl p-8 shadow-2xl backdrop-blur-xl">
                        <h4 className="text-xl font-bold text-white mb-4">Miembros Activos del Negocio</h4>
                        
                        <div className="overflow-x-auto">
                            <table className="w-full text-left">
                                <thead>
                                    <tr className="border-b border-white/10 text-xs font-bold uppercase text-gray-400">
                                        <th className="py-3 px-4">Nombre</th>
                                        <th className="py-3 px-4">Correo</th>
                                        <th className="py-3 px-4">Rol</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-white/5 text-sm">
                                    {teamMembers.map((m) => (
                                        <tr key={m.id} className="hover:bg-white/5 transition-colors">
                                            <td className="py-3.5 px-4 text-white font-semibold">{m.name}</td>
                                            <td className="py-3.5 px-4 text-gray-300">{m.email}</td>
                                            <td className="py-3.5 px-4">
                                                <span className="px-3 py-1 bg-ualdo-turquesa/20 text-ualdo-turquesa rounded-full text-xs font-bold border border-ualdo-teal/30">
                                                    {roleLabels[m.role] || m.role}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {/* Pending Invitations */}
                    {invitations.length > 0 && (
                        <div className="bg-black/40 border border-ualdo-teal/30 rounded-3xl p-8 shadow-2xl backdrop-blur-xl">
                            <h4 className="text-xl font-bold text-white mb-4">Invitaciones Pendientes</h4>
                            <div className="space-y-3">
                                {invitations.map((inv) => (
                                    <div key={inv.id} className="flex flex-col sm:flex-row sm:items-center justify-between p-4 bg-white/5 rounded-2xl border border-white/10 text-sm">
                                        <div>
                                            <span className="font-bold text-white">{inv.email}</span>
                                            <span className="ml-3 text-xs bg-ualdo-teal/20 text-ualdo-turquesa px-2 py-0.5 rounded font-semibold capitalize">{inv.role}</span>
                                        </div>
                                        <div className="mt-2 sm:mt-0 text-xs text-gray-400">
                                            {inv.accepted_at ? (
                                                <span className="text-emerald-400 font-bold">✓ Aceptada</span>
                                            ) : (
                                                <span>Expira el {new Date(inv.expires_at).toLocaleDateString()}</span>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                </div>
            </div>
        </AuthenticatedLayout>
    );
}
