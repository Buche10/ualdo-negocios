import UaldoLogo from '@/Components/UaldoLogo';
import { Link } from '@inertiajs/react';

export default function GuestLayout({ children }) {
    return (
        <div className="flex min-h-screen flex-col items-center bg-gradient-to-br from-ualdo-profundo via-ualdo-tinta to-black pt-6 sm:justify-center sm:pt-0 font-sans">
            <div>
                <Link href="/">
                    <UaldoLogo variant="light" className="h-16 w-auto" />
                </Link>
            </div>

            <div className="mt-6 w-full overflow-hidden bg-ualdo-tinta/80 border border-ualdo-teal/30 px-6 py-6 shadow-2xl backdrop-blur-xl sm:max-w-md sm:rounded-3xl text-white">
                {children}
            </div>
        </div>
    );
}
