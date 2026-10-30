const WelcomePage = () => import('./pages/WelcomePage.vue');
const LoginPage = () => import('./pages/LoginPage.vue');
const TrackPage = () => import('./pages/TrackPage.vue');
const AppointmentBookingPage = () => import('./pages/AppointmentBookingPage.vue');
const ShopProfilePage = () => import('./pages/ShopProfilePage.vue');
const ShopOnboardingPage = () => import('./pages/ShopOnboardingPage.vue');
const AdminDashboardPage = () => import('./pages/admin/AdminDashboardPage.vue');
const JobOrdersPage = () => import('./pages/admin/JobOrdersPage.vue');
const JobOrderEditPage = () => import('./pages/admin/JobOrderEditPage.vue');
const JobOrderInvoicePage = () => import('./pages/admin/JobOrderInvoicePage.vue');
const JobOrderPrintPage = () => import('./pages/admin/JobOrderPrintPage.vue');
const JobOrderShowPage = () => import('./pages/admin/JobOrderShowPage.vue');
const JobOrderTrashPage = () => import('./pages/admin/JobOrderTrashPage.vue');
const PartRequestsPage = () => import('./pages/admin/PartRequestsPage.vue');
const AppointmentsPage = () => import('./pages/admin/AppointmentsPage.vue');
const ReportsPage = () => import('./pages/admin/ReportsPage.vue');
const FailedJobsPage = () => import('./pages/platform/FailedJobsPage.vue');
const AuditLogsPage = () => import('./pages/admin/AuditLogsPage.vue');
const InventoryPage = () => import('./pages/admin/InventoryPage.vue');
const TechniciansPage = () => import('./pages/admin/TechniciansPage.vue');
const TechnicianCreatePage = () => import('./pages/admin/TechnicianCreatePage.vue');
const TechnicianEditPage = () => import('./pages/admin/TechnicianEditPage.vue');
const TechnicianShowPage = () => import('./pages/admin/TechnicianShowPage.vue');
const UsersPage = () => import('./pages/admin/UsersPage.vue');
const UserEditPage = () => import('./pages/admin/UserEditPage.vue');
const ShopSettingsPage = () => import('./pages/admin/ShopSettingsPage.vue');
const PlatformDashboardPage = () => import('./pages/platform/PlatformDashboardPage.vue');
const PlatformSubscriptionRequestsPage = () => import('./pages/platform/PlatformSubscriptionRequestsPage.vue');
const PlatformSettingsPage = () => import('./pages/platform/PlatformSettingsPage.vue');
const PlatformAuditLogsPage = () => import('./pages/platform/PlatformAuditLogsPage.vue');
const TechnicianDashboardPage = () => import('./pages/technician/TechnicianDashboardPage.vue');
const TechnicianJobOrderPage = () => import('./pages/technician/TechnicianJobOrderPage.vue');
const NotFoundPage = () => import('./pages/NotFoundPage.vue');

export const resolveRoute = (path) => {
    if (path === '/') {
        return { component: WelcomePage, params: {}, title: 'Welcome' };
    }

    if (path === '/login') {
        return { component: LoginPage, params: {}, title: 'Login' };
    }

    if (path === '/get-started') {
        return { component: ShopOnboardingPage, params: {}, title: 'Launch Your Shop' };
    }

    if (path === '/track') {
        return { component: TrackPage, params: {}, title: 'Track Repair' };
    }

    if (path === '/book') {
        return { component: AppointmentBookingPage, params: {}, title: 'Book Drop-off' };
    }

    if (path === '/consultation') {
        return { component: AppointmentBookingPage, params: { mode: 'consultation' }, title: 'Free Consultation' };
    }

    if (path.startsWith('/shops/')) {
        const segments = path.split('/').filter(Boolean);
        if (segments.length === 2) {
            const slug = decodeURIComponent(segments[1]);
            return { component: ShopProfilePage, params: { slug }, title: 'Repair Shop' };
        }

        if (segments.length === 3 && segments[2] === 'consultation') {
            const shopSlug = decodeURIComponent(segments[1]);
            return {
                component: AppointmentBookingPage,
                params: { shopSlug, mode: 'consultation' },
                title: 'Free Consultation',
            };
        }

        if (segments.length === 3 && segments[2] === 'book') {
            const shopSlug = decodeURIComponent(segments[1]);
            return {
                component: AppointmentBookingPage,
                params: { shopSlug },
                title: 'Book Drop-off',
            };
        }

        if (segments.length >= 3 && segments[2] === 'track') {
            const shopSlug = decodeURIComponent(segments[1]);
            const jobOrderNumber = segments[3] ? decodeURIComponent(segments[3]) : '';
            return {
                component: TrackPage,
                params: { shopSlug, jobOrderNumber },
                title: 'Track Repair',
            };
        }
    }

    if (path === '/technician') {
        return { component: TechnicianDashboardPage, params: {}, title: 'My Jobs' };
    }

    if (path === '/platform') {
        return { component: PlatformDashboardPage, params: {}, title: 'Dashboard' };
    }

    if (path === '/platform/shops') {
        return { component: PlatformDashboardPage, params: { view: 'shops' }, title: 'Shops' };
    }

    if (path === '/platform/subscription-requests') {
        return { component: PlatformSubscriptionRequestsPage, params: {}, title: 'Subscription Requests' };
    }

    if (path === '/platform/failed-jobs') {
        return { component: FailedJobsPage, params: {}, title: 'Failed Jobs' };
    }

    if (path === '/platform/audit-logs') {
        return { component: PlatformAuditLogsPage, params: {}, title: 'Audit Logs' };
    }

    if (path === '/platform/settings') {
        return { component: PlatformSettingsPage, params: {}, title: 'Platform Settings' };
    }

    if (path.startsWith('/technician/job-orders/')) {
        const id = path.replace('/technician/job-orders/', '');
        if (id) {
            return { component: TechnicianJobOrderPage, params: { id }, title: `Assigned Job ${id}` };
        }
    }

    if (path.startsWith('/track/')) {
        const jobOrderNumber = decodeURIComponent(path.replace('/track/', ''));
        return { component: TrackPage, params: { jobOrderNumber }, title: 'Track Repair' };
    }

    if (path === '/admin') {
        return { component: AdminDashboardPage, params: {}, title: 'Dashboard' };
    }

    if (path === '/admin/job-orders') {
        return { component: JobOrdersPage, params: {}, title: 'Job Orders' };
    }

    if (path === '/admin/part-requests') {
        return { component: PartRequestsPage, params: {}, title: 'For Order' };
    }

    if (path === '/admin/appointments') {
        return { component: AppointmentsPage, params: {}, title: 'Customer Requests' };
    }

    if (path === '/admin/job-orders/create') {
        return { component: JobOrdersPage, params: { openCreate: true }, title: 'Job Orders' };
    }

    if (path === '/admin/job-orders/trash') {
        return { component: JobOrderTrashPage, params: {}, title: 'Trashed Job Orders' };
    }

    if (path.startsWith('/admin/job-orders/')) {
        const id = path.replace('/admin/job-orders/', '');
        if (id.endsWith('/invoice')) {
            return {
                component: JobOrderInvoicePage,
                params: { id: id.replace('/invoice', '') },
                title: 'Invoice',
            };
        }
        if (id.endsWith('/print/intake')) {
            return {
                component: JobOrderPrintPage,
                params: { id: id.replace('/print/intake', ''), mode: 'intake' },
                title: 'Claim Stub and Job Order Slip',
            };
        }
        if (id.endsWith('/print/warranty')) {
            return {
                component: JobOrderPrintPage,
                params: { id: id.replace('/print/warranty', ''), mode: 'warranty' },
                title: 'Warranty Certificate',
            };
        }
        if (id.endsWith('/edit')) {
            return {
                component: JobOrderEditPage,
                params: { id: id.replace('/edit', '') },
                title: 'Edit Job Order',
            };
        }
        if (id) {
            return { component: JobOrderShowPage, params: { id }, title: `Job Order ${id}` };
        }
    }

    if (path === '/admin/reports') {
        return { component: ReportsPage, params: {}, title: 'Reports' };
    }

    if (path === '/admin/audit-logs') {
        return { component: AuditLogsPage, params: {}, title: 'Audit Logs' };
    }

    if (path === '/admin/inventory') {
        return { component: InventoryPage, params: {}, title: 'Inventory' };
    }

    if (path === '/admin/technicians') {
        return { component: TechniciansPage, params: {}, title: 'Technicians' };
    }

    if (path === '/admin/users') {
        return { component: UsersPage, params: {}, title: 'User Management' };
    }

    if (path === '/admin/settings/shop') {
        return { component: ShopSettingsPage, params: {}, title: 'Shop Settings' };
    }

    if (path.startsWith('/admin/users/')) {
        const id = path.replace('/admin/users/', '');
        if (id.endsWith('/edit')) {
            return {
                component: UserEditPage,
                params: { id: id.replace('/edit', '') },
                title: 'Edit User',
            };
        }
    }

    if (path === '/admin/technicians/create') {
        return { component: TechnicianCreatePage, params: {}, title: 'Add Technician' };
    }

    if (path.startsWith('/admin/technicians/')) {
        const id = path.replace('/admin/technicians/', '');
        if (id.endsWith('/edit')) {
            return {
                component: TechnicianEditPage,
                params: { id: id.replace('/edit', '') },
                title: 'Edit Technician',
            };
        }
        if (id) {
            return { component: TechnicianShowPage, params: { id }, title: 'Technician Details' };
        }
    }

    return { component: NotFoundPage, params: {}, title: 'Not Found' };
};
