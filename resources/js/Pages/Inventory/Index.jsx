import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ items = [], lowStockItems = [] }) {
    const [filterType, setFilterType] = useState('all');

    const filteredItems = items.filter(item => {
        if (filterType === 'services') return item.type === 'service';
        if (filterType === 'supplies') return item.type !== 'service';
        return true;
    });

    return (
        <AuthenticatedLayout
            header={
                <div className="flex justify-between items-center">
                    <div>
                        <h2 className="text-2xl font-bold leading-tight text-white">
                            Gestión de Inventario & Catálogo
                        </h2>
                        <p className="text-sm text-gray-400">Servicios, tratamientos, productos e insumos médicos</p>
                    </div>
                    <Link href={route('inventory.create')} className="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg shadow-[0_0_15px_rgba(59,130,246,0.5)] transition-all">
                        + Nuevo Ítem / Servicio
                    </Link>
                </div>
            }
        >
            <Head title="Inventario" />

            <div className="py-12 bg-gray-900 min-h-screen">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">

                    {/* Low Stock Warning Banner */}
                    {lowStockItems.length > 0 && (
                        <div className="bg-amber-950/60 border border-amber-500/50 rounded-2xl p-4 text-amber-200 backdrop-blur-md">
                            <div className="flex items-center space-x-3">
                                <span className="text-2xl">⚠️</span>
                                <div>
                                    <h4 className="font-semibold text-amber-100">Alerta de Stock Bajo ({lowStockItems.length} insumos)</h4>
                                    <p className="text-sm text-amber-300">
                                        Los siguientes productos están en o por debajo de su stock mínimo: {lowStockItems.map(i => `${i.name} (${i.stock}/${i.min_stock})`).join(', ')}
                                    </p>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Filter Tabs */}
                    <div className="flex space-x-2 border-b border-gray-700 pb-2">
                        <button
                            onClick={() => setFilterType('all')}
                            className={`px-4 py-2 text-sm font-medium rounded-lg transition-colors ${filterType === 'all' ? 'bg-blue-600 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800'}`}
                        >
                            Todos ({items.length})
                        </button>
                        <button
                            onClick={() => setFilterType('services')}
                            className={`px-4 py-2 text-sm font-medium rounded-lg transition-colors ${filterType === 'services' ? 'bg-blue-600 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800'}`}
                        >
                            🩺 Servicios & Tratamientos ({items.filter(i => i.type === 'service').length})
                        </button>
                        <button
                            onClick={() => setFilterType('supplies')}
                            className={`px-4 py-2 text-sm font-medium rounded-lg transition-colors ${filterType === 'supplies' ? 'bg-blue-600 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800'}`}
                        >
                            📦 Productos e Insumos ({items.filter(i => i.type !== 'service').length})
                        </button>
                    </div>

                    <div className="bg-gray-800/50 backdrop-blur-lg border border-gray-700 overflow-hidden shadow-sm sm:rounded-2xl">
                        <div className="p-6">
                            {filteredItems.length === 0 ? (
                                <div className="text-center py-10">
                                    <p className="text-gray-400">No se encontraron ítems en esta categoría.</p>
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left border-collapse">
                                        <thead>
                                            <tr>
                                                <th className="py-4 px-6 bg-gray-800 font-bold uppercase text-sm text-gray-300 border-b border-gray-700">Nombre</th>
                                                <th className="py-4 px-6 bg-gray-800 font-bold uppercase text-sm text-gray-300 border-b border-gray-700">Tipo</th>
                                                <th className="py-4 px-6 bg-gray-800 font-bold uppercase text-sm text-gray-300 border-b border-gray-700">Precio</th>
                                                <th className="py-4 px-6 bg-gray-800 font-bold uppercase text-sm text-gray-300 border-b border-gray-700">Stock / Mínimo</th>
                                                <th className="py-4 px-6 bg-gray-800 font-bold uppercase text-sm text-gray-300 border-b border-gray-700 text-right">Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {filteredItems.map((item) => {
                                                const isLow = item.type !== 'service' && item.stock <= (item.min_stock || 0);
                                                return (
                                                    <tr key={item.id} className="hover:bg-gray-700/50 transition-colors">
                                                        <td className="py-4 px-6 border-b border-gray-700 text-white font-medium">
                                                            <div>{item.name}</div>
                                                            {item.description && <div className="text-xs text-gray-400">{item.description}</div>}
                                                        </td>
                                                        <td className="py-4 px-6 border-b border-gray-700 text-gray-300">
                                                            <span className={`px-2 py-1 rounded-md text-xs font-semibold ${item.type === 'service' ? 'bg-purple-900/60 text-purple-300 border border-purple-700' : 'bg-blue-900/60 text-blue-300 border border-blue-700'}`}>
                                                                {item.type === 'service' ? 'Servicio / Tratamiento' : item.type}
                                                            </span>
                                                        </td>
                                                        <td className="py-4 px-6 border-b border-gray-700 text-emerald-400 font-bold">${item.price}</td>
                                                        <td className="py-4 px-6 border-b border-gray-700 text-gray-300">
                                                            {item.type === 'service' ? (
                                                                <span className="text-gray-500 text-sm">N/A (Servicio)</span>
                                                            ) : (
                                                                <span className={`font-semibold ${isLow ? 'text-red-400' : 'text-emerald-400'}`}>
                                                                    {item.stock} / {item.min_stock || 0} {isLow && '⚠️'}
                                                                </span>
                                                            )}
                                                        </td>
                                                        <td className="py-4 px-6 border-b border-gray-700 text-right">
                                                            <Link href={route('inventory.destroy', item.id)} method="delete" as="button" className="text-red-400 hover:text-red-300 text-sm font-semibold transition-colors">
                                                                Eliminar
                                                            </Link>
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
