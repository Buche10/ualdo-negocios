import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

export default function Index({ items }) {
    return (
        <AuthenticatedLayout
            header={
                <div className="flex justify-between items-center">
                    <h2 className="text-2xl font-bold leading-tight text-white">
                        Inventario Universal
                    </h2>
                    <Link href={route('inventory.create')} className="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg shadow-[0_0_15px_rgba(59,130,246,0.5)] transition-all">
                        + Agregar Producto
                    </Link>
                </div>
            }
        >
            <Head title="Inventario" />

            <div className="py-12 bg-gray-900 min-h-screen">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    
                    <div className="bg-gray-800/50 backdrop-blur-lg border border-gray-700 overflow-hidden shadow-sm sm:rounded-2xl">
                        <div className="p-6">
                            {items.length === 0 ? (
                                <div className="text-center py-10">
                                    <p className="text-gray-400">No hay ítems en el inventario.</p>
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left border-collapse">
                                        <thead>
                                            <tr>
                                                <th className="py-4 px-6 bg-gray-800 font-bold uppercase text-sm text-gray-300 border-b border-gray-700">Nombre</th>
                                                <th className="py-4 px-6 bg-gray-800 font-bold uppercase text-sm text-gray-300 border-b border-gray-700">Tipo</th>
                                                <th className="py-4 px-6 bg-gray-800 font-bold uppercase text-sm text-gray-300 border-b border-gray-700">Precio</th>
                                                <th className="py-4 px-6 bg-gray-800 font-bold uppercase text-sm text-gray-300 border-b border-gray-700">Stock</th>
                                                <th className="py-4 px-6 bg-gray-800 font-bold uppercase text-sm text-gray-300 border-b border-gray-700 text-right">Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {items.map((item) => (
                                                <tr key={item.id} className="hover:bg-gray-700/50 transition-colors">
                                                    <td className="py-4 px-6 border-b border-gray-700 text-white font-medium">{item.name}</td>
                                                    <td className="py-4 px-6 border-b border-gray-700 text-gray-300">
                                                        <span className="px-2 py-1 bg-gray-700 rounded-md text-xs">{item.type}</span>
                                                    </td>
                                                    <td className="py-4 px-6 border-b border-gray-700 text-gray-300">${item.price}</td>
                                                    <td className="py-4 px-6 border-b border-gray-700 text-gray-300">{item.stock}</td>
                                                    <td className="py-4 px-6 border-b border-gray-700 text-right">
                                                        <Link href={route('inventory.destroy', item.id)} method="delete" as="button" className="text-red-400 hover:text-red-300 text-sm font-semibold transition-colors">
                                                            Eliminar
                                                        </Link>
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
            </div>
        </AuthenticatedLayout>
    );
}
