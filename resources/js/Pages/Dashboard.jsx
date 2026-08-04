import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
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
                <h2 className="text-2xl font-bold leading-tight text-white">
                    Ualdo Business Manager
                </h2>
            }
        >
            <Head title="Dashboard" />

            <div className="py-12 bg-gray-900 min-h-screen">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-8">
                    
                    {/* Header Banner */}
                    <div className="overflow-hidden rounded-2xl bg-gradient-to-r from-blue-900/60 to-purple-900/60 backdrop-blur-lg border border-white/10 shadow-2xl p-8">
                        <h3 className="text-3xl font-extrabold text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-purple-400 mb-2">
                            Panel de Control - Consultorio de Salud
                        </h3>
                        <p className="text-gray-300">
                            Atención automatizada por WhatsApp 24/7, agendamiento multi-doctor, gestión de inventario e historial médico.
                        </p>
                    </div>

                    {/* Low Stock Alert if any */}
                    {lowStockCount > 0 && (
                        <div className="bg-amber-950/70 border border-amber-500/60 rounded-xl p-4 text-amber-200 flex items-center justify-between">
                            <div className="flex items-center space-x-3">
                                <span className="text-2xl">⚠️</span>
                                <div>
                                    <span className="font-bold">Alerta de Insumos:</span> Tienes {lowStockCount} insumo(s) con stock por debajo del límite mínimo.
                                </div>
                            </div>
                            <Link href={route('inventory.index')} className="bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold py-1.5 px-3 rounded-lg transition-colors">
                                Revisar Inventario
                            </Link>
                        </div>
                    )}

                    {/* Stats Grid */}
                    <div className="grid grid-cols-1 gap-6 md:grid-cols-4">
                        <div className="p-6 transition-all duration-300 rounded-xl bg-gradient-to-br from-gray-800 to-gray-900 border border-gray-700 hover:border-blue-500">
                            <div className="flex items-center justify-between">
                                <h4 className="text-sm font-semibold text-gray-400">Citas Programadas</h4>
                                <span className="p-2 bg-blue-500/20 text-blue-400 rounded-lg text-xs font-bold">📅 Citas</span>
                            </div>
                            <p className="mt-4 text-3xl font-bold text-white">{scheduledAppointments} <span className="text-xs font-normal text-gray-500">activas</span></p>
                        </div>

                        <div className="p-6 transition-all duration-300 rounded-xl bg-gradient-to-br from-gray-800 to-gray-900 border border-gray-700 hover:border-purple-500">
                            <div className="flex items-center justify-between">
                                <h4 className="text-sm font-semibold text-gray-400">Pacientes Atendidos</h4>
                                <span className="p-2 bg-purple-500/20 text-purple-400 rounded-lg text-xs font-bold">👥 Contactos</span>
                            </div>
                            <p className="mt-4 text-3xl font-bold text-white">{totalPatients} <span className="text-xs font-normal text-gray-500">registrados</span></p>
                        </div>

                        <div className="p-6 transition-all duration-300 rounded-xl bg-gradient-to-br from-gray-800 to-gray-900 border border-gray-700 hover:border-emerald-500">
                            <div className="flex items-center justify-between">
                                <h4 className="text-sm font-semibold text-gray-400">Doctores / Especialistas</h4>
                                <span className="p-2 bg-emerald-500/20 text-emerald-400 rounded-lg text-xs font-bold">🩺 Staff</span>
                            </div>
                            <p className="mt-4 text-3xl font-bold text-white">{totalDoctors} <span className="text-xs font-normal text-gray-500">activos</span></p>
                        </div>

                        <div className="p-6 transition-all duration-300 rounded-xl bg-gradient-to-br from-gray-800 to-gray-900 border border-gray-700 hover:border-amber-500">
                            <div className="flex items-center justify-between">
                                <h4 className="text-sm font-semibold text-gray-400">Insumos en Alerta</h4>
                                <span className="p-2 bg-amber-500/20 text-amber-400 rounded-lg text-xs font-bold">📦 Stock</span>
                            </div>
                            <p className="mt-4 text-3xl font-bold text-white">{lowStockCount} <span className="text-xs font-normal text-gray-500">críticos</span></p>
                        </div>
                    </div>

                    {/* Recent Appointments */}
                    <div className="p-6 rounded-2xl bg-gray-800/50 border border-gray-700 shadow-xl">
                        <div className="flex justify-between items-center mb-4">
                            <h4 className="text-xl font-bold text-white">Próximas Citas Agendadas por Ualdo IA</h4>
                        </div>
                        {recentAppointments.length === 0 ? (
                            <div className="flex items-center justify-center h-32 border-2 border-dashed border-gray-700 rounded-xl">
                                <p className="text-gray-500">No hay citas registradas en el sistema aún.</p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left">
                                    <thead>
                                        <tr className="border-b border-gray-700 text-xs font-bold uppercase text-gray-400">
                                            <th className="py-3 px-4">Paciente</th>
                                            <th className="py-3 px-4">Motivo / Tratamiento</th>
                                            <th className="py-3 px-4">Doctor</th>
                                            <th className="py-3 px-4">Fecha y Hora</th>
                                            <th className="py-3 px-4">Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-700 text-sm">
                                        {recentAppointments.map((app) => (
                                            <tr key={app.id} className="hover:bg-gray-700/40">
                                                <td className="py-3 px-4 text-white font-medium">{app.contact?.name || 'Paciente'}</td>
                                                <td className="py-3 px-4 text-gray-300">{app.title}</td>
                                                <td className="py-3 px-4 text-gray-300">{app.doctor?.name || 'General'}</td>
                                                <td className="py-3 px-4 text-gray-300">{new Date(app.start_time).toLocaleString()}</td>
                                                <td className="py-3 px-4">
                                                    <span className="px-2 py-1 bg-emerald-900/60 text-emerald-300 rounded text-xs border border-emerald-700">
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
        </AuthenticatedLayout>
    );
}
