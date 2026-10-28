import WelcomePage from './pages/WelcomePage.vue';
import LoginPage from './pages/LoginPage.vue';
import TrackPage from './pages/TrackPage.vue';
import AppointmentBookingPage from './pages/AppointmentBookingPage.vue';
import ShopOnboardingPage from './pages/ShopOnboardingPage.vue';
import AdminDashboardPage from './pages/admin/AdminDashboardPage.vue';
import JobOrdersPage from './pages/admin/JobOrdersPage.vue';
import JobOrderEditPage from './pages/admin/JobOrderEditPage.vue';
import JobOrderInvoicePage from './pages/admin/JobOrderInvoicePage.vue';
import JobOrderPrintPage from './pages/admin/JobOrderPrintPage.vue';
import JobOrderShowPage from './pages/admin/JobOrderShowPage.vue';
import JobOrderTrashPage from './pages/admin/JobOrderTrashPage.vue';
import PartRequestsPage from './pages/admin/PartRequestsPage.vue';
import AppointmentsPage from './pages/admin/AppointmentsPage.vue';
import ReportsPage from './pages/admin/ReportsPage.vue';
import FailedJobsPage from './pages/platform/FailedJobsPage.vue';
import AuditLogsPage from './pages/admin/AuditLogsPage.vue';
import InventoryPage from './pages/admin/InventoryPage.vue';
import TechniciansPage from './pages/admin/TechniciansPage.vue';
import TechnicianCreatePage from './pages/admin/TechnicianCreatePage.vue';
import TechnicianEditPage from './pages/admin/TechnicianEditPage.vue';
import TechnicianShowPage from './pages/admin/TechnicianShowPage.vue';
import UsersPage from './pages/admin/UsersPage.vue';
import UserEditPage from './pages/admin/UserEditPage.vue';
import ShopSettingsPage from './pages/admin/ShopSettingsPage.vue';
import PlatformDashboardPage from './pages/platform/PlatformDashboardPage.vue';
import PlatformSubscriptionRequestsPage from './pages/platform/PlatformSubscriptionRequestsPage.vue';
import PlatformSettingsPage from './pages/platform/PlatformSettingsPage.vue';
import PlatformAuditLogsPage from './pages/platform/PlatformAuditLogsPage.vue';
import TechnicianDashboardPage from './pages/technician/TechnicianDashboardPage.vue';
import TechnicianJobOrderPage from './pages/technician/TechnicianJobOrderPage.vue';
import NotFoundPage from './pages/NotFoundPage.vue';

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
                title: 'Claim Stab and Job Order Slip',
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
