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
    id: 'dashboard' | 'tickets' | 'work-orders' | 'customers' | 'assets' | 'invoices' | 'users' | 'preview';
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

export type WorkOrderSiteOption = {
    id: number;
    site_name: string;
    customer_name: string | null;
    assets: { id: number; asset_code: string; category: string }[];
};

export type WorkOrderFormData = {
    command_key?: string;
    origin_kind?: 'ticket' | 'manual';
    ticket_id?: number | null;
    expected_ticket_version?: number | null;
    manual_reason?: string;
    initial_scope?: { description: string; acceptance_criteria: string };
    customer_site_id: string;
    title: string;
    description: string;
    service_type: string;
    category: string;
    priority: string;
    scheduled_at: string;
    asset_ids: number[];
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

export type VisitWorkOrder = {
    id: number;
    number: string;
    title: string;
    status: string;
    customer: string | null;
    site: string | null;
};

export type BookingRecord = {
    id: number;
    technician_name: string;
    status: string;
    ended_reason: string | null;
    cancel_url: string | null;
    command_key: string | null;
};

export type VisitRecord = {
    id: number;
    visit_no: number;
    starts_at: string;
    ends_at: string;
    zone_name: string;
    status: string;
    reason: string | null;
    previous_visit_no: number | null;
    lock_version: number;
    confirm_url: string | null;
    command_key: string | null;
    bookings: BookingRecord[];
};

export type VisitIndexProps = {
    workOrder: VisitWorkOrder;
    members: UserSummary[];
    technicians: UserSummary[];
    visits: Paginated<VisitRecord>;
    unavailableReason: string | null;
    errors?: Record<string, string | Record<string, string>>;
    links: { index: string; refresh: string; create: string | null };
};

export type VisitCreateProps = {
    workOrder: VisitWorkOrder;
    commandKey: string;
    links: { index: string; store: string };
};
