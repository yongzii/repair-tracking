<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { adminRoles, authState, ensureRoles, logout } from '../composables/auth';
import { useNavigation, navigate } from '../navigation';
import AppLink from '../components/AppLink.vue';
import ChangePasswordButton from '../components/ChangePasswordButton.vue';
import { appConfig } from '../config';

const props = defineProps({
    title: {
        type: String,
        default: '',
    },
});

const { location } = useNavigation();
const mobileNavOpen = ref(false);
const pendingCustomerRequests = ref(null);
const overdueJobs = ref(null);
const alertsError = ref('');
const alertsLoading = ref(false);
let alertsRefreshInterval;

const normalizePath = (path) => {
    if (!path) return '/';
    if (path.length > 1 && path.endsWith('/')) return path.slice(0, -1);
    return path;
};

const currentPath = computed(() => normalizePath(location.path));

const isExactActive = (path) => currentPath.value === normalizePath(path);
const isSectionActive = (path) => {
    const normalized = normalizePath(path);
    return currentPath.value === normalized || currentPath.value.startsWith(`${normalized}/`);
};

const user = computed(() => authState.user);
const isAdmin = computed(() => adminRoles.includes(user.value?.role));
const canViewInventory = computed(() => [...adminRoles, 'staff'].includes(user.value?.role));
const shopName = computed(() => user.value?.shop?.name || appConfig.name);
const shopSlug = computed(() => user.value?.shop?.slug || '');
const shopLogo = computed(() => user.value?.shop?.logo_url || '');
const isCloseOutMode = computed(() => Boolean(user.value?.shop?.is_close_out_mode));
const isPreviewMode = computed(() => Boolean(user.value?.shop && !user.value.shop.has_active_access && !user.value.shop.is_close_out_mode));
const accessUntil = computed(() => user.value?.shop?.access_until_formatted || '');
const trackingPath = computed(() => shopSlug.value ? `/shops/${shopSlug.value}/track` : '/track');
const showSubscribeModal = ref(false);
const subscriptionLoading = ref(false);
const subscriptionSubmitting = ref(false);
const subscriptionError = ref('');
const subscriptionSuccess = ref('');
const subscriptionSettings = ref(null);
const pendingSubscriptionRequest = ref(null);
const subscriptionForm = ref({
    plan_key: '',
    payment_method_key: '',
    reference_number: '',
    proof: null,
});

const subscriptionPlans = computed(() => subscriptionSettings.value?.plans || []);
const subscriptionMethods = computed(() => subscriptionSettings.value?.payment_methods || []);
const selectedPlan = computed(() => subscriptionPlans.value.find((plan) => plan.key === subscriptionForm.value.plan_key));
const selectedMethod = computed(() => subscriptionMethods.value.find((method) => method.key === subscriptionForm.value.payment_method_key));

const loadAlerts = async () => {
    if (alertsLoading.value) return;

    alertsLoading.value = true;
    alertsError.value = '';
    try {
        const auth = await ensureRoles(['admin', 'staff']);
        if (!auth.ok) {
            pendingCustomerRequests.value = null;
            overdueJobs.value = null;
            return;
        }

        const { data } = await window.axios.get('/api/admin/alerts');
        pendingCustomerRequests.value = Number(data.pending_customer_requests || 0);
        overdueJobs.value = Number(data.overdue_jobs || 0);
    } catch (error) {
        pendingCustomerRequests.value = null;
        overdueJobs.value = null;
        alertsError.value = error?.response?.data?.message || 'Unable to load shop alerts.';
    } finally {
        alertsLoading.value = false;
    }
};

const formatCurrency = (value) => {
    const amount = Number(value || 0);
    return `PHP ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

const onLogout = async () => {
    await logout();
    navigate('/login', { replace: true });
};

onMounted(() => {
    loadAlerts();
    alertsRefreshInterval = window.setInterval(loadAlerts, 60_000);
    window.addEventListener('focus', loadAlerts);
});

onBeforeUnmount(() => {
    window.clearInterval(alertsRefreshInterval);
    window.removeEventListener('focus', loadAlerts);
});

watch(() => user.value?.role, (role) => {
    if ([...adminRoles, 'staff'].includes(role)) {
        loadAlerts();
    } else {
        pendingCustomerRequests.value = null;
        overdueJobs.value = null;
    }
});

const loadSubscriptionOptions = async () => {
    subscriptionLoading.value = true;
    subscriptionError.value = '';

    try {
        const { data } = await window.axios.get('/api/admin/subscription/options');
        subscriptionSettings.value = data.settings;
        pendingSubscriptionRequest.value = data.pending_request;
        if (!subscriptionForm.value.plan_key && data.settings?.plans?.[0]) {
            subscriptionForm.value.plan_key = data.settings.plans[0].key;
        }
        if (!subscriptionForm.value.payment_method_key && data.settings?.payment_methods?.[0]) {
            subscriptionForm.value.payment_method_key = data.settings.payment_methods[0].key;
        }
    } catch (error) {
        subscriptionError.value = error?.response?.data?.message || 'Unable to load subscription options.';
    } finally {
        subscriptionLoading.value = false;
    }
};

const openSubscribeModal = async () => {
    showSubscribeModal.value = true;
    subscriptionSuccess.value = '';
    if (!subscriptionSettings.value) {
        await loadSubscriptionOptions();
    }
};

const onProofChange = (event) => {
    subscriptionForm.value.proof = event.target.files?.[0] || null;
};

const submitSubscriptionRequest = async () => {
    subscriptionSubmitting.value = true;
    subscriptionError.value = '';
    subscriptionSuccess.value = '';

    try {
        const formData = new FormData();
        formData.append('plan_key', subscriptionForm.value.plan_key);
        formData.append('payment_method_key', subscriptionForm.value.payment_method_key);
        formData.append('reference_number', subscriptionForm.value.reference_number || '');
        if (subscriptionForm.value.proof) {
            formData.append('proof', subscriptionForm.value.proof);
        }

        const { data } = await window.axios.post('/api/admin/subscription/requests', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        pendingSubscriptionRequest.value = data.subscription_request;
        subscriptionSuccess.value = data.message || 'Subscription request submitted.';
        subscriptionForm.value.reference_number = '';
        subscriptionForm.value.proof = null;
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        subscriptionError.value = firstError || error?.response?.data?.message || 'Unable to submit subscription request.';
    } finally {
        subscriptionSubmitting.value = false;
    }
};
</script>

<template>
  <div class="app-shell flex min-h-screen">
    <button
      v-if="mobileNavOpen"
      type="button"
      class="fixed inset-0 z-40 bg-slate-950/55 backdrop-blur-sm lg:hidden"
      aria-label="Close navigation menu"
      @click="mobileNavOpen = false"
    ></button>
    <aside
      class="sidebar app-scrollbar fixed inset-y-0 left-0 z-50 flex h-screen min-h-screen w-72 shrink-0 flex-col overflow-hidden transition-transform duration-200 lg:sticky lg:z-auto lg:translate-x-0"
      :class="mobileNavOpen ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="border-b border-white/10 p-5">
        <AppLink to="/admin" class="flex items-start gap-3">
          <span class="mt-1 flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white text-sm font-bold text-slate-950">
            <img v-if="shopLogo" :src="shopLogo" alt="Shop logo" class="h-full w-full object-contain p-1.5" />
            <span v-else>{{ (shopName || 'RS').slice(0, 2).toUpperCase() }}</span>
          </span>
          <span class="min-w-0">
            <span class="block truncate text-xl font-semibold sidebar-title text-white">{{ shopName }}</span>
            <span class="mt-1 block text-sm text-slate-300">{{ appConfig.tagline }}</span>
          </span>
        </AppLink>
        <p v-if="shopSlug" class="mt-4 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs font-bold uppercase tracking-[0.15em] text-teal-200">Shop Code: {{ shopSlug }}</p>
        <p v-if="isPreviewMode" class="mt-3 rounded-lg border border-amber-300/30 bg-amber-300/10 px-3 py-2 text-xs font-bold uppercase tracking-[0.12em] text-amber-100">Preview Mode</p>
      </div>

      <nav id="admin-navigation" class="app-scrollbar mt-5 flex-1 space-y-1 overflow-y-auto pb-6" @click="mobileNavOpen = false">
        <AppLink to="/admin" class="nav-link" :class="isExactActive('/admin') ? 'nav-link-active' : ''">
          <i class="fas fa-chart-line w-5"></i>
          <span>Dashboard</span>
        </AppLink>

        <AppLink to="/admin/job-orders" class="nav-link" :class="isSectionActive('/admin/job-orders') ? 'nav-link-active' : ''">
          <i class="fas fa-briefcase w-5"></i>
          <span>Job Orders</span>
          <span
            v-if="overdueJobs"
            class="ml-auto inline-flex min-w-6 items-center justify-center rounded-full bg-amber-400 px-2 py-0.5 text-xs font-bold text-amber-950"
            :aria-label="`${overdueJobs} overdue jobs`"
            title="Overdue jobs"
          >{{ overdueJobs > 99 ? '99+' : overdueJobs }}</span>
          <span
            v-else-if="alertsError"
            class="ml-auto inline-flex h-5 w-5 items-center justify-center rounded-full bg-amber-400 text-xs font-bold text-slate-950"
            :title="alertsError"
            aria-label="Could not load alerts"
          >!</span>
        </AppLink>

        <AppLink to="/admin/part-requests" class="nav-link" :class="isSectionActive('/admin/part-requests') ? 'nav-link-active' : ''">
          <i class="fas fa-screwdriver-wrench w-5"></i>
          <span>For Order</span>
        </AppLink>

        <AppLink to="/admin/appointments" class="nav-link" :class="isSectionActive('/admin/appointments') ? 'nav-link-active' : ''">
          <i class="fas fa-calendar-check w-5"></i>
          <span>Customer Requests</span>
          <span
            v-if="pendingCustomerRequests"
            class="ml-auto inline-flex min-w-6 items-center justify-center rounded-full bg-rose-500 px-2 py-0.5 text-xs font-bold text-white"
            :aria-label="`${pendingCustomerRequests} pending customer requests`"
            title="Pending customer requests"
          >{{ pendingCustomerRequests > 99 ? '99+' : pendingCustomerRequests }}</span>
          <span
            v-else-if="alertsError && !overdueJobs"
            class="ml-auto inline-flex h-5 w-5 items-center justify-center rounded-full bg-amber-400 text-xs font-bold text-slate-950"
            :title="alertsError"
            aria-label="Could not load alerts"
          >!</span>
        </AppLink>

        <AppLink v-if="isAdmin" to="/admin/reports" class="nav-link" :class="isSectionActive('/admin/reports') ? 'nav-link-active' : ''">
          <i class="fas fa-file-chart-bar w-5"></i>
          <span>Reports</span>
        </AppLink>

        <AppLink v-if="canViewInventory" to="/admin/inventory" class="nav-link" :class="isSectionActive('/admin/inventory') ? 'nav-link-active' : ''">
          <i class="fas fa-boxes-stacked w-5"></i>
          <span>Inventory</span>
        </AppLink>

        <AppLink v-if="isAdmin" to="/admin/technicians" class="nav-link" :class="isSectionActive('/admin/technicians') ? 'nav-link-active' : ''">
          <i class="fas fa-users w-5"></i>
          <span>Technicians</span>
        </AppLink>

        <AppLink v-if="isAdmin" to="/admin/users" class="nav-link" :class="isSectionActive('/admin/users') ? 'nav-link-active' : ''">
          <i class="fas fa-user-shield w-5"></i>
          <span>User Management</span>
        </AppLink>

        <AppLink v-if="isAdmin" to="/admin/audit-logs" class="nav-link" :class="isSectionActive('/admin/audit-logs') ? 'nav-link-active' : ''">
          <i class="fas fa-clipboard-list w-5"></i>
          <span>Audit Logs</span>
        </AppLink>

        <AppLink v-if="isAdmin" to="/admin/settings/shop" class="nav-link" :class="isSectionActive('/admin/settings/shop') ? 'nav-link-active' : ''">
          <i class="fas fa-store w-5"></i>
          <span>Shop Settings</span>
        </AppLink>

        <AppLink :to="trackingPath" class="nav-link" :class="isSectionActive('/track') || isSectionActive('/shops') ? 'nav-link-active' : ''">
          <i class="fas fa-eye w-5"></i>
          <span>Customer Tracking</span>
        </AppLink>
      </nav>

      <div class="mt-auto border-t border-white/10 bg-black/20 p-5">
        <div class="mb-4 rounded-xl border border-white/10 bg-white/5 p-3" v-if="user">
          <p class="text-xs text-slate-300">Logged in as</p>
          <p class="mt-1 truncate text-sm font-bold text-white">{{ user.name }}</p>
          <p class="truncate text-xs text-slate-300">{{ user.email }}</p>
          <p class="mt-2 inline-flex rounded-full bg-teal-400/15 px-2 py-1 text-xs font-bold uppercase text-teal-200">{{ user.role }}</p>
          <p v-if="user.shop?.name" class="mt-2 truncate text-xs text-slate-300">{{ user.shop.name }}</p>
          <ChangePasswordButton />
        </div>
        <button type="button" class="flex w-full items-center justify-center rounded-lg bg-white/10 py-2 font-bold text-white hover:bg-white/20" @click="onLogout">
          <i class="fas fa-sign-out-alt mr-2"></i>
          Logout
        </button>
      </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col overflow-hidden">
      <div class="content-header sticky top-0 z-30 px-4 py-3 sm:px-6 sm:py-4">
        <div class="flex items-center justify-between gap-3">
          <div>
            <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-400">Operations</p>
            <h2 class="text-xl font-bold text-slate-950 sm:text-2xl">{{ title }}</h2>
          </div>
          <button
            type="button"
            class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 shadow-sm hover:border-teal-200 hover:text-teal-700 lg:hidden"
            :aria-expanded="mobileNavOpen"
            aria-controls="admin-navigation"
            :aria-label="mobileNavOpen ? 'Close navigation menu' : 'Open navigation menu'"
            @click="mobileNavOpen = !mobileNavOpen"
          >
            <i :class="mobileNavOpen ? 'fas fa-xmark' : 'fas fa-bars'" aria-hidden="true"></i>
          </button>
        </div>
      </div>

      <div class="app-scrollbar flex-1 overflow-auto">
        <div class="space-y-4 p-4 sm:space-y-5 sm:p-6">
          <div v-if="isCloseOutMode" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-bold">Shop access expired{{ accessUntil ? ` on ${accessUntil}` : '' }}.</p>
            <p class="mt-1">Close-out mode is active: existing job orders can still be completed and released, but new intake is locked until the subscription is renewed.</p>
          </div>
          <div v-if="isPreviewMode" class="flex flex-col gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900 sm:flex-row sm:items-center sm:justify-between">
            <span>Preview mode: subscription required before saving records or processing transactions.</span>
            <button type="button" class="shrink-0 rounded-lg bg-slate-950 px-4 py-2 text-xs font-bold uppercase tracking-[0.08em] text-white hover:bg-slate-800" @click="openSubscribeModal">
              Subscribe Now
            </button>
          </div>
          <div class="relative">
            <div :class="isPreviewMode ? 'blur-[3px] opacity-55' : ''" class="transition duration-200">
              <slot />
            </div>
            <div v-if="isPreviewMode" class="pointer-events-none absolute inset-x-0 top-6 flex justify-center">
              <span class="rounded-full bg-slate-950/85 px-4 py-2 text-xs font-bold uppercase tracking-[0.12em] text-white shadow-lg">
                Subscribe to unlock
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div v-if="showSubscribeModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 px-4 py-6 backdrop-blur-sm" @click.self="showSubscribeModal = false">
      <div class="w-full max-w-lg rounded-xl bg-white shadow-2xl shadow-slate-950/20">
        <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-5">
          <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-teal-700">Subscription</p>
            <h3 class="mt-1 text-xl font-bold text-slate-950">Activate {{ shopName }}</h3>
          </div>
          <button type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-bold text-slate-600 hover:bg-slate-50" @click="showSubscribeModal = false">Close</button>
        </div>

        <div class="space-y-4 px-6 py-5 text-sm text-slate-700">
          <p>{{ subscriptionSettings?.instructions || 'Choose a plan, pay using an available method, then upload proof for verification.' }}</p>

          <div v-if="subscriptionError" class="rounded-lg border border-rose-200 bg-rose-50 p-3 font-semibold text-rose-700">{{ subscriptionError }}</div>
          <div v-if="subscriptionSuccess" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 font-semibold text-emerald-700">{{ subscriptionSuccess }}</div>
          <div v-if="subscriptionLoading" class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-slate-500">Loading subscription options...</div>

          <div v-if="pendingSubscriptionRequest" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-900">
            <p class="font-bold">Pending verification</p>
            <p class="mt-1 text-xs">{{ pendingSubscriptionRequest.plan_name }} - {{ formatCurrency(pendingSubscriptionRequest.amount) }} via {{ pendingSubscriptionRequest.payment_method_name }}</p>
            <p class="mt-1 text-xs">Submitted {{ pendingSubscriptionRequest.created_at_formatted }}</p>
          </div>

          <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
            <p class="text-xs font-bold uppercase text-slate-500">Workspace Code</p>
            <p class="mt-1 font-bold text-slate-950">{{ shopSlug || 'Not assigned' }}</p>
          </div>

          <form class="space-y-4" @submit.prevent="submitSubscriptionRequest">
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
              <label v-for="plan in subscriptionPlans" :key="plan.key" class="cursor-pointer rounded-lg border p-3" :class="subscriptionForm.plan_key === plan.key ? 'border-teal-300 bg-teal-50 ring-2 ring-teal-100' : 'border-slate-200'">
                <input v-model="subscriptionForm.plan_key" type="radio" class="sr-only" :value="plan.key" />
                <p class="font-bold text-slate-950">{{ plan.name }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ formatCurrency(plan.amount) }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ plan.duration_months }} month{{ plan.duration_months === 1 ? '' : 's' }}</p>
              </label>
            </div>

            <label class="block space-y-2">
              <span class="text-xs font-bold uppercase text-slate-500">Payment Method</span>
              <select v-model="subscriptionForm.payment_method_key" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                <option v-for="method in subscriptionMethods" :key="method.key" :value="method.key">{{ method.name }}</option>
              </select>
            </label>

            <div v-if="selectedMethod" class="rounded-lg border border-slate-200 bg-slate-50 p-4">
              <p class="font-bold text-slate-950">{{ selectedMethod.name }}</p>
              <p v-if="selectedMethod.account_name" class="mt-1 text-xs text-slate-600">Account name: {{ selectedMethod.account_name }}</p>
              <p v-if="selectedMethod.account_number" class="mt-1 text-xs text-slate-600">Account number: {{ selectedMethod.account_number }}</p>
              <p v-if="selectedMethod.instructions" class="mt-2 text-xs text-slate-500">{{ selectedMethod.instructions }}</p>
            </div>

            <div v-if="selectedPlan" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-900">
              <p class="font-bold">Amount to pay: {{ formatCurrency(selectedPlan.amount) }}</p>
              <p class="mt-1 text-xs">After payment verification, your workspace will be activated by the platform admin.</p>
            </div>

            <label class="block space-y-2">
              <span class="text-xs font-bold uppercase text-slate-500">Reference Number</span>
              <input v-model="subscriptionForm.reference_number" class="w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="GCash / bank reference optional" />
            </label>

            <label class="block space-y-2">
              <span class="text-xs font-bold uppercase text-slate-500">Proof of Payment</span>
              <input type="file" accept="image/*,.pdf" class="w-full rounded-lg border border-slate-300 px-3 py-2" :required="!pendingSubscriptionRequest" @change="onProofChange" />
            </label>

            <button type="submit" class="w-full rounded-lg bg-teal-600 px-4 py-3 font-bold text-white hover:bg-teal-700 disabled:opacity-60" :disabled="subscriptionSubmitting || subscriptionLoading">
              {{ subscriptionSubmitting ? 'Submitting...' : 'Submit Payment Proof' }}
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>
