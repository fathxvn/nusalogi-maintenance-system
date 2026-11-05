export type UserSummary = {
    id: number;
    name: string;
};

export type ApprovalStatus = 'pending' | 'approved' | 'rejected';

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
    flash: { success: string | null };
};

export type UserRecord = AuthenticatedUser & {
    email: string;
    is_active: boolean;
    approval_status: ApprovalStatus;
    created_at?: string | null;
    is_self?: boolean;
    links?: { edit: string; status: string };
};
export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type CustomerRecord = {
    id: number;
    name: string;
    pic_name: string | null;
    phone: string | null;
    email: string | null;
    billing_address: string | null;
};

export type CustomerFormData = {
    name: string;
    pic_name: string;
    phone: string;
    email: string;
    billing_address: string;
};

export type CustomerSiteRecord = {
    id: number;
    site_name: string;
    address: string;
    pic_name: string | null;
    pic_phone: string | null;
};

export type CustomerSiteFormData = {
    site_name: string;
    address: string;
    pic_name: string;
    pic_phone: string;
};
export type DashboardProps = {
    metrics: { active: number; today: number; customers: number; pendingInvoices: number };
    trend: { month: string; total: number }[];
    statuses: { status: string; total: number }[];
    recentWorkOrders: {
        id: number; number: string; title: string; date: string | null;
        customer: string | null; site: string | null; status: string; priority: string; technicians: string[];
    }[];
};

export type AssetRecord = {
    id: number;
    asset_code: string;
    category: string;
    brand: string | null;
    model: string | null;
    serial_number: string | null;
    status: string;
    installed_at: string | null;
    site: {
        id: number | null;
        site_name: string | null;
        customer_name: string | null;
    };
};

export type AssetSiteOption = {
    id: number;
    site_name: string;
    customer_name: string | null;
};

export type AssetFormData = {
    customer_site_id: string;
    asset_code: string;
    category: string;
    brand: string;
    model: string;
    serial_number: string;
    installed_at: string;
};
