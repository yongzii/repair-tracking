<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import PlatformLayout from '../../layouts/PlatformLayout.vue';
import AppLink from '../../components/AppLink.vue';
import { ensurePlatformAdmin } from '../../composables/auth';
import { navigate } from '../../navigation';

const props = defineProps({
    params: {
        type: Object,
        default: () => ({}),
    },
});

const loading = ref(true);
const forbidden = ref(false);
const dashboard = ref(null);
const shops = ref(null);
const plans = ref([]);
const errorMessage = ref('');
const successMessage = ref('');
const temporaryPassword = ref(null);
const pendingSubscriptionCount = ref(null);
const failedJobsCount = ref(null);

const filters = reactive({
    search: '',
    is_active: '',
    access_status: '',
    subscription_plan: '',
});

const editForms = reactive({});
const editingShopIds = ref(new Set());
const billingShopIds = ref(new Set());
const billingForms = reactive({});
const showShops = computed(() => props.params.view === 'shops');
const formatCurrency = (value) => {
    const amount = Number(value || 0);
    return `PHP ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

const todayDate = () => new Date().toISOString().slice(0, 10);
const billingPresets = [
    { label: '1 Month', months: 1 },
    { label: '3 Months', months: 3 },
    { label: '6 Months', months: 6 },
    { label: '1 Year', months: 12 },
];

const dateFromInput = (value) => {
    if (!value) return null;
    const [year, month, day] = String(value).slice(0, 10).split('-').map(Number);
    if (!year || !month || !day) return null;
    return new Date(year, month - 1, day);
};

const inputDate = (date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
};

const addMonths = (date, months) => {
    const next = new Date(date);
    const originalDay = next.getDate();
    next.setMonth(next.getMonth() + months);
    if (next.getDate() !== originalDay) {
        next.setDate(0);
    }
    return next;
};

const accessBadgeClass = (shop) => {
    return {
        active: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        expiring: 'bg-amber-50 text-amber-700 ring-amber-200',
        close_out: 'bg-orange-50 text-orange-700 ring-orange-200',
        needs_setup: 'bg-sky-50 text-sky-700 ring-sky-200',
        inactive: 'bg-rose-50 text-rose-700 ring-rose-200',
    }[shop.access_status] || 'bg-slate-50 text-slate-600 ring-slate-200';
};

const paymentActionLabel = (shop) => {
    if (isBillingShop(shop)) return 'Hide Billing';
    if (shop.access_status === 'needs_setup') return 'Set Up Access';
    if (shop.access_status === 'close_out') return 'Renew Access';
    if (shop.access_status === 'expiring') return 'Extend Access';
    return 'Record Payment';
};

const applyBillingPreset = (shop, months) => {
    const form = billingForms[shop.id];
    if (!form) return;

    const today = dateFromInput(todayDate());
    const currentAccess = dateFromInput(shop.trial_ends_at);
    const startsAfterCurrentAccess = currentAccess && currentAccess >= today;
    const start = startsAfterCurrentAccess ? new Date(currentAccess) : today;
    if (startsAfterCurrentAccess) {
        start.setDate(start.getDate() + 1);
    }
    const end = addMonths(start, months);
    end.setDate(end.getDate() - 1);

    form.covered_from = inputDate(start);
    form.covered_until = inputDate(end);
};

const summaryCards = computed(() => {
    const summary = dashboard.value?.summary;
    if (!summary) return [];

    return [
        { label: 'Total Shops', value: summary.total_shops, hint: 'Registered workspaces' },
        { label: 'Active Shops', value: summary.active_shops, hint: 'Can accept users and requests' },
        { label: 'Inactive Shops', value: summary.inactive_shops, hint: 'Blocked from shop login' },
        { label: 'Active Access', value: summary.trial_shops, hint: 'Covered workspaces' },
    ];
});

const heroStats = computed(() => {
    const summary = dashboard.value?.summary;
    if (!summary) return [];

    return [
        { label: 'Total Shops', value: summary.total_shops, hint: 'Registered workspaces', icon: 'fa-store', tone: 'teal' },
        {
            label: 'Pending Subscription Requests',
            value: pendingSubscriptionCount.value === null ? '—' : pendingSubscriptionCount.value,
            hint: 'Awaiting platform review',
            icon: 'fa-file-invoice',
            tone: 'blue',
        },
        {
            label: 'Failed Jobs',
            value: failedJobsCount.value === null ? '—' : failedJobsCount.value,
            hint: 'Queue records needing attention',
            icon: 'fa-triangle-exclamation',
            tone: 'orange',
        },
        { label: 'Active Shops', value: summary.active_shops, hint: 'Enabled workspaces', icon: 'fa-circle-check', tone: 'purple' },
    ];
});

const overviewSections = computed(() => {
    const summary = dashboard.value?.summary;
    if (!summary) return [];

    return [
        { label: 'Active Access', value: summary.trial_shops, hint: 'Can accept new work', tone: 'emerald' },
        { label: 'Expiring Soon', value: summary.trial_expiring_soon, hint: 'Ending within 7 days', tone: 'amber' },
        { label: 'Close-out', value: summary.expired_trials, hint: 'Expired, existing jobs only', tone: 'orange' },
        { label: 'Needs Setup', value: summary.no_access_date_shops, hint: 'New intake locked until coverage is recorded', tone: 'sky' },
        { label: 'Inactive', value: summary.inactive_shops, hint: 'Workspaces disabled for sign-in', tone: 'rose' },
    ];
});

const reminderStatusClass = (status) => {
    return {
        sent: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        queued: 'bg-amber-50 text-amber-700 ring-amber-200',
        failed: 'bg-rose-50 text-rose-700 ring-rose-200',
    }[status] || 'bg-slate-50 text-slate-600 ring-slate-200';
};

const ensureAccess = async () => {
    const auth = await ensurePlatformAdmin();
    if (!auth.ok) {
        if (auth.reason === 'unauthenticated') {
            navigate('/login', { replace: true });
            return false;
        }
        forbidden.value = true;
        loading.value = false;
        return false;
    }

    return true;
};

const prepareForms = (items) => {
    items.forEach((shop) => {
        editForms[shop.id] = {
            subscription_plan: shop.subscription_plan || 'starter',
            trial_ends_at: shop.trial_ends_at ? String(shop.trial_ends_at).slice(0, 10) : '',
            is_active: shop.is_active,
        };
        if (!billingForms[shop.id]) {
            billingForms[shop.id] = {
                amount: '',
                payment_method: 'CASH',
                reference_number: '',
                covered_from: '',
                covered_until: '',
                received_at: todayDate(),
                notes: '',
            };
        }
    });
};

const isEditingShop = (shop) => editingShopIds.value.has(shop.id);

const setShopEditing = (shop, editing) => {
    const next = new Set(editingShopIds.value);
    if (editing) {
        next.add(shop.id);
        editForms[shop.id] = {
            subscription_plan: shop.subscription_plan || 'starter',
            trial_ends_at: shop.trial_ends_at ? String(shop.trial_ends_at).slice(0, 10) : '',
            is_active: shop.is_active,
        };
    } else {
        next.delete(shop.id);
    }
    editingShopIds.value = next;
};

const isBillingShop = (shop) => billingShopIds.value.has(shop.id);

const setShopBilling = (shop, open) => {
    const next = new Set(billingShopIds.value);
    if (open) {
        next.add(shop.id);
        if (!billingForms[shop.id]) {
            billingForms[shop.id] = {
                amount: '',
                payment_method: 'CASH',
                reference_number: '',
                covered_from: '',
                covered_until: '',
                received_at: todayDate(),
                notes: '',
            };
        }
    } else {
        next.delete(shop.id);
    }
    billingShopIds.value = next;
};

const loadDashboard = async () => {
    if (!(await ensureAccess())) return;

    try {
        const { data } = await window.axios.get('/api/platform/dashboard');
        dashboard.value = data;
        prepareForms(data.recent_shops || []);
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Unable to load platform dashboard.';
    }
};

const loadShops = async (page = 1) => {
    if (!(await ensureAccess())) return;
    errorMessage.value = '';

    try {
        const { data } = await window.axios.get('/api/platform/shops', {
            params: {
                page,
                search: filters.search || undefined,
                is_active: filters.is_active === '' ? undefined : filters.is_active,
                access_status: filters.access_status || undefined,
                subscription_plan: filters.subscription_plan || undefined,
            },
        });
        shops.value = data.shops;
        plans.value = data.plans || [];
        prepareForms(data.shops?.data || []);
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Unable to load shop workspaces.';
    }
};

const loadPendingSubscriptionCount = async () => {
    if (!(await ensureAccess())) return;

    try {
        const { data } = await window.axios.get('/api/platform/subscription-requests', {
            params: { status: 'pending', per_page: 1 },
        });
        pendingSubscriptionCount.value = Number(data.subscription_requests?.total ?? 0);
    } catch (error) {
        pendingSubscriptionCount.value = null;
        errorMessage.value = error?.response?.data?.message || 'Unable to load pending subscription count.';
    }
};

const loadFailedJobsCount = async () => {
    if (!(await ensureAccess())) return;

    try {
        const { data } = await window.axios.get('/api/platform/failed-jobs', {
            params: { per_page: 1 },
        });
        failedJobsCount.value = Number(data.failedJobs?.total ?? 0);
    } catch (error) {
        failedJobsCount.value = null;
        errorMessage.value = error?.response?.data?.message || 'Unable to load failed job count.';
    }
};

const refresh = async () => {
    loading.value = true;
    errorMessage.value = '';
    successMessage.value = '';
    temporaryPassword.value = null;

    if (showShops.value) {
        await loadDashboard();
        await loadShops();
    } else {
        await loadDashboard();
        await loadPendingSubscriptionCount();
        await loadFailedJobsCount();
    }

    loading.value = false;
};

const updateShop = async (shop) => {
    errorMessage.value = '';
    successMessage.value = '';
    temporaryPassword.value = null;

    try {
        const form = editForms[shop.id];
        const { data } = await window.axios.put(`/api/platform/shops/${shop.id}`, {
            subscription_plan: form.subscription_plan,
            trial_ends_at: form.trial_ends_at || null,
            is_active: form.is_active,
        });
        successMessage.value = data.message || 'Shop workspace updated.';
        setShopEditing(shop, false);
        await loadDashboard();
        if (showShops.value) {
            await loadShops(shops.value?.current_page || 1);
        }
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to update shop workspace.';
    }
};

const toggleShop = async (shop) => {
    errorMessage.value = '';
    successMessage.value = '';

    try {
        const { data } = await window.axios.post(`/api/platform/shops/${shop.id}/toggle`, {
            is_active: !shop.is_active,
        });
        successMessage.value = data.message || 'Shop status updated.';
        await loadDashboard();
        if (showShops.value) {
            await loadShops(shops.value?.current_page || 1);
        }
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Unable to update shop status.';
    }
};

const resetShopAdminPassword = async (shop) => {
    if (!window.confirm(`Reset the admin password for ${shop.name}?`)) return;

    errorMessage.value = '';
    successMessage.value = '';
    temporaryPassword.value = null;

    try {
        const { data } = await window.axios.post(`/api/platform/shops/${shop.id}/reset-admin-password`);
        successMessage.value = data.message || 'Shop admin password reset.';
        temporaryPassword.value = {
            shop: shop.name,
            admin: data.admin,
            password: data.temporary_password,
        };
        await loadDashboard();
        if (showShops.value) {
            await loadShops(shops.value?.current_page || 1);
        }
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to reset shop admin password.';
    }
};

const recordSubscriptionPayment = async (shop) => {
    errorMessage.value = '';
    successMessage.value = '';
    temporaryPassword.value = null;

    try {
        const form = billingForms[shop.id];
        const { data } = await window.axios.post(`/api/platform/shops/${shop.id}/subscription-payments`, {
            amount: Number(form.amount || 0),
            payment_method: form.payment_method || null,
            reference_number: form.reference_number || null,
            covered_from: form.covered_from || null,
            covered_until: form.covered_until || null,
            received_at: form.received_at || null,
            notes: form.notes || null,
        });
        successMessage.value = data.message || 'Subscription payment recorded.';
        billingForms[shop.id] = {
            amount: '',
            payment_method: 'CASH',
            reference_number: '',
            covered_from: '',
            covered_until: '',
            received_at: todayDate(),
            notes: '',
        };
        setShopBilling(shop, false);
        await loadDashboard();
        if (showShops.value) {
            await loadShops(shops.value?.current_page || 1);
        }
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to record subscription payment.';
    }
};

const clearFilters = () => {
    filters.search = '';
    filters.is_active = '';
    filters.access_status = '';
    filters.subscription_plan = '';
    loadShops(1);
};

const gotoPage = (url) => {
    if (!url) return;
    const parsed = new URL(url, window.location.origin);
    const page = Number(new URLSearchParams(parsed.search).get('page') || 1);
    loadShops(page);
};

onMounted(refresh);
</script>

<template>
  <PlatformLayout :title="showShops ? 'Shops' : 'Dashboard'">
    <div v-if="loading && !showShops" class="space-y-6" role="status" aria-label="Loading platform overview">
      <div class="animate-pulse rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
        <div class="h-3 w-32 rounded bg-slate-200"></div>
        <div class="mt-4 h-8 w-64 max-w-full rounded bg-slate-200"></div>
        <div class="mt-3 h-4 w-full max-w-xl rounded bg-slate-100"></div>
        <div class="mt-5 flex flex-wrap gap-3">
          <div class="h-10 w-36 rounded-lg bg-slate-100"></div>
          <div class="h-10 w-36 rounded-lg bg-slate-100"></div>
        </div>
      </div>
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-5">
        <div v-for="item in 4" :key="item" class="animate-pulse rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
          <div class="h-3 w-28 rounded bg-slate-200"></div>
          <div class="mt-4 h-8 w-24 rounded bg-slate-100"></div>
          <div class="mt-3 h-3 w-36 max-w-full rounded bg-slate-100"></div>
        </div>
      </div>
      <span class="sr-only">Loading platform overview...</span>
    </div>
    <div v-else-if="loading" class="card p-6 text-slate-600">Loading platform console...</div>
    <div v-else-if="forbidden" class="card border border-rose-200 bg-rose-50 p-6 text-rose-700">Only platform admin accounts can access this page.</div>
    <section v-else-if="!showShops && !dashboard" class="platform-card p-6 sm:p-8" role="alert">
      <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-rose-50 text-rose-600" aria-hidden="true"><i class="fas fa-triangle-exclamation"></i></span>
      <h3 class="mt-4 text-lg font-bold text-slate-950">Dashboard unavailable</h3>
      <p class="mt-1 max-w-xl text-sm leading-6 text-slate-600">{{ errorMessage || 'The platform dashboard could not be loaded. Please try again.' }}</p>
      <button type="button" class="btn-soft mt-4 min-h-10 px-4 py-2 text-sm" @click="refresh">Try again</button>
    </section>

    <div v-else :class="showShops ? 'space-y-6' : 'platform-dashboard-flow'">
      <section v-if="!showShops" class="platform-dashboard-intro rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div class="min-w-0">
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-teal-700">Platform Admin</p>
            <h3 class="mt-1 text-3xl font-bold leading-tight tracking-tight text-slate-950 sm:text-4xl">Dashboard</h3>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Overview of platform activity, shops, subscriptions, and system status.</p>
          </div>
        </div>
      </section>
      <section v-else class="platform-page-heading">
        <div>
          <p class="platform-eyebrow">PLATFORM ADMIN</p>
          <h1>Shops</h1>
          <p>Manage shop access, subscription coverage, and platform billing.</p>
        </div>
        <AppLink to="/get-started" class="platform-button platform-button-primary">
          <i class="fas fa-plus" aria-hidden="true"></i><span>Add Shop</span>
        </AppLink>
      </section>

      <div class="platform-dashboard-notices">
        <div v-if="successMessage" class="platform-alert platform-alert-success" role="status">{{ successMessage }}</div>
        <div v-if="temporaryPassword" class="platform-alert border-amber-200 bg-amber-50 text-amber-900" role="status">
          <p class="font-bold">Temporary password for {{ temporaryPassword.admin?.email }}:</p>
          <p class="mt-2 inline-flex rounded-md bg-white px-3 py-2 font-mono text-base font-bold text-slate-950 ring-1 ring-amber-200">{{ temporaryPassword.password }}</p>
          <p class="mt-2 text-xs">Show this once to the shop admin, then ask them to change it after login.</p>
        </div>
        <div v-if="errorMessage && (showShops || dashboard)" class="platform-alert platform-alert-danger" role="alert">{{ errorMessage }}</div>
      </div>

      <section v-if="!showShops && dashboard" aria-label="Platform key metrics" class="platform-dashboard-metrics grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <article v-for="stat in heroStats" :key="stat.label" class="platform-metric flex min-w-0 items-center justify-between gap-3 p-4 sm:p-5">
          <div class="min-w-0">
            <p class="text-xs font-semibold leading-5 text-slate-500">{{ stat.label }}</p>
            <p class="mt-1 break-words text-3xl font-bold leading-tight tabular-nums text-slate-950">{{ stat.value }}</p>
            <p class="mt-1 text-xs leading-5 text-slate-500">{{ stat.hint }}</p>
          </div>
          <span
            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ring-1"
            :class="{
              'bg-teal-50 text-teal-700 ring-teal-100': stat.tone === 'teal',
              'bg-blue-50 text-blue-700 ring-blue-100': stat.tone === 'blue',
              'bg-orange-50 text-orange-700 ring-orange-100': stat.tone === 'orange',
              'bg-violet-50 text-violet-700 ring-violet-100': stat.tone === 'purple',
            }"
            aria-hidden="true"
          >
            <i class="fas" :class="stat.icon"></i>
          </span>
        </article>
      </section>

      <section v-if="showShops && dashboard" class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <div v-for="card in summaryCards.slice(0, 3)" :key="card.label" class="platform-metric p-4">
          <p class="text-sm font-semibold text-slate-500">{{ card.label }}</p>
          <p class="mt-2 text-3xl font-bold leading-tight text-slate-950">{{ card.value }}</p>
          <p class="mt-2 text-xs text-slate-500">{{ card.hint }}</p>
        </div>
      </section>

      <section v-if="!showShops && dashboard" aria-label="Platform access and billing" class="platform-dashboard-overview grid grid-cols-1 gap-4 xl:grid-cols-[1.1fr_0.9fr]">
        <div class="platform-card">
          <div class="border-b border-slate-200 px-5 py-4">
            <h3 class="text-lg font-bold text-slate-950">Shop &amp; Subscription Overview</h3>
            <p class="mt-1 text-sm text-slate-500">Current workspace access status and upcoming renewal attention.</p>
          </div>
          <div class="divide-y divide-slate-100 px-5">
            <div v-for="item in overviewSections" :key="item.label" class="flex items-center gap-3 py-4">
              <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="{
                'bg-emerald-500': item.tone === 'emerald',
                'bg-amber-500': item.tone === 'amber',
                'bg-orange-500': item.tone === 'orange',
                'bg-sky-500': item.tone === 'sky',
                'bg-rose-500': item.tone === 'rose',
              }" aria-hidden="true"></span>
              <div class="min-w-0 flex-1">
                <p class="font-semibold text-slate-900">{{ item.label }}</p>
                <p class="mt-0.5 text-xs leading-5 text-slate-500">{{ item.hint }}</p>
              </div>
              <span class="min-w-8 text-right text-lg font-bold tabular-nums text-slate-950">{{ item.value }}</span>
            </div>
          </div>
          <div class="border-t border-slate-100 px-5 py-3">
            <AppLink to="/platform/shops" class="inline-flex min-h-10 items-center gap-2 text-sm font-bold text-teal-700 hover:text-teal-800">
              Review workspaces <i class="fas fa-arrow-right text-xs" aria-hidden="true"></i>
            </AppLink>
          </div>
        </div>

        <div class="platform-card flex flex-col p-5 sm:p-6">
          <div>
            <p class="text-xs font-bold uppercase tracking-[0.14em] text-teal-700">Platform Billing</p>
            <h3 class="mt-1 text-lg font-bold text-slate-950">Collected this month</h3>
          </div>
          <p class="mt-3 break-words text-3xl font-bold leading-tight text-slate-950 sm:text-4xl">{{ formatCurrency(dashboard.summary.platform_collected_this_month) }}</p>
          <p class="mt-1 text-sm text-slate-500">From {{ dashboard.summary.platform_payments_this_month }} platform payment{{ dashboard.summary.platform_payments_this_month === 1 ? '' : 's' }} this month</p>
          <div class="mt-5 flex items-center justify-between gap-3 border-t border-slate-100 pt-4">
            <div>
              <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Repair Receivables</p>
              <p class="mt-1 text-lg font-bold text-slate-900">{{ formatCurrency(dashboard.summary.pending_payments) }}</p>
              <p class="text-xs text-slate-500">Customer repair balances across shops</p>
            </div>
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-50 text-slate-600 ring-1 ring-slate-200" aria-hidden="true">
              <i class="fas fa-arrow-trend-up"></i>
            </span>
          </div>
        </div>
      </section>

      <section v-if="!showShops && dashboard" class="platform-dashboard-footprint platform-card">
        <div class="border-b border-slate-200 px-5 py-4">
          <h3 class="text-lg font-bold text-slate-950">Operations Footprint</h3>
          <p class="mt-1 text-sm text-slate-500">Platform-wide usage across shops and their teams.</p>
        </div>
        <div class="grid grid-cols-2 divide-x divide-y divide-slate-100 sm:grid-cols-3 xl:grid-cols-3 xl:divide-y-0">
          <div class="p-4 sm:p-5">
            <p class="text-xs font-semibold text-slate-500">Inactive Shops</p>
            <p class="mt-2 text-2xl font-bold tabular-nums text-slate-950">{{ dashboard.summary.inactive_shops }}</p>
          </div>
          <div class="p-4 sm:p-5">
            <p class="text-xs font-semibold text-slate-500">Technicians</p>
            <p class="mt-2 text-2xl font-bold tabular-nums text-slate-950">{{ dashboard.summary.technicians }}</p>
          </div>
          <div class="col-span-2 p-4 sm:col-span-1 sm:p-5">
            <p class="text-xs font-semibold text-slate-500">Job Orders</p>
            <p class="mt-2 text-2xl font-bold tabular-nums text-slate-950">{{ dashboard.summary.job_orders }}</p>
          </div>
        </div>
      </section>

      <section v-if="!showShops && dashboard" aria-label="Platform quick actions" class="platform-dashboard-actions space-y-3">
        <div>
          <h3 class="text-lg font-bold text-slate-950">Quick Actions</h3>
          <p class="mt-1 text-sm text-slate-500">Open a platform workspace or operational queue.</p>
        </div>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
          <AppLink to="/platform/shops" class="group flex min-h-14 items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 font-semibold text-slate-800 shadow-sm hover:border-teal-200 hover:text-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500">
            <span class="flex min-w-0 items-center gap-3"><i class="fas fa-store w-5 text-center text-teal-700" aria-hidden="true"></i><span>Manage Shops</span></span>
            <i class="fas fa-arrow-right text-xs text-slate-400 group-hover:text-teal-700" aria-hidden="true"></i>
          </AppLink>
          <AppLink to="/platform/subscription-requests" class="group flex min-h-14 items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 font-semibold text-slate-800 shadow-sm hover:border-teal-200 hover:text-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500">
            <span class="flex min-w-0 items-center gap-3"><i class="fas fa-credit-card w-5 text-center text-blue-700" aria-hidden="true"></i><span>Manage Subscriptions</span></span>
            <i class="fas fa-arrow-right text-xs text-slate-400 group-hover:text-teal-700" aria-hidden="true"></i>
          </AppLink>
          <AppLink to="/get-started" class="group flex min-h-14 items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 font-semibold text-slate-800 shadow-sm hover:border-teal-200 hover:text-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500">
            <span class="flex min-w-0 items-center gap-3"><i class="fas fa-plus-circle w-5 text-center text-teal-700" aria-hidden="true"></i><span>Add Shop</span></span>
            <i class="fas fa-arrow-right text-xs text-slate-400 group-hover:text-teal-700" aria-hidden="true"></i>
          </AppLink>
          <AppLink to="/platform/failed-jobs" class="group flex min-h-14 items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 font-semibold text-slate-800 shadow-sm hover:border-teal-200 hover:text-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500">
            <span class="flex min-w-0 items-center gap-3"><i class="fas fa-triangle-exclamation w-5 text-center text-amber-600" aria-hidden="true"></i><span>Failed Jobs</span></span>
            <i class="fas fa-arrow-right text-xs text-slate-400 group-hover:text-teal-700" aria-hidden="true"></i>
          </AppLink>
          <AppLink to="/platform/audit-logs" class="group flex min-h-14 items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 font-semibold text-slate-800 shadow-sm hover:border-teal-200 hover:text-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500">
            <span class="flex min-w-0 items-center gap-3"><i class="fas fa-clipboard-list w-5 text-center text-violet-700" aria-hidden="true"></i><span>Audit Logs</span></span>
            <i class="fas fa-arrow-right text-xs text-slate-400 group-hover:text-teal-700" aria-hidden="true"></i>
          </AppLink>
        </div>
      </section>

      <section v-if="!showShops && dashboard" class="platform-dashboard-shops platform-card">
        <div class="flex items-center justify-between gap-4 border-b border-slate-200 p-5">
          <div>
            <h3 class="text-lg font-bold text-slate-950">Recent Shops</h3>
            <p class="mt-1 text-sm text-slate-500">Newest shops registered in the platform.</p>
          </div>
          <AppLink to="/platform/shops" class="btn-soft px-4 py-2">View All</AppLink>
        </div>
        <div class="divide-y divide-slate-100 xl:hidden">
          <article v-for="shop in dashboard.recent_shops" :key="`mobile-${shop.id}`" class="space-y-3 p-4">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="truncate font-bold text-slate-950">{{ shop.name }}</p>
                <p class="mt-1 break-words text-xs text-slate-500">{{ shop.slug }}</p>
              </div>
              <span class="inline-flex shrink-0 rounded-full px-2.5 py-1 text-xs font-bold ring-1" :class="accessBadgeClass(shop)">
                {{ shop.access_status_label }}
              </span>
            </div>
            <div class="grid grid-cols-2 gap-3 rounded-lg bg-slate-50 p-3 text-sm">
              <div class="min-w-0">
                <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Plan</p>
                <p class="mt-1 truncate font-semibold uppercase text-slate-700">{{ shop.subscription_plan }}</p>
              </div>
              <div class="min-w-0">
                <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Owner</p>
                <p class="mt-1 truncate font-semibold text-slate-800">{{ shop.admin?.name || 'No owner' }}</p>
              </div>
              <div>
                <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Joined</p>
                <p class="mt-1 text-xs text-slate-700">{{ shop.created_at_formatted || '—' }}</p>
              </div>
              <div class="flex items-end justify-end text-xs text-slate-500">{{ shop.is_active ? 'Enabled workspace' : 'Disabled workspace' }}</div>
            </div>
            <p class="text-xs leading-5 text-slate-500">{{ shop.access_status_help }}</p>
          </article>
          <div v-if="!dashboard.recent_shops?.length" class="p-8 text-center">
            <p class="font-semibold text-slate-700">No shop workspaces yet</p>
            <p class="mt-1 text-sm text-slate-500">Newly registered shops will appear here.</p>
          </div>
        </div>
        <div class="hidden overflow-x-auto xl:block">
          <table class="data-table">
            <thead>
              <tr>
                <th class="px-4 py-3 text-left text-slate-600">Shop</th>
                <th class="px-4 py-3 text-left text-slate-600">Owner</th>
                <th class="px-4 py-3 text-left text-slate-600">Plan</th>
                <th class="px-4 py-3 text-left text-slate-600">Status</th>
                <th class="px-4 py-3 text-left text-slate-600">Joined</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="shop in dashboard.recent_shops" :key="shop.id">
                <td class="px-4 py-3">
                  <p class="font-semibold text-slate-900">{{ shop.name }}</p>
                  <p class="text-xs text-slate-500">{{ shop.slug }}</p>
                </td>
                <td class="px-4 py-3">
                  <p class="font-semibold text-slate-900">{{ shop.admin?.name || 'No owner' }}</p>
                </td>
                <td class="px-4 py-3 font-semibold uppercase text-slate-700">{{ shop.subscription_plan }}</td>
                <td class="px-4 py-3">
                  <span class="rounded-full px-3 py-1 text-xs font-bold ring-1" :class="accessBadgeClass(shop)">
                    {{ shop.access_status_label }}
                  </span>
                </td>
                <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ shop.created_at_formatted || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="!dashboard.recent_shops?.length" class="hidden p-8 text-center xl:block">
          <p class="font-semibold text-slate-700">No shop workspaces yet</p>
          <p class="mt-1 text-sm text-slate-500">Newly registered shops will appear here.</p>
        </div>
      </section>

      <section v-if="!showShops && dashboard" class="platform-dashboard-activity platform-card">
        <div class="flex flex-col gap-2 border-b border-slate-200 p-5 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h3 class="text-lg font-bold text-slate-950">Recent Activity</h3>
            <p class="mt-1 text-sm text-slate-500">Platform subscription reminder notifications and delivery status.</p>
          </div>
          <span class="w-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600 ring-1 ring-slate-200">
            {{ dashboard.recent_reminders?.length || 0 }} recent
          </span>
        </div>
        <div v-if="dashboard.recent_reminders?.length" class="divide-y divide-slate-100">
          <div v-for="reminder in dashboard.recent_reminders" :key="reminder.id" class="grid grid-cols-1 gap-3 p-5 lg:grid-cols-[1.1fr_1fr_0.8fr] lg:items-start">
            <div>
              <p class="font-bold text-slate-950">{{ reminder.shop_name || 'Unknown shop' }}</p>
              <p class="mt-1 text-xs text-slate-500">{{ reminder.shop_slug || 'no-shop-code' }}</p>
              <p class="mt-2 text-sm font-semibold text-slate-700">{{ reminder.trigger_label }}</p>
            </div>
            <div>
              <p class="text-sm font-semibold text-slate-900">{{ reminder.recipient }}</p>
              <p class="mt-1 text-xs text-slate-500">{{ reminder.subject }}</p>
              <p v-if="reminder.error_message" class="mt-2 rounded-lg border border-rose-100 bg-rose-50 p-2 text-xs text-rose-700">{{ reminder.error_message }}</p>
            </div>
            <div class="lg:text-right">
              <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold ring-1" :class="reminderStatusClass(reminder.status)">
                {{ reminder.status_label }}
              </span>
              <p class="mt-2 text-xs text-slate-500">Queued {{ reminder.queued_at_formatted || 'not recorded' }}</p>
              <p v-if="reminder.sent_at_formatted" class="mt-1 text-xs text-emerald-700">Sent {{ reminder.sent_at_formatted }}</p>
              <p v-if="reminder.failed_at_formatted" class="mt-1 text-xs text-rose-700">Failed {{ reminder.failed_at_formatted }}</p>
            </div>
          </div>
        </div>
        <div v-else class="p-6 text-sm text-slate-500">
          No subscription reminders queued yet.
        </div>
      </section>

      <div v-if="showShops && errorMessage" class="platform-alert platform-alert-danger" role="alert">{{ errorMessage }}</div>
      <div v-if="showShops && errorMessage && !shops" class="platform-card p-5">
        <button type="button" class="platform-button platform-button-secondary" @click="loadShops(1)">Try again</button>
      </div>
      <section v-if="showShops && shops" class="space-y-5">
        <div class="platform-card p-4">
          <form class="grid grid-cols-1 gap-3 md:grid-cols-[1.5fr_0.75fr_0.85fr_0.8fr_auto]" @submit.prevent="loadShops(1)">
            <label class="platform-field">
              <span class="sr-only">Search shops</span>
              <input v-model="filters.search" type="search" aria-label="Search shop name, slug, or email" placeholder="Search shop name, slug, email..." />
            </label>
            <select v-model="filters.is_active" aria-label="Filter by shop status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option value="">All workspaces</option>
              <option value="1">Enabled</option>
              <option value="0">Disabled</option>
            </select>
            <select v-model="filters.access_status" aria-label="Filter by access status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option value="">All access states</option>
              <option value="active">Active Access</option>
              <option value="expiring">Expiring Soon</option>
              <option value="close_out">Close-out</option>
              <option value="needs_setup">Needs Setup</option>
              <option value="inactive">Inactive</option>
            </select>
            <select v-model="filters.subscription_plan" aria-label="Filter by subscription plan" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option value="">All plans</option>
              <option v-for="plan in plans" :key="plan" :value="plan">{{ plan }}</option>
            </select>
            <div class="flex justify-end gap-2">
              <button type="button" class="platform-button platform-button-secondary" @click="clearFilters">Clear</button>
              <button type="submit" class="platform-button platform-button-primary">Apply</button>
            </div>
          </form>
        </div>

        <div class="space-y-3 xl:hidden" aria-label="Shop workspace list">
          <article v-for="shop in shops.data" :key="`mobile-${shop.id}`" class="admin-panel space-y-4 p-4 sm:p-5">
            <div class="flex min-w-0 items-start justify-between gap-3">
              <div class="min-w-0">
                <h3 class="break-words text-base font-bold text-slate-950">{{ shop.name }}</h3>
                <p class="mt-1 break-words text-xs text-slate-500">{{ shop.slug }} · {{ shop.email || 'No email' }}</p>
              </div>
              <span class="inline-flex max-w-[46%] shrink-0 rounded-full px-2.5 py-1 text-center text-xs font-bold ring-1" :class="accessBadgeClass(shop)">
                {{ shop.access_status_label }}
              </span>
            </div>

            <div class="grid grid-cols-2 gap-3 rounded-xl bg-slate-50 p-3 text-sm">
              <div class="min-w-0">
                <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Plan</p>
                <p class="mt-1 truncate font-semibold uppercase text-slate-800">{{ shop.subscription_plan }}</p>
              </div>
              <div class="min-w-0">
                <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Access</p>
                <p class="mt-1 text-xs font-semibold text-slate-800">{{ shop.trial_ends_at_formatted ? `Until ${shop.trial_ends_at_formatted}` : 'No access date' }}</p>
              </div>
              <div class="min-w-0">
                <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Shop Admin</p>
                <p class="mt-1 truncate font-semibold text-slate-800">{{ shop.admin?.name || 'No admin' }}</p>
                <p class="truncate text-xs text-slate-500">{{ shop.admin?.email || 'Not set' }}</p>
              </div>
              <div>
                <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Activity</p>
                <p class="mt-1 text-xs text-slate-700">{{ shop.users_count }} users · {{ shop.technicians_count }} technicians</p>
                <p class="text-xs text-slate-700">{{ shop.job_orders_count }} job orders</p>
              </div>
            </div>

            <p class="text-xs leading-5 text-slate-500">{{ shop.access_status_help }}</p>

            <div v-if="isEditingShop(shop)" class="grid grid-cols-1 gap-3 rounded-xl border border-teal-100 bg-teal-50/40 p-3">
              <label class="space-y-1.5">
                <span class="text-xs font-bold uppercase text-slate-500">Subscription plan</span>
                <input v-model="editForms[shop.id].subscription_plan" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="starter" />
              </label>
              <label class="space-y-1.5">
                <span class="text-xs font-bold uppercase text-slate-500">Access until</span>
                <input v-model="editForms[shop.id].trial_ends_at" type="date" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
              </label>
              <label class="flex min-h-10 items-center gap-2 text-sm font-semibold text-slate-700">
                <input v-model="editForms[shop.id].is_active" type="checkbox" />
                {{ editForms[shop.id].is_active ? 'Workspace active' : 'Workspace inactive' }}
              </label>
            </div>

            <div class="flex flex-wrap gap-2">
              <template v-if="isEditingShop(shop)">
                <button type="button" class="inline-flex min-h-10 items-center rounded-lg bg-teal-600 px-3 py-2 text-sm font-bold text-white hover:bg-teal-700" @click="updateShop(shop)">Save changes</button>
                <button type="button" class="inline-flex min-h-10 items-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="setShopEditing(shop, false)">Cancel</button>
              </template>
              <button v-else type="button" class="inline-flex min-h-10 items-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-700 hover:border-teal-200 hover:text-teal-700" @click="setShopEditing(shop, true)">Edit</button>
              <button type="button" class="inline-flex min-h-10 items-center rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm font-bold text-amber-700 hover:bg-amber-50 disabled:opacity-50" :disabled="!shop.admin" @click="resetShopAdminPassword(shop)">Reset Admin</button>
              <button
                type="button"
                class="inline-flex min-h-10 items-center rounded-lg border bg-white px-3 py-2 text-sm font-bold"
                :class="shop.access_status === 'close_out' || shop.access_status === 'expiring' || shop.access_status === 'needs_setup' ? 'border-amber-200 text-amber-700 hover:bg-amber-50' : 'border-teal-200 text-teal-700 hover:bg-teal-50'"
                @click="setShopBilling(shop, !isBillingShop(shop))"
              >
                {{ paymentActionLabel(shop) }}
              </button>
              <button type="button" class="inline-flex min-h-10 items-center rounded-lg border px-3 py-2 text-sm font-bold" :class="shop.is_active ? 'border-rose-200 bg-white text-rose-700 hover:bg-rose-50' : 'border-emerald-200 bg-white text-emerald-700 hover:bg-emerald-50'" @click="toggleShop(shop)">
                {{ shop.is_active ? 'Deactivate' : 'Activate' }}
              </button>
              <a :href="shop.public_tracking_url" target="_blank" rel="noopener" class="inline-flex min-h-10 items-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-700 hover:border-teal-200 hover:text-teal-700">Tracking</a>
            </div>

            <div v-if="isBillingShop(shop)" class="space-y-4 rounded-xl border border-slate-200 bg-slate-50 p-3">
              <form class="space-y-3 rounded-lg border border-slate-200 bg-white p-3" @submit.prevent="recordSubscriptionPayment(shop)">
                <div>
                  <h4 class="font-bold text-slate-950">Record Subscription Payment</h4>
                  <p class="mt-1 text-xs leading-5 text-slate-500">Platform billing, separate from customer repair payments.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                  <button v-for="preset in billingPresets" :key="preset.months" type="button" class="min-h-9 rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 hover:border-teal-200 hover:text-teal-700" @click="applyBillingPreset(shop, preset.months)">
                    {{ preset.label }}
                  </button>
                </div>
                <label class="block space-y-1.5">
                  <span class="text-xs font-bold uppercase text-slate-500">Amount</span>
                  <input v-model="billingForms[shop.id].amount" type="number" min="0.01" step="0.01" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
                </label>
                <label class="block space-y-1.5">
                  <span class="text-xs font-bold uppercase text-slate-500">Method</span>
                  <select v-model="billingForms[shop.id].payment_method" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="CASH">Cash</option>
                    <option value="GCASH">GCash</option>
                    <option value="MAYA">Maya</option>
                    <option value="BANK_TRANSFER">Bank Transfer</option>
                    <option value="CARD">Card</option>
                    <option value="OTHER">Other</option>
                  </select>
                </label>
                <label class="block space-y-1.5">
                  <span class="text-xs font-bold uppercase text-slate-500">Received</span>
                  <input v-model="billingForms[shop.id].received_at" type="date" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                </label>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <label class="space-y-1.5">
                    <span class="text-xs font-bold uppercase text-slate-500">Covered From</span>
                    <input v-model="billingForms[shop.id].covered_from" type="date" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                  </label>
                  <label class="space-y-1.5">
                    <span class="text-xs font-bold uppercase text-slate-500">Covered Until</span>
                    <input v-model="billingForms[shop.id].covered_until" type="date" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                  </label>
                </div>
                <label class="block space-y-1.5">
                  <span class="text-xs font-bold uppercase text-slate-500">Reference</span>
                  <input v-model="billingForms[shop.id].reference_number" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Optional" />
                </label>
                <label class="block space-y-1.5">
                  <span class="text-xs font-bold uppercase text-slate-500">Notes</span>
                  <textarea v-model="billingForms[shop.id].notes" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Optional"></textarea>
                </label>
                <div class="flex justify-end gap-2">
                  <button type="button" class="min-h-10 rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="setShopBilling(shop, false)">Cancel</button>
                  <button type="submit" class="btn-primary min-h-10 px-3 py-2 text-sm">Record Payment</button>
                </div>
              </form>
              <div class="rounded-lg border border-slate-200 bg-white p-3">
                <h4 class="font-bold text-slate-950">Recent Billing</h4>
                <div v-if="shop.subscription_payments?.length" class="mt-3 space-y-2">
                  <div v-for="payment in shop.subscription_payments" :key="`mobile-${payment.id}`" class="rounded-lg bg-slate-50 p-3 text-sm">
                    <div class="flex items-start justify-between gap-3">
                      <div>
                        <p class="font-bold text-slate-900">{{ formatCurrency(payment.amount) }}</p>
                        <p class="text-xs text-slate-500">{{ payment.payment_method?.replaceAll('_', ' ') }} <span v-if="payment.reference_number">- Ref: {{ payment.reference_number }}</span></p>
                      </div>
                      <p class="text-right text-xs text-slate-500">{{ payment.received_at_formatted }}</p>
                    </div>
                    <p v-if="payment.covered_from_formatted || payment.covered_until_formatted" class="mt-2 text-xs text-slate-500">
                      Coverage: {{ payment.covered_from_formatted || 'Start not set' }} - {{ payment.covered_until_formatted || 'End not set' }}
                    </p>
                  </div>
                </div>
                <p v-else class="mt-2 text-sm text-slate-500">No platform billing records yet.</p>
              </div>
            </div>
          </article>

          <div v-if="!shops.data.length" class="admin-panel p-8 text-center">
            <p class="font-semibold text-slate-700">No shop workspaces found</p>
            <p class="mt-1 text-sm text-slate-500">Try adjusting your search or filters.</p>
          </div>
        </div>

        <div class="admin-panel hidden overflow-hidden xl:block">
          <div class="overflow-x-auto">
            <table class="data-table">
              <thead>
                <tr>
                  <th class="px-4 py-3 text-left text-slate-600">Shop</th>
                  <th class="px-4 py-3 text-left text-slate-600">Admin</th>
                  <th class="px-4 py-3 text-left text-slate-600">Plan / Access</th>
                  <th class="px-4 py-3 text-left text-slate-600">Activity</th>
                  <th class="px-4 py-3 text-left text-slate-600">Status</th>
                  <th class="px-4 py-3 text-left text-slate-600">Actions</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="shop in shops.data" :key="shop.id">
                <tr class="align-top">
                  <td class="px-4 py-3">
                    <p class="font-semibold text-slate-900">{{ shop.name }}</p>
                    <p class="text-xs text-slate-500">{{ shop.slug }} - {{ shop.email || 'No email' }}</p>
                    <p class="text-xs text-slate-500">Created {{ shop.created_at_formatted }}</p>
                  </td>
                  <td class="px-4 py-3">
                    <p class="font-semibold text-slate-900">{{ shop.admin?.name || 'No admin' }}</p>
                    <p class="text-xs text-slate-500">{{ shop.admin?.email || 'Not set' }}</p>
                    <p v-if="shop.admin?.last_login_at_formatted" class="text-xs text-slate-500">Last login {{ shop.admin.last_login_at_formatted }}</p>
                  </td>
                  <td class="px-4 py-3">
                    <div v-if="isEditingShop(shop)" class="grid min-w-56 gap-2">
                      <input v-model="editForms[shop.id].subscription_plan" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="starter" />
                      <input v-model="editForms[shop.id].trial_ends_at" type="date" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                      <span
                        class="w-fit rounded-full px-2.5 py-1 text-xs font-bold ring-1"
                        :class="shop.trial_status === 'expired' ? 'bg-rose-50 text-rose-700 ring-rose-200' : shop.trial_status === 'expiring' ? 'bg-amber-50 text-amber-700 ring-amber-200' : 'bg-slate-50 text-slate-600 ring-slate-200'"
                      >
                        {{ shop.trial_status === 'none' ? 'No expiry' : shop.trial_status }}
                      </span>
                    </div>
                    <div v-else class="space-y-2">
                      <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-bold uppercase text-slate-700 ring-1 ring-slate-200">{{ shop.subscription_plan }}</span>
                      <p class="text-sm font-semibold text-slate-900">{{ shop.trial_ends_at_formatted ? `Access until ${shop.trial_ends_at_formatted}` : 'No access date' }}</p>
                      <p v-if="shop.subscription_payments?.[0]" class="text-xs text-slate-500">Last payment {{ formatCurrency(shop.subscription_payments[0].amount) }} on {{ shop.subscription_payments[0].received_at_formatted }}</p>
                    </div>
                  </td>
                  <td class="px-4 py-3 text-slate-700">
                    <p>{{ shop.users_count }} users</p>
                    <p>{{ shop.technicians_count }} technicians</p>
                    <p>{{ shop.job_orders_count }} job orders</p>
                  </td>
                  <td class="px-4 py-3">
                    <label v-if="isEditingShop(shop)" class="flex items-center gap-2 text-sm font-semibold text-slate-700">
                      <input v-model="editForms[shop.id].is_active" type="checkbox" />
                      {{ editForms[shop.id].is_active ? 'Active' : 'Inactive' }}
                    </label>
                    <div v-else class="max-w-52 space-y-2">
                      <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold ring-1" :class="accessBadgeClass(shop)">
                        {{ shop.access_status_label }}
                      </span>
                      <p class="text-xs leading-5 text-slate-500">{{ shop.access_status_help }}</p>
                    </div>
                  </td>
                  <td class="px-4 py-3">
                    <div class="flex flex-wrap gap-2">
                      <template v-if="isEditingShop(shop)">
                        <button type="button" class="rounded-lg border border-teal-200 px-3 py-1.5 text-xs font-bold text-teal-700 hover:bg-teal-50" @click="updateShop(shop)">Save</button>
                        <button type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-50" @click="setShopEditing(shop, false)">Cancel</button>
                      </template>
                      <button v-else type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700 hover:border-teal-200 hover:text-teal-700" @click="setShopEditing(shop, true)">Edit</button>
                      <button type="button" class="rounded-lg border border-amber-200 px-3 py-1.5 text-xs font-bold text-amber-700 hover:bg-amber-50 disabled:opacity-50" :disabled="!shop.admin" @click="resetShopAdminPassword(shop)">Reset Admin</button>
                      <button
                        type="button"
                        class="rounded-lg border px-3 py-1.5 text-xs font-bold"
                        :class="shop.access_status === 'close_out' || shop.access_status === 'expiring' || shop.access_status === 'needs_setup' ? 'border-amber-200 text-amber-700 hover:bg-amber-50' : 'border-violet-200 text-violet-700 hover:bg-violet-50'"
                        @click="setShopBilling(shop, !isBillingShop(shop))"
                      >
                        {{ paymentActionLabel(shop) }}
                      </button>
                      <button type="button" class="rounded-lg border px-3 py-1.5 text-xs font-bold" :class="shop.is_active ? 'border-rose-200 text-rose-600 hover:bg-rose-50' : 'border-emerald-200 text-emerald-700 hover:bg-emerald-50'" @click="toggleShop(shop)">
                        {{ shop.is_active ? 'Deactivate' : 'Activate' }}
                      </button>
                      <a :href="shop.public_tracking_url" target="_blank" rel="noopener" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700 hover:border-teal-200 hover:text-teal-700">Tracking</a>
                    </div>
                  </td>
                </tr>
                <tr v-if="isBillingShop(shop)" class="bg-slate-50">
                  <td colspan="6" class="px-4 py-4">
                    <div class="grid grid-cols-1 gap-4 xl:grid-cols-[1.3fr_0.9fr]">
                      <form class="rounded-xl border border-slate-200 bg-white p-4" @submit.prevent="recordSubscriptionPayment(shop)">
                        <div class="mb-3">
                          <h4 class="font-bold text-slate-950">Record Subscription Payment</h4>
                          <p class="mt-1 text-xs text-slate-500">This is for platform billing, separate from customer repair payments.</p>
                        </div>
                        <div class="mb-3 flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 p-2">
                          <span class="px-1 text-xs font-bold uppercase text-slate-500">Coverage</span>
                          <button
                            v-for="preset in billingPresets"
                            :key="preset.months"
                            type="button"
                            class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 hover:border-teal-200 hover:text-teal-700"
                            @click="applyBillingPreset(shop, preset.months)"
                          >
                            {{ preset.label }}
                          </button>
                        </div>
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                          <label class="space-y-1.5">
                            <span class="text-xs font-bold uppercase text-slate-500">Amount</span>
                            <input v-model="billingForms[shop.id].amount" type="number" min="0.01" step="0.01" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
                          </label>
                          <label class="space-y-1.5">
                            <span class="text-xs font-bold uppercase text-slate-500">Method</span>
                            <select v-model="billingForms[shop.id].payment_method" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                              <option value="CASH">Cash</option>
                              <option value="GCASH">GCash</option>
                              <option value="MAYA">Maya</option>
                              <option value="BANK_TRANSFER">Bank Transfer</option>
                              <option value="CARD">Card</option>
                              <option value="OTHER">Other</option>
                            </select>
                          </label>
                          <label class="space-y-1.5">
                            <span class="text-xs font-bold uppercase text-slate-500">Received</span>
                            <input v-model="billingForms[shop.id].received_at" type="date" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                          </label>
                          <label class="space-y-1.5">
                            <span class="text-xs font-bold uppercase text-slate-500">Covered From</span>
                            <input v-model="billingForms[shop.id].covered_from" type="date" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                          </label>
                          <label class="space-y-1.5">
                            <span class="text-xs font-bold uppercase text-slate-500">Covered Until</span>
                            <input v-model="billingForms[shop.id].covered_until" type="date" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                          </label>
                          <label class="space-y-1.5">
                            <span class="text-xs font-bold uppercase text-slate-500">Reference</span>
                            <input v-model="billingForms[shop.id].reference_number" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="optional" />
                          </label>
                          <label class="space-y-1.5 md:col-span-3">
                            <span class="text-xs font-bold uppercase text-slate-500">Notes</span>
                            <textarea v-model="billingForms[shop.id].notes" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="optional"></textarea>
                          </label>
                        </div>
                        <div class="mt-3 flex justify-end gap-2">
                          <button type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="setShopBilling(shop, false)">Cancel</button>
                          <button type="submit" class="btn-primary px-3 py-2 text-sm">Record Payment</button>
                        </div>
                      </form>

                      <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <h4 class="font-bold text-slate-950">Recent Billing</h4>
                        <div v-if="shop.subscription_payments?.length" class="mt-3 space-y-2">
                          <div v-for="payment in shop.subscription_payments" :key="payment.id" class="rounded-lg border border-slate-100 bg-slate-50 p-3 text-sm">
                            <div class="flex items-start justify-between gap-3">
                              <div>
                                <p class="font-bold text-slate-900">{{ formatCurrency(payment.amount) }}</p>
                                <p class="text-xs text-slate-500">{{ payment.payment_method?.replaceAll('_', ' ') }} <span v-if="payment.reference_number">- Ref: {{ payment.reference_number }}</span></p>
                              </div>
                              <p class="text-xs text-slate-500 text-right">{{ payment.received_at_formatted }}</p>
                            </div>
                            <p v-if="payment.covered_from_formatted || payment.covered_until_formatted" class="mt-2 text-xs text-slate-500">
                              Coverage: {{ payment.covered_from_formatted || 'Start not set' }} - {{ payment.covered_until_formatted || 'End not set' }}
                            </p>
                          </div>
                        </div>
                        <p v-else class="mt-3 rounded-lg bg-slate-50 p-3 text-sm text-slate-500">No platform billing records yet.</p>
                      </div>
                    </div>
                  </td>
                </tr>
                </template>
                <tr v-if="!shops.data.length">
                  <td colspan="6" class="px-4 py-8 text-center text-slate-500">No shop workspaces found.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="flex flex-wrap gap-2">
          <button
            v-for="link in shops.links"
            :key="link.label + String(link.url)"
            type="button"
            class="rounded-lg px-3 py-2 text-sm font-bold"
            :class="link.active ? 'bg-slate-950 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 disabled:text-slate-300'"
            :disabled="!link.url"
            @click="gotoPage(link.url)"
            v-html="link.label"
          ></button>
        </div>
      </section>
    </div>
  </PlatformLayout>
</template>
