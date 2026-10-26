type Props = {
    user: {
        id: number;
        name: string;
    };
};

export default function MigrationTest({ user }: Props) {
    return (
        <main className="min-h-screen bg-slate-50 p-8">
            <div className="mx-auto max-w-3xl rounded-lg border border-slate-200 bg-white p-8">
                <p className="text-sm text-slate-500">
                    Nusalogi Maintenance System
                </p>

                <h1 className="mt-3 text-2xl font-semibold text-slate-900">
                    React + Inertia Migration
                </h1>

                <p className="mt-4 text-slate-600">
                    Selamat datang, {user.name}.
                </p>

                <p className="mt-2 text-sm text-slate-500">
                    Halaman ini dirender menggunakan React.
                </p>
            </div>
        </main>
    );
}