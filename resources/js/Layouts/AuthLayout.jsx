import { Head, usePage } from '@inertiajs/react';

export default function AuthLayout({ titre, sousTitre, children }) {
    const { app } = usePage().props;

    return (
        <div className="flex min-h-screen items-center justify-center px-4 py-12">
            <Head title={titre} />
            <div className="w-full max-w-sm">
                <p className="mb-6 text-center text-lg font-semibold text-green-800">{app.nom}</p>
                <div className="rounded-lg bg-white p-6 shadow-sm ring-1 ring-slate-200">
                    <h1 className="text-lg font-semibold text-slate-900">{titre}</h1>
                    {sousTitre && <p className="mt-1 text-sm text-slate-600">{sousTitre}</p>}
                    <div className="mt-5">{children}</div>
                </div>
            </div>
        </div>
    );
}
