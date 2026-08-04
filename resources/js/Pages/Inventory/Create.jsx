import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';

export default function Create({ supplies = [] }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        description: '',
        type: 'service',
        price: '',
        stock: '0',
        min_stock: '0',
        supplies: [],
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('inventory.store'));
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex justify-between items-center">
                    <h2 className="text-2xl font-bold leading-tight text-white">
                        Agregar al Inventario / Catálogo
                    </h2>
                    <Link href={route('inventory.index')} className="text-gray-400 hover:text-white transition-colors">
                        ← Volver al inventario
                    </Link>
                </div>
            }
        >
            <Head title="Agregar Producto/Servicio" />

            <div className="py-12 bg-gray-900 min-h-screen">
                <div className="mx-auto max-w-3xl sm:px-6 lg:px-8">
                    
                    <div className="bg-gray-800/50 backdrop-blur-lg border border-gray-700 overflow-hidden shadow-2xl sm:rounded-2xl p-8">
                        <form onSubmit={submit} className="space-y-6">
                            
                            <div>
                                <label className="block text-sm font-medium text-gray-300">Nombre del Ítem / Servicio</label>
                                <input
                                    type="text"
                                    value={data.name}
                                    onChange={e => setData('name', e.target.value)}
                                    placeholder="Ej: Consulta Odontológica, Limpieza Dental, Guantes Quirúrgicos..."
                                    className="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500"
                                    required
                                />
                                {errors.name && <p className="mt-1 text-sm text-red-400">{errors.name}</p>}
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-300">Descripción</label>
                                <textarea
                                    value={data.description}
                                    onChange={e => setData('description', e.target.value)}
                                    placeholder="Detalles sobre el procedimiento, especificaciones del insumo..."
                                    className="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500"
                                    rows="3"
                                />
                            </div>

                            <div className="grid grid-cols-2 gap-6">
                                <div>
                                    <label className="block text-sm font-medium text-gray-300">Tipo de Ítem</label>
                                    <select
                                        value={data.type}
                                        onChange={e => setData('type', e.target.value)}
                                        className="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500"
                                    >
                                        <option value="service">🩺 Servicio / Tratamiento</option>
                                        <option value="physical">📦 Insumo / Producto Físico</option>
                                        <option value="digital">💻 Digital / Recurso</option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label className="block text-sm font-medium text-gray-300">Precio ($)</label>
                                    <div className="relative mt-1">
                                        <div className="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-400">
                                            $
                                        </div>
                                        <input
                                            type="number"
                                            step="0.01"
                                            value={data.price}
                                            onChange={e => setData('price', e.target.value)}
                                            className="block w-full pl-7 rounded-md border-gray-600 bg-gray-700 text-white shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500"
                                            required
                                        />
                                    </div>
                                    {errors.price && <p className="mt-1 text-sm text-red-400">{errors.price}</p>}
                                </div>
                            </div>

                            {data.type !== 'service' && (
                                <div className="grid grid-cols-2 gap-6">
                                    <div>
                                        <label className="block text-sm font-medium text-gray-300">Stock Actual</label>
                                        <input
                                            type="number"
                                            value={data.stock}
                                            onChange={e => setData('stock', e.target.value)}
                                            className="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500"
                                            required
                                        />
                                        {errors.stock && <p className="mt-1 text-sm text-red-400">{errors.stock}</p>}
                                    </div>

                                    <div>
                                        <label className="block text-sm font-medium text-gray-300">Stock Mínimo (Alerta)</label>
                                        <input
                                            type="number"
                                            value={data.min_stock}
                                            onChange={e => setData('min_stock', e.target.value)}
                                            className="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500"
                                        />
                                        {errors.min_stock && <p className="mt-1 text-sm text-red-400">{errors.min_stock}</p>}
                                    </div>
                                </div>
                            )}

                            <div className="pt-4 border-t border-gray-700 flex justify-end">
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-lg shadow-[0_0_15px_rgba(59,130,246,0.5)] transition-all disabled:opacity-50"
                                >
                                    Guardar Ítem
                                </button>
                            </div>

                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
