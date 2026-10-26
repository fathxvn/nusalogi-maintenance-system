export type UserSummary = {
    id: number;
    name: string;
};

export type UserRole = 'admin' | 'koordinator' | 'teknisi';

export type AuthenticatedUser = UserSummary & {
    role: UserRole | null;
};

export type NavigationItem = {
    id: 'dashboard' | 'work-orders' | 'customers' | 'assets' | 'invoices' | 'users' | 'preview';
    label: string;
    href: string | null;
    active: boolean;
    inertia: boolean;
};

export type SharedProps = {
    auth: {
        user: AuthenticatedUser | null;
    };
    navigation: NavigationItem[];
};
