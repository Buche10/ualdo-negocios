import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import AdminChatWidget from '@/Components/AdminChatWidget';
import { Head, Link } from '@inertiajs/react';

export default function Dashboard({ metrics = {}, recentAppointments = [] }) {
    const {
        totalAppointments = 0,
        scheduledAppointments = 0,
        totalPatients = 0,
        totalDoctors = 0,
        lowStockCount = 0,
    } = metrics;

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-2xl font-extrabold leading-tight text-white tracking-tight">
                    Ualdo Business Manager
                </h2>
            }
        >
            <Head title="Dashboard" />

            <div className="py-10 bg-ualdo-tinta min-h-screen font-sans">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-8">
                    
                    {/* Header Banner */}
                    <div className="overflow-hidden rounded-3xl bg-gradient-to-r from-ualdo-profundo via-ualdo-teal to-ualdo-tinta border border-ualdo-teal/30 shadow-2xl p-8 text-white relative">
                        <div className="absolute right-6 top-6 opacity-20 hidden sm:block">
                            <img src="/brand/isotipo.png" alt="" className="w-32 h-32" />
                        </div>
                        <h3 className="text-3xl font-extrabold text-transparent bg-clip-text bg-gradient-to-r from-ualdo-turquesa to-white mb-2">
                            Panel de Control — Ualdo AI
                        </h3>
                        <p className="text-gray-200 max-w-2xl text-base">
                            Atención automatizada por WhatsApp 24/7 con IA, agendamiento multi-doctor, gestión de inventario e historial clínico.
                        </p>
                    </div>

                    {/* Low Stock Alert if any */}
                    {lowStockCount > 0 && (
                        <div className="bg-amber-950/70 border border-amber-500/60 rounded-2xl p-4 text-amber-200 flex items-center justify-between shadow-lg">
                            <div className="flex items-center space-x-3">
                                <span className="text-2xl">⚠️</span>
                                <div>
                                    <span className="font-bold">Alerta de Insumos:</span> Tienes {lowStockCount} insumo(s) con stock por debajo del límite mínimo.
                                </div>
                            </div>
                            <Link href={route('inventory.index')} className="bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold py-2 px-4 rounded-xl transition-all shadow">
                                Revisar Inventario
                            </Link>
                        </div>
                    )}

                    {/* Stats Grid */}
                    <div className="grid grid-cols-1 gap-6 md:grid-cols-4">
                        <div className="p-6 transition-all duration-300 rounded-2xl bg-black/40 border border-ualdo-teal/20 hover:border-ualdo-turquesa shadow-xl">
                            <div className="flex items-center justify-between">
                                <h4 className="text-sm font-semibold text-gray-300">Citas Programadas</h4>
                                <span className="p-2 bg-ualdo-turquesa/20 text-ualdo-turquesa rounded-xl text-xs font-bold">📅 Citas</span>
                            </div>
                            <p className="mt-4 text-3xl font-bold text-white">{scheduledAppointments} <span className="text-xs font-normal text-gray-400">activas</span></p>
                        </div>

                        <div className="p-6 transition-all duration-300 rounded-2xl bg-black/40 border border-ualdo-teal/20 hover:border-ualdo-turquesa shadow-xl">
                            <div className="flex items-center justify-between">
                                <h4 className="text-sm font-semibold text-gray-300">Pacientes Atendidos</h4>
                                <span className="p-2 bg-ualdo-teal/20 text-ualdo-turquesa rounded-xl text-xs font-bold">👥 Contactos</span>
                            </div>
                            <p className="mt-4 text-3xl font-bold text-white">{totalPatients} <span className="text-xs font-normal text-gray-400">registrados</span></p>
                        </div>

                        <div className="p-6 transition-all duration-300 rounded-2xl bg-black/40 border border-ualdo-teal/20 hover:border-ualdo-turquesa shadow-xl">
                            <div className="flex items-center justify-between">
                                <h4 className="text-sm font-semibold text-gray-300">Doctores / Staff</h4>
                                <span className="p-2 bg-emerald-500/20 text-emerald-400 rounded-xl text-xs font-bold">🩺 Staff</span>
                            </div>
                            <p className="mt-4 text-3xl font-bold text-white">{totalDoctors} <span className="text-xs font-normal text-gray-400">activos</span></p>
                        </div>

                        <div className="p-6 transition-all duration-300 rounded-2xl bg-black/40 border border-ualdo-teal/20 hover:border-amber-500 shadow-xl">
                            <div className="flex items-center justify-between">
                                <h4 className="text-sm font-semibold text-gray-300">Insumos Críticos</h4>
                                <span className="p-2 bg-amber-500/20 text-amber-400 rounded-xl text-xs font-bold">📦 Stock</span>
                            </div>
                            <p className="mt-4 text-3xl font-bold text-white">{lowStockCount} <span className="text-xs font-normal text-gray-400">alertas</span></p>
                        </div>
                    </div>

                    {/* Recent Appointments */}
                    <div className="p-6 rounded-3xl bg-black/40 border border-ualdo-teal/20 shadow-2xl">
                        <div className="flex justify-between items-center mb-6">
                            <h4 className="text-xl font-bold text-white">Próximas Citas Agendadas por Ualdo IA</h4>
                        </div>
                        {recentAppointments.length === 0 ? (
                            <div className="flex items-center justify-center h-36 border-2 border-dashed border-white/10 rounded-2xl">
                                <p className="text-gray-400">No hay citas registradas en el sistema aún.</p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left">
                                    <thead>
                                        <tr className="border-b border-white/10 text-xs font-bold uppercase text-gray-400">
                                            <th className="py-3 px-4">Paciente</th>
                                            <th className="py-3 px-4">Motivo / Tratamiento</th>
                                            <th className="py-3 px-4">Doctor</th>
                                            <th className="py-3 px-4">Fecha y Hora</th>
                                            <th className="py-3 px-4">Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-white/5 text-sm">
                                        {recentAppointments.map((app) => (
                                            <tr key={app.id} className="hover:bg-white/5 transition-colors">
                                                <td className="py-3 px-4 text-white font-medium">{app.contact?.name || 'Paciente'}</td>
                                                <td className="py-3 px-4 text-gray-300">{app.title}</td>
                                                <td className="py-3 px-4 text-gray-300">{app.doctor?.name || 'General'}</td>
                                                <td className="py-3 px-4 text-gray-300">{new Date(app.start_time).toLocaleString()}</td>
                                                <td className="py-3 px-4">
                                                    <span className="px-2.5 py-1 bg-ualdo-teal/20 text-ualdo-turquesa rounded-lg text-xs font-bold border border-ualdo-teal/30">
                                                        {app.status}
                                                    </span>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>

                </div>
            </div>

            {/* Admin AI Chat Floating Widget */}
            <AdminChatWidget />
        </AuthenticatedLayout>
    );
}
