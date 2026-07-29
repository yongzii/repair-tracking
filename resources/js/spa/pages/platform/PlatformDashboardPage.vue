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
const subscriptionSettings = ref(null);
const subscriptionRequests = ref([]);
const subscriptionRequestNotes = reactive({});

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
const settingsForm = reactive({
    instructions: '',
    plans: [],
    payment_methods: [],
});

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
        { label: 'Active Access', value: summary.trial_shops, hint: 'Covered shops' },
        { label: 'Access Expiring', value: summary.trial_expiring_soon, hint: 'Ending within 7 days' },
        { label: 'Expired Access', value: summary.expired_trials, hint: 'Need follow-up or extension' },
        { label: 'Needs Setup', value: summary.no_access_date_shops, hint: 'No access date yet' },
        { label: 'Platform Collected', value: formatCurrency(summary.platform_collected_this_month), hint: `${summary.platform_payments_this_month} payments this month`, wide: true },
        { label: 'Portal Users', value: summary.portal_users, hint: 'All login-capable accounts' },
        { label: 'Technicians', value: summary.technicians, hint: 'Technician profiles across shops' },
        { label: 'Job Orders', value: summary.job_orders, hint: 'All shop repair records' },
        { label: 'Pending Payments', value: formatCurrency(summary.pending_payments), hint: 'Receivables across shops', wide: true },
    ];
});

const heroStats = computed(() => {
    const summary = dashboard.value?.summary;
    if (!summary) return [];

    return [
        { label: 'Workspaces', value: summary.total_shops },
        { label: 'Collected This Month', value: formatCurrency(summary.platform_collected_this_month) },
        { label: 'Needs Follow-up', value: summary.expired_trials + summary.no_access_date_shops },
    ];
});

const overviewSections = computed(() => {
    const summary = dashboard.value?.summary;
    if (!summary) return [];

    return [
        {
            title: 'Access Health',
            description: 'Subscription coverage and workspaces that need renewal attention.',
            columns: 'xl:grid-cols-4',
            cards: [
                { label: 'Active Access', value: summary.trial_shops, hint: 'Can accept new work', tone: 'emerald' },
                { label: 'Expiring Soon', value: summary.trial_expiring_soon, hint: 'Ending within 7 days', tone: 'amber' },
                { label: 'Close-out', value: summary.expired_trials, hint: 'Expired, existing jobs only', tone: 'orange' },
                { label: 'Needs Setup', value: summary.no_access_date_shops, hint: 'New intake locked until coverage is recorded', tone: 'sky' },
            ],
        },
        {
            title: 'Platform Billing',
            description: 'Payments collected from repair shops for using the system.',
            columns: 'xl:grid-cols-3',
            cards: [
                { label: 'Collected This Month', value: formatCurrency(summary.platform_collected_this_month), hint: `${summary.platform_payments_this_month} platform payments`, tone: 'teal', wide: true },
                { label: 'Repair Receivables', value: formatCurrency(summary.pending_payments), hint: 'Customer repair balances across shops', tone: 'rose' },
            ],
        },
        {
            title: 'Operations Footprint',
            description: 'Overall platform usage across shops, users, technicians, and repair records.',
            columns: 'xl:grid-cols-5',
            cards: [
                { label: 'Total Shops', value: summary.total_shops, hint: 'Registered workspaces', tone: 'slate' },
                { label: 'Active Shops', value: summary.active_shops, hint: 'Enabled workspaces', tone: 'emerald' },
                { label: 'Portal Users', value: summary.portal_users, hint: 'Login-capable accounts', tone: 'slate' },
                { label: 'Technicians', value: summary.technicians, hint: 'Technician profiles', tone: 'slate' },
                { label: 'Job Orders', value: summary.job_orders, hint: 'All repair records', tone: 'slate' },
            ],
        },
    ];
});

const overviewCardClass = (card) => {
    const tones = {
        emerald: 'border-emerald-100 bg-emerald-50/60',
        amber: 'border-amber-100 bg-amber-50/60',
        orange: 'border-orange-100 bg-orange-50/60',
        rose: 'border-rose-100 bg-rose-50/60',
        teal: 'border-teal-100 bg-teal-50/60',
        sky: 'border-sky-100 bg-sky-50/60',
        slate: 'border-slate-200 bg-white',
    };

    return tones[card.tone] || tones.slate;
};

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

const prepareSubscriptionSettings = (settings) => {
    subscriptionSettings.value = settings;
    settingsForm.instructions = settings?.instructions || '';
    settingsForm.plans = (settings?.plans || []).map((plan) => ({ ...plan }));
    settingsForm.payment_methods = (settings?.payment_methods || []).map((method) => ({ ...method }));
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

const loadSubscriptionSettings = async () => {
    if (!(await ensureAccess())) return;

    try {
        const { data } = await window.axios.get('/api/platform/subscription-settings');
        prepareSubscriptionSettings(data.settings);
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Unable to load subscription settings.';
    }
};

const loadSubscriptionRequests = async () => {
    if (!(await ensureAccess())) return;

    try {
        const { data } = await window.axios.get('/api/platform/subscription-requests', {
            params: { status: 'pending', per_page: 8 },
        });
        subscriptionRequests.value = data.subscription_requests?.data || [];
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Unable to load subscription requests.';
    }
};

const refresh = async () => {
    loading.value = true;
    errorMessage.value = '';
    successMessage.value = '';
    temporaryPassword.value = null;

    await loadDashboard();
    await loadSubscriptionSettings();
    await loadSubscriptionRequests();
    if (showShops.value) {
        await loadShops();
    }

    loading.value = false;
};

const addPlan = () => {
    settingsForm.plans.push({
        key: `plan-${settingsForm.plans.length + 1}`,
        name: 'New Plan',
        amount: 0,
        duration_months: 1,
    });
};

const removePlan = (index) => {
    settingsForm.plans.splice(index, 1);
};

const addPaymentMethod = () => {
    settingsForm.payment_methods.push({
        key: `method-${settingsForm.payment_methods.length + 1}`,
        name: 'Payment Method',
        account_name: '',
        account_number: '',
        instructions: '',
    });
};

const removePaymentMethod = (index) => {
    settingsForm.payment_methods.splice(index, 1);
};

const updateSubscriptionSettings = async () => {
    errorMessage.value = '';
    successMessage.value = '';
    temporaryPassword.value = null;

    try {
        const { data } = await window.axios.put('/api/platform/subscription-settings', {
            instructions: settingsForm.instructions,
            plans: settingsForm.plans,
            payment_methods: settingsForm.payment_methods,
        });
        successMessage.value = data.message || 'Subscription settings updated.';
        prepareSubscriptionSettings(data.settings);
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to update subscription settings.';
    }
};

const approveSubscriptionRequest = async (request) => {
    errorMessage.value = '';
    successMessage.value = '';

    try {
        const { data } = await window.axios.post(`/api/platform/subscription-requests/${request.id}/approve`, {
            admin_notes: subscriptionRequestNotes[request.id] || null,
        });
        successMessage.value = data.message || 'Subscription request approved.';
        await loadDashboard();
        await loadSubscriptionRequests();
        if (showShops.value) {
            await loadShops(shops.value?.current_page || 1);
        }
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Unable to approve subscription request.';
    }
};

const rejectSubscriptionRequest = async (request) => {
    errorMessage.value = '';
    successMessage.value = '';

    try {
        const { data } = await window.axios.post(`/api/platform/subscription-requests/${request.id}/reject`, {
            admin_notes: subscriptionRequestNotes[request.id] || null,
        });
        successMessage.value = data.message || 'Subscription request rejected.';
        await loadDashboard();
        await loadSubscriptionRequests();
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Unable to reject subscription request.';
    }
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
  <PlatformLayout :title="showShops ? 'Shop Workspaces' : 'Platform Overview'">
    <div v-if="loading" class="card p-6 text-slate-600">Loading platform console...</div>
    <div v-else-if="forbidden" class="card border border-rose-200 bg-rose-50 p-6 text-rose-700">Only platform admin accounts can access this page.</div>

    <div v-else class="space-y-6">
      <section class="admin-hero p-6">
        <div class="grid grid-cols-1 gap-5 xl:grid-cols-[1.2fr_0.8fr] xl:items-end">
          <div>
            <p class="text-xs font-bold uppercase text-amber-300">Super admin console</p>
            <h3 class="mt-2 font-display text-3xl">{{ showShops ? 'Manage shop workspaces' : 'Platform Overview' }}</h3>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">
              Monitor shop access, billing coverage, and platform activity without needing a shop account.
            </p>
            <div class="mt-5 flex flex-wrap gap-3">
              <AppLink to="/platform" class="rounded-lg bg-white/10 px-4 py-2 text-sm font-bold text-white hover:bg-white/15">Overview</AppLink>
              <AppLink to="/platform/shops" class="rounded-lg bg-amber-400 px-4 py-2 text-sm font-bold text-slate-950 hover:bg-amber-300">Shop Workspaces</AppLink>
            </div>
          </div>
          <div v-if="!showShops" class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div v-for="stat in heroStats" :key="stat.label" class="rounded-xl border border-white/10 bg-white/10 p-4">
              <p class="text-xs font-bold uppercase text-slate-300">{{ stat.label }}</p>
              <p class="mt-2 text-2xl font-bold text-white">{{ stat.value }}</p>
            </div>
          </div>
        </div>
      </section>

      <div v-if="successMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700">{{ successMessage }}</div>
      <div v-if="temporaryPassword" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
        <p class="font-bold">Temporary password for {{ temporaryPassword.admin?.email }}:</p>
        <p class="mt-2 inline-flex rounded-md bg-white px-3 py-2 font-mono text-base font-bold text-slate-950 ring-1 ring-amber-200">{{ temporaryPassword.password }}</p>
        <p class="mt-2 text-xs">Show this once to the shop admin, then ask them to change it after login.</p>
      </div>
      <div v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">{{ errorMessage }}</div>

      <section v-if="showShops" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div v-for="card in summaryCards" :key="card.label" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
          <p class="text-sm font-semibold text-slate-500">{{ card.label }}</p>
          <p class="mt-2 text-3xl font-bold leading-tight text-slate-950">{{ card.value }}</p>
          <p class="mt-2 text-xs text-slate-500">{{ card.hint }}</p>
        </div>
      </section>

      <section v-if="!showShops && dashboard" class="space-y-6">
        <div v-for="section in overviewSections" :key="section.title" class="space-y-3">
          <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <h3 class="text-lg font-bold text-slate-950">{{ section.title }}</h3>
              <p class="text-sm text-slate-500">{{ section.description }}</p>
            </div>
          </div>
          <div class="grid grid-cols-1 gap-4 md:grid-cols-2" :class="section.columns">
            <div
              v-for="card in section.cards"
              :key="`${section.title}-${card.label}`"
              class="rounded-xl border p-5 shadow-sm"
              :class="[overviewCardClass(card), card.wide ? 'xl:col-span-2' : '']"
            >
              <p class="text-sm font-semibold text-slate-600">{{ card.label }}</p>
              <p class="mt-2 text-3xl font-bold leading-tight text-slate-950">{{ card.value }}</p>
              <p class="mt-2 text-xs text-slate-500">{{ card.hint }}</p>
            </div>
          </div>
        </div>
      </section>

      <section v-if="!showShops && dashboard" class="grid grid-cols-1 gap-5 xl:grid-cols-[1.05fr_0.95fr]">
        <div class="admin-panel p-5">
          <div class="mb-4">
            <h3 class="text-lg font-bold text-slate-950">Subscription Payment Setup</h3>
            <p class="mt-1 text-sm text-slate-500">Plans and payment details shown to shops when they click Subscribe Now.</p>
          </div>
          <form class="space-y-4" @submit.prevent="updateSubscriptionSettings">
            <label class="block space-y-1.5">
              <span class="text-xs font-bold uppercase text-slate-500">Instructions</span>
              <textarea v-model="settingsForm.instructions" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
            </label>

            <div class="space-y-2">
              <div class="flex items-center justify-between gap-3">
                <span class="text-xs font-bold uppercase text-slate-500">Plans</span>
                <button type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="addPlan">Add Plan</button>
              </div>
              <div v-for="(plan, index) in settingsForm.plans" :key="`plan-${index}`" class="grid grid-cols-1 gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 md:grid-cols-[1fr_1fr_0.7fr_0.7fr_auto]">
                <input v-model="plan.key" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="monthly" />
                <input v-model="plan.name" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Monthly" />
                <input v-model="plan.amount" type="number" min="0.01" step="0.01" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Amount" />
                <input v-model="plan.duration_months" type="number" min="1" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Months" />
                <button type="button" class="rounded-lg border border-rose-200 px-3 py-2 text-xs font-bold text-rose-600 hover:bg-rose-50" @click="removePlan(index)">Remove</button>
              </div>
            </div>

            <div class="space-y-2">
              <div class="flex items-center justify-between gap-3">
                <span class="text-xs font-bold uppercase text-slate-500">Payment Methods</span>
                <button type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="addPaymentMethod">Add Method</button>
              </div>
              <div v-for="(method, index) in settingsForm.payment_methods" :key="`method-${index}`" class="grid grid-cols-1 gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 md:grid-cols-2">
                <input v-model="method.key" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="gcash" />
                <input v-model="method.name" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="GCash" />
                <input v-model="method.account_name" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Account name" />
                <input v-model="method.account_number" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Account number" />
                <input v-model="method.instructions" class="rounded-lg border border-slate-300 px-3 py-2 text-sm md:col-span-2" placeholder="Instructions" />
                <button type="button" class="w-fit rounded-lg border border-rose-200 px-3 py-2 text-xs font-bold text-rose-600 hover:bg-rose-50" @click="removePaymentMethod(index)">Remove Method</button>
              </div>
            </div>

            <div class="flex justify-end">
              <button type="submit" class="btn-primary px-4 py-2 text-sm">Save Subscription Setup</button>
            </div>
          </form>
        </div>

        <div class="admin-panel overflow-hidden">
          <div class="flex items-center justify-between gap-3 border-b border-slate-200 p-5">
            <div>
              <h3 class="text-lg font-bold text-slate-950">Subscription Requests</h3>
              <p class="mt-1 text-sm text-slate-500">Payment proofs waiting for verification.</p>
            </div>
            <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-700 ring-1 ring-amber-200">{{ subscriptionRequests.length }} pending</span>
          </div>

          <div v-if="subscriptionRequests.length" class="divide-y divide-slate-100">
            <div v-for="request in subscriptionRequests" :key="request.id" class="space-y-3 p-5">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <p class="font-bold text-slate-950">{{ request.shop_name }}</p>
                  <p class="mt-1 text-xs text-slate-500">{{ request.shop_slug }} - {{ request.requested_by?.email || 'No requester email' }}</p>
                </div>
                <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-bold uppercase text-amber-700 ring-1 ring-amber-200">{{ request.status }}</span>
              </div>
              <div class="rounded-lg bg-slate-50 p-3 text-sm text-slate-700">
                <p><strong>{{ request.plan_name }}</strong> - {{ formatCurrency(request.amount) }} / {{ request.duration_months }} month{{ request.duration_months === 1 ? '' : 's' }}</p>
                <p class="mt-1">Method: {{ request.payment_method_name }} <span v-if="request.reference_number">- Ref: {{ request.reference_number }}</span></p>
                <p class="mt-1 text-xs text-slate-500">Submitted {{ request.created_at_formatted }}</p>
              </div>
              <a v-if="request.proof_url" :href="request.proof_url" target="_blank" rel="noopener" class="inline-flex rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700 hover:border-teal-200 hover:text-teal-700">View Proof</a>
              <textarea v-model="subscriptionRequestNotes[request.id]" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Admin notes optional"></textarea>
              <div class="flex flex-wrap justify-end gap-2">
                <button type="button" class="rounded-lg border border-rose-200 px-3 py-2 text-xs font-bold text-rose-600 hover:bg-rose-50" @click="rejectSubscriptionRequest(request)">Reject</button>
                <button type="button" class="rounded-lg bg-teal-600 px-3 py-2 text-xs font-bold text-white hover:bg-teal-700" @click="approveSubscriptionRequest(request)">Approve & Activate</button>
              </div>
            </div>
          </div>
          <div v-else class="p-6 text-sm text-slate-500">No pending subscription requests.</div>
        </div>
      </section>

      <section v-if="!showShops && dashboard" class="admin-panel overflow-hidden">
        <div class="flex items-center justify-between gap-4 border-b border-slate-200 p-5">
          <div>
            <h3 class="text-lg font-bold text-slate-950">Recent Shop Workspaces</h3>
            <p class="mt-1 text-sm text-slate-500">Newest shops registered in the platform.</p>
          </div>
          <AppLink to="/platform/shops" class="btn-soft px-4 py-2">View All</AppLink>
        </div>
        <div class="overflow-x-auto">
          <table class="data-table">
            <thead>
              <tr>
                <th class="px-4 py-3 text-left text-slate-600">Shop</th>
                <th class="px-4 py-3 text-left text-slate-600">Plan</th>
                <th class="px-4 py-3 text-left text-slate-600">Shop Admin</th>
                <th class="px-4 py-3 text-left text-slate-600">Counts</th>
                <th class="px-4 py-3 text-left text-slate-600">Status</th>
                <th class="px-4 py-3 text-left text-slate-600">Public Link</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="shop in dashboard.recent_shops" :key="shop.id">
                <td class="px-4 py-3">
                  <p class="font-semibold text-slate-900">{{ shop.name }}</p>
                  <p class="text-xs text-slate-500">{{ shop.slug }} - {{ shop.email || 'No email' }}</p>
                </td>
                <td class="px-4 py-3 uppercase text-slate-700">{{ shop.subscription_plan }}</td>
                <td class="px-4 py-3">
                  <p class="font-semibold text-slate-900">{{ shop.admin?.name || 'No admin' }}</p>
                  <p class="text-xs text-slate-500">{{ shop.admin?.email || 'Not set' }}</p>
                </td>
                <td class="px-4 py-3 text-slate-700">{{ shop.users_count }} users - {{ shop.job_orders_count }} jobs</td>
                <td class="px-4 py-3">
                  <span class="rounded-full px-3 py-1 text-xs font-bold ring-1" :class="accessBadgeClass(shop)">
                    {{ shop.access_status_label }}
                  </span>
                  <p class="mt-2 max-w-48 text-xs leading-5 text-slate-500">{{ shop.access_status_help }}</p>
                </td>
                <td class="px-4 py-3">
                  <a :href="shop.public_tracking_url" target="_blank" rel="noopener" class="font-bold text-teal-700 hover:text-teal-800">Tracking</a>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="!showShops && dashboard" class="admin-panel overflow-hidden">
        <div class="flex flex-col gap-2 border-b border-slate-200 p-5 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h3 class="text-lg font-bold text-slate-950">Subscription Reminder History</h3>
            <p class="mt-1 text-sm text-slate-500">Recent platform emails queued for shop access renewals.</p>
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

      <section v-if="showShops && shops" class="space-y-5">
        <div class="admin-panel p-4">
          <form class="grid grid-cols-1 gap-3 md:grid-cols-[1.5fr_0.75fr_0.85fr_0.8fr_auto]" @submit.prevent="loadShops(1)">
            <input v-model="filters.search" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Search shop name, slug, email..." />
            <select v-model="filters.is_active" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option value="">All workspaces</option>
              <option value="1">Enabled</option>
              <option value="0">Disabled</option>
            </select>
            <select v-model="filters.access_status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option value="">All access states</option>
              <option value="active">Active Access</option>
              <option value="expiring">Expiring Soon</option>
              <option value="close_out">Close-out</option>
              <option value="needs_setup">Needs Setup</option>
              <option value="inactive">Inactive</option>
            </select>
            <select v-model="filters.subscription_plan" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option value="">All plans</option>
              <option v-for="plan in plans" :key="plan" :value="plan">{{ plan }}</option>
            </select>
            <div class="flex justify-end gap-2">
              <button type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold text-slate-700 hover:border-teal-200 hover:text-teal-700" @click="clearFilters">Clear</button>
              <button type="submit" class="btn-primary px-3 py-2 text-sm">Apply</button>
            </div>
          </form>
        </div>

        <div class="admin-panel overflow-hidden">
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
