import { Head, Link } from '@inertiajs/react';
import UaldoLogo from '@/Components/UaldoLogo';
import UaldoMascot from '@/Components/UaldoMascot';

function Feature({ emoji, title, children }) {
    return (
        <div className="group rounded-2xl border border-ualdo-turquesa/15 bg-white p-6 shadow-sm transition duration-300 hover:-translate-y-1 hover:shadow-lg hover:shadow-ualdo-turquesa/10">
            <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-br from-ualdo-turquesa to-ualdo-teal text-2xl text-white shadow-md">
                {emoji}
            </div>
            <h3 className="mb-2 text-lg font-semibold text-ualdo-tinta">{title}</h3>
            <p className="text-sm leading-relaxed text-ualdo-tinta/70">{children}</p>
        </div>
    );
}

export default function Welcome({ auth }) {
    return (
        <>
            <Head title="Ualdo — Tu negocio, atendido por IA" />

            <div className="min-h-screen bg-[#f7fbfb] font-sans text-ualdo-tinta antialiased">
                {/* Fondo decorativo */}
                <div className="pointer-events-none absolute inset-x-0 top-0 -z-0 h-[520px] overflow-hidden">
                    <div className="absolute -right-32 -top-40 h-[520px] w-[520px] rounded-full bg-ualdo-turquesa/20 blur-3xl" />
                    <div className="absolute -left-24 top-10 h-96 w-96 rounded-full bg-ualdo-teal/10 blur-3xl" />
                </div>

                <div className="relative z-10 mx-auto max-w-6xl px-6">
                    {/* Nav */}
                    <header className="flex items-center justify-between py-6">
                        <UaldoLogo variant="dark" className="h-9 w-auto" />
                        <nav className="flex items-center gap-2 sm:gap-4">
                            {auth?.user ? (
                                <Link
                                    href={route('dashboard')}
                                    className="rounded-full bg-ualdo-profundo px-5 py-2 text-sm font-semibold text-white transition hover:bg-ualdo-teal"
                                >
                                    Ir al panel
                                </Link>
                            ) : (
                                <>
                                    <Link
                                        href={route('login')}
                                        className="rounded-full px-4 py-2 text-sm font-semibold text-ualdo-profundo transition hover:bg-ualdo-turquesa/10"
                                    >
                                        Acceso
                                    </Link>
                                    <Link
                                        href={route('register')}
                                        className="rounded-full bg-ualdo-profundo px-5 py-2 text-sm font-semibold text-white shadow-md shadow-ualdo-profundo/20 transition hover:bg-ualdo-teal"
                                    >
                                        Registro
                                    </Link>
                                </>
                            )}
                        </nav>
                    </header>

                    {/* Hero */}
                    <section className="grid items-center gap-8 py-10 lg:grid-cols-2 lg:py-16">
                        <div className="text-center lg:text-left">
                            <span className="inline-flex items-center gap-2 rounded-full bg-ualdo-turquesa/15 px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-ualdo-profundo">
                                Recepcionista + gestor con IA
                            </span>
                            <h1 className="mt-5 text-4xl font-bold leading-tight text-ualdo-tinta sm:text-5xl">
                                Tu negocio,{' '}
                                <span className="bg-gradient-to-r from-ualdo-turquesa to-ualdo-profundo bg-clip-text text-transparent">
                                    atendido por Ualdo
                                </span>
                            </h1>
                            <p className="mx-auto mt-5 max-w-xl text-lg leading-relaxed text-ualdo-tinta/70 lg:mx-0">
                                Atiende a tus clientes por WhatsApp 24/7, agenda citas sin dobles
                                reservas, controla tu inventario y administra todo por chat.
                                Ualdo trabaja por ti mientras tú te enfocas en tu negocio.
                            </p>
                            <div className="mt-8 flex flex-col items-center gap-3 sm:flex-row lg:justify-start">
                                <Link
                                    href={auth?.user ? route('dashboard') : route('register')}
                                    className="w-full rounded-full bg-gradient-to-r from-ualdo-turquesa to-ualdo-teal px-7 py-3 text-center text-base font-semibold text-white shadow-lg shadow-ualdo-turquesa/30 transition hover:opacity-95 sm:w-auto"
                                >
                                    Empezar ahora
                                </Link>
                                <Link
                                    href={route('login')}
                                    className="w-full rounded-full border border-ualdo-profundo/20 px-7 py-3 text-center text-base font-semibold text-ualdo-profundo transition hover:bg-white sm:w-auto"
                                >
                                    Ya tengo cuenta
                                </Link>
                            </div>
                        </div>

                        <div className="flex justify-center lg:justify-end">
                            <UaldoMascot className="w-64 h-auto sm:w-80" />
                        </div>
                    </section>

                    {/* Features */}
                    <section className="py-8">
                        <h2 className="text-center text-2xl font-bold text-ualdo-tinta sm:text-3xl">
                            Todo tu negocio en un solo asistente
                        </h2>
                        <div className="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                            <Feature emoji="💬" title="Atención por WhatsApp">
                                Responde dudas, precios y disponibilidad al instante, todos los
                                días, sin que muevas un dedo.
                            </Feature>
                            <Feature emoji="📅" title="Agenda sin choques">
                                Agenda, reprograma y cancela citas o reservas con bloqueo
                                anti-doble-reserva garantizado.
                            </Feature>
                            <Feature emoji="📦" title="Inventario al día">
                                Controla stock, insumos y recetas; registra entradas y salidas
                                por chat y mantén todo sincronizado.
                            </Feature>
                            <Feature emoji="🧠" title="Administra por chat">
                                Consulta métricas, ajusta precios y asigna tareas a tu equipo
                                hablándole a Ualdo como a un asistente.
                            </Feature>
                        </div>
                    </section>

                    {/* CTA final */}
                    <section className="my-12">
                        <div className="relative overflow-hidden rounded-3xl bg-gradient-to-r from-ualdo-profundo to-ualdo-teal px-8 py-12 text-center shadow-xl">
                            <div className="absolute -right-10 -top-10 h-40 w-40 rounded-full bg-ualdo-turquesa/30 blur-2xl" />
                            <h2 className="relative text-2xl font-bold text-white sm:text-3xl">
                                ¿Listo para que Ualdo atienda tu negocio?
                            </h2>
                            <p className="relative mx-auto mt-3 max-w-lg text-white/80">
                                Configúralo en minutos y deja que la IA se encargue de la
                                recepción, la agenda y el inventario.
                            </p>
                            <Link
                                href={auth?.user ? route('dashboard') : route('register')}
                                className="relative mt-7 inline-block rounded-full bg-white px-8 py-3 text-base font-semibold text-ualdo-profundo shadow-lg transition hover:bg-ualdo-turquesa hover:text-white"
                            >
                                Crear mi cuenta
                            </Link>
                        </div>
                    </section>

                    {/* Footer */}
                    <footer className="flex flex-col items-center justify-between gap-4 border-t border-ualdo-tinta/10 py-8 text-sm text-ualdo-tinta/60 sm:flex-row">
                        <UaldoLogo variant="dark" className="h-7 w-auto opacity-80" />
                        <p>© {new Date().getFullYear()} Ualdo. Todos los derechos reservados.</p>
                    </footer>
                </div>
            </div>
        </>
    );
}
