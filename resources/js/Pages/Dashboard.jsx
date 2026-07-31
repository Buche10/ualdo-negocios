import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

export default function Dashboard() {
    const [activeTab, setActiveTab] = useState('overview');

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
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    
                    {/* Premium Glassmorphism Header */}
                    <div className="mb-8 overflow-hidden rounded-2xl bg-white/10 backdrop-blur-lg border border-white/20 shadow-2xl">
                        <div className="p-8">
                            <h3 className="text-3xl font-extrabold text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-purple-500 mb-2">
                                Bienvenido, humano.
                            </h3>
                            <p className="text-gray-300">
                                Soy Ualdo. Todo está bajo control. Aquí tienes un resumen de tu negocio.
                            </p>
                        </div>
                    </div>

                    {/* Stats Grid */}
                    <div className="grid grid-cols-1 gap-6 mb-8 md:grid-cols-3">
                        <div className="p-6 transition-all duration-300 rounded-xl bg-gradient-to-br from-gray-800 to-gray-900 border border-gray-700 hover:border-blue-500 hover:shadow-[0_0_15px_rgba(59,130,246,0.5)]">
                            <div className="flex items-center justify-between">
                                <h4 className="text-lg font-semibold text-gray-400">Inventario</h4>
                                <div className="p-2 bg-blue-500/20 rounded-lg">
                                    <svg className="w-6 h-6 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                                </div>
                            </div>
                            <p className="mt-4 text-4xl font-bold text-white">0 <span className="text-sm font-normal text-gray-500">ítems</span></p>
                        </div>

                        <div className="p-6 transition-all duration-300 rounded-xl bg-gradient-to-br from-gray-800 to-gray-900 border border-gray-700 hover:border-purple-500 hover:shadow-[0_0_15px_rgba(168,85,247,0.5)]">
                            <div className="flex items-center justify-between">
                                <h4 className="text-lg font-semibold text-gray-400">Mensajes sin leer</h4>
                                <div className="p-2 bg-purple-500/20 rounded-lg">
                                    <svg className="w-6 h-6 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path></svg>
                                </div>
                            </div>
                            <p className="mt-4 text-4xl font-bold text-white">0 <span className="text-sm font-normal text-gray-500">chats</span></p>
                        </div>

                        <div className="p-6 transition-all duration-300 rounded-xl bg-gradient-to-br from-gray-800 to-gray-900 border border-gray-700 hover:border-emerald-500 hover:shadow-[0_0_15px_rgba(16,185,129,0.5)]">
                            <div className="flex items-center justify-between">
                                <h4 className="text-lg font-semibold text-gray-400">Próximas Citas</h4>
                                <div className="p-2 bg-emerald-500/20 rounded-lg">
                                    <svg className="w-6 h-6 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                </div>
                            </div>
                            <p className="mt-4 text-4xl font-bold text-white">0 <span className="text-sm font-normal text-gray-500">hoy</span></p>
                        </div>
                    </div>

                    {/* Content Tabs area */}
                    <div className="p-6 rounded-2xl bg-gray-800/50 border border-gray-700">
                        <h4 className="text-xl font-medium text-white mb-4">Actividad Reciente</h4>
                        <div className="flex items-center justify-center h-48 border-2 border-dashed border-gray-600 rounded-xl">
                            <p className="text-gray-500">Ualdo está esperando órdenes o mensajes de clientes...</p>
                        </div>
                    </div>

                </div>
            </div>
        </AuthenticatedLayout>
    );
}
