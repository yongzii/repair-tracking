<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import { authState, ensureAdmin } from '../../composables/auth';
import { navigate } from '../../navigation';

const loading = ref(true);
const forbidden = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const shop = ref(null);
const subscriptionRequest = ref(null);
const subscriptionRequestLoading = ref(true);
const subscriptionRequestError = ref('');
const logoFile = ref(null);
const uploadingLogo = ref(false);
const serviceCatalog = ref([]);
const selectedServiceCategoryId = ref(null);
const editingServiceCategoryId = ref(null);
const editingServiceItemId = ref(null);
const addCategoryOpen = ref(false);
const addServiceOpen = ref(false);
const savingCatalog = ref(false);
const categoryForm = reactive({
    name: '',
    description: '',
});
const serviceForms = reactive({});

const form = reactive({
    name: '',
    slug: '',
    email: '',
    phone: '',
    address: '',
    description: '',
    business_hours: {},
    business_hours_configured: false,
    claim_terms: '',
    warranty_terms: '',
    notification_settings: {
        email_enabled: true,
        sms_enabled: false,
        events: {
            RECEIVED: true,
            DIAGNOSING: true,
            WAITING_PARTS: true,
            IN_PROGRESS: true,
            READY_FOR_PICKUP: true,
            PICKED_UP: true,
            RTO: true,
            RTO_RELEASED: true,
        },
    },
});

const notificationEvents = [
    { key: 'RECEIVED', label: 'Job received' },
    { key: 'DIAGNOSING', label: 'Diagnosing' },
    { key: 'WAITING_PARTS', label: 'Waiting for parts' },
    { key: 'IN_PROGRESS', label: 'Repair in progress' },
    { key: 'READY_FOR_PICKUP', label: 'Ready for pickup' },
    { key: 'PICKED_UP', label: 'RELEASED' },
    { key: 'RTO', label: 'RTO awaiting return' },
    { key: 'RTO_RELEASED', label: 'RTO released' },
];

const businessDays = [
    { key: 'monday', label: 'Mon' },
    { key: 'tuesday', label: 'Tue' },
    { key: 'wednesday', label: 'Wed' },
    { key: 'thursday', label: 'Thu' },
    { key: 'friday', label: 'Fri' },
    { key: 'saturday', label: 'Sat' },
    { key: 'sunday', label: 'Sun' },
];

const trackingUrl = computed(() => shop.value?.public_tracking_url || '');
const bookingUrl = computed(() => shop.value?.public_booking_url || '');
const consultationUrl = computed(() => shop.value?.public_consultation_url || '');
const subscriptionRequestStatusClass = computed(() => ({
    pending: 'bg-amber-50 text-amber-700 ring-amber-200',
    approved: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    rejected: 'bg-rose-50 text-rose-700 ring-rose-200',
}[subscriptionRequest.value?.status] || 'bg-slate-100 text-slate-700 ring-slate-200'));
const formatCurrency = (amount) => `PHP ${Number(amount || 0).toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
})}`;
const onBusinessHoursToggle = () => {
    if (form.business_hours_configured && !Object.keys(form.business_hours).length) {
        Object.assign(form.business_hours, Object.fromEntries(
            businessDays.map((day) => [day.key, {
                label: day.label,
                open: false,
                open_time: '',
                close_time: '',
            }])
        ));
    }
};
const publicLinks = computed(() => [
    {
        key: 'tracking',
        label: 'Tracking',
        description: 'For customers with an existing job order number.',
        url: trackingUrl.value,
        empty: 'Tracking link unavailable.',
    },
    {
        key: 'booking',
        label: 'Drop-off Booking',
        description: 'For customers scheduling a device drop-off.',
        url: bookingUrl.value,
        empty: 'Drop-off booking link unavailable.',
    },
    {
        key: 'consultation',
        label: 'Free Consultation',
        description: 'For customers asking questions before visiting.',
        url: consultationUrl.value,
        empty: 'Consultation link unavailable.',
    },
]);

const copyPublicLink = async (link) => {
    if (!link.url) return;

    errorMessage.value = '';
    try {
        await navigator.clipboard.writeText(link.url);
        successMessage.value = `${link.label} link copied.`;
    } catch {
        errorMessage.value = 'Unable to copy link. Please copy it manually.';
    }
};

const loadSubscriptionRequest = async () => {
    subscriptionRequestLoading.value = true;
    subscriptionRequestError.value = '';

    try {
        const { data } = await window.axios.get('/api/admin/subscription/request');
        subscriptionRequest.value = data.latest_request;
    } catch (error) {
        subscriptionRequestError.value = error?.response?.data?.message || 'Unable to load subscription request status.';
    } finally {
        subscriptionRequestLoading.value = false;
    }
};

const normalizeBusinessHours = (hours = {}) => Object.fromEntries(
    businessDays.map((day) => {
        const current = hours?.[day.key] || {};

        return [
            day.key,
            {
                label: current.label || day.label,
                open: current.open ?? false,
                open_time: current.open_time ?? '',
                close_time: current.close_time ?? '',
            },
        ];
    })
);

const refreshServiceCatalog = (catalog = []) => {
    serviceCatalog.value = catalog || [];
    serviceCatalog.value.forEach((category) => {
        if (!serviceForms[category.id]) {
            serviceForms[category.id] = { name: '', description: '' };
        }
    });
    if (!selectedServiceCategoryId.value || !serviceCatalog.value.some((category) => category.id === selectedServiceCategoryId.value)) {
        selectedServiceCategoryId.value = serviceCatalog.value[0]?.id || null;
    }
};

const selectedServiceCategory = computed(() => serviceCatalog.value.find((category) => category.id === selectedServiceCategoryId.value) || null);

const openCategoryEditor = () => {
    editingServiceCategoryId.value = selectedServiceCategory.value?.id || null;
    addCategoryOpen.value = false;
};

const closeCategoryEditor = () => {
    editingServiceCategoryId.value = null;
};

const openServiceEditor = (service) => {
    editingServiceItemId.value = service.id;
    addServiceOpen.value = false;
};

const closeServiceEditor = () => {
    editingServiceItemId.value = null;
};

const loadShop = async () => {
    const auth = await ensureAdmin();
    if (!auth.ok) {
        if (auth.reason === 'unauthenticated') {
            navigate('/login', { replace: true });
            return;
        }
        forbidden.value = true;
        loading.value = false;
        return;
    }

    loading.value = true;
    try {
        const { data } = await window.axios.get('/api/admin/shop');
        shop.value = data.shop;
        refreshServiceCatalog(data.shop.service_catalog);
        Object.assign(form, {
            name: data.shop.name || '',
            slug: data.shop.slug || '',
            email: data.shop.email || '',
            phone: data.shop.phone || '',
            address: data.shop.address || '',
            description: data.shop.description || '',
            business_hours: data.shop.business_hours_configured ? normalizeBusinessHours(data.shop.business_hours) : {},
            business_hours_configured: Boolean(data.shop.business_hours_configured),
            claim_terms: data.shop.claim_terms || '',
            warranty_terms: data.shop.warranty_terms || '',
            notification_settings: normalizeNotificationSettings(data.shop.notification_settings),
        });
        loadSubscriptionRequest();
    } catch (error) {
        forbidden.value = true;
        errorMessage.value = error?.response?.data?.message || 'Unable to load shop settings.';
    } finally {
        loading.value = false;
    }
};

const saveShop = async () => {
    errorMessage.value = '';
    successMessage.value = '';

    try {
        const { data } = await window.axios.put('/api/admin/shop', {
            name: form.name,
            slug: form.slug,
            email: form.email || null,
            phone: form.phone || null,
            address: form.address || null,
            description: form.description.trim() || null,
            business_hours: form.business_hours_configured ? normalizeBusinessHours(form.business_hours) : null,
            claim_terms: form.claim_terms || null,
            warranty_terms: form.warranty_terms || null,
            notification_settings: normalizeNotificationSettings(form.notification_settings),
        });

        shop.value = data.shop;
        refreshServiceCatalog(data.shop.service_catalog);
        form.business_hours_configured = Boolean(data.shop.business_hours_configured);
        form.business_hours = data.shop.business_hours_configured
            ? normalizeBusinessHours(data.shop.business_hours)
            : {};
        if (authState.user?.shop) {
            authState.user.shop = {
                ...authState.user.shop,
                id: data.shop.id,
                name: data.shop.name,
                slug: data.shop.slug,
                logo_url: data.shop.logo_url,
            };
        }
        successMessage.value = data.message || 'Shop settings updated successfully.';
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to save shop settings.';
    }
};

const addServiceCategory = async () => {
    if (!categoryForm.name.trim()) return;

    errorMessage.value = '';
    successMessage.value = '';
    savingCatalog.value = true;

    try {
        const { data } = await window.axios.post('/api/admin/shop/service-categories', {
            name: categoryForm.name.trim(),
            description: categoryForm.description.trim() || null,
        });
        refreshServiceCatalog(data.service_catalog);
        categoryForm.name = '';
        categoryForm.description = '';
        addCategoryOpen.value = false;
        successMessage.value = data.message || 'Service category added.';
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to add service category.';
    } finally {
        savingCatalog.value = false;
    }
};

const updateServiceCategory = async (category) => {
    errorMessage.value = '';
    successMessage.value = '';
    savingCatalog.value = true;

    try {
        const { data } = await window.axios.put(`/api/admin/shop/service-categories/${category.id}`, {
            name: category.name,
            description: category.description || null,
            sort_order: category.sort_order || 0,
            is_active: category.is_active,
        });
        refreshServiceCatalog(data.service_catalog);
        closeCategoryEditor();
        successMessage.value = data.message || 'Service category updated.';
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to update service category.';
    } finally {
        savingCatalog.value = false;
    }
};

const deactivateServiceCategory = async (category) => {
    if (!window.confirm(`Deactivate ${category.name} and its services?`)) return;

    errorMessage.value = '';
    successMessage.value = '';
    savingCatalog.value = true;

    try {
        const { data } = await window.axios.delete(`/api/admin/shop/service-categories/${category.id}`);
        refreshServiceCatalog(data.service_catalog);
        successMessage.value = data.message || 'Service category deactivated.';
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Unable to deactivate service category.';
    } finally {
        savingCatalog.value = false;
    }
};

const addServiceItem = async (category) => {
    const serviceForm = serviceForms[category.id];
    if (!serviceForm?.name?.trim()) return;

    errorMessage.value = '';
    successMessage.value = '';
    savingCatalog.value = true;

    try {
        const { data } = await window.axios.post(`/api/admin/shop/service-categories/${category.id}/services`, {
            name: serviceForm.name.trim(),
            description: serviceForm.description.trim() || null,
        });
        refreshServiceCatalog(data.service_catalog);
        serviceForm.name = '';
        serviceForm.description = '';
        addServiceOpen.value = false;
        successMessage.value = data.message || 'Service added.';
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to add service.';
    } finally {
        savingCatalog.value = false;
    }
};

const updateServiceItem = async (service) => {
    errorMessage.value = '';
    successMessage.value = '';
    savingCatalog.value = true;

    try {
        const { data } = await window.axios.put(`/api/admin/shop/services/${service.id}`, {
            name: service.name,
            description: service.description || null,
            sort_order: service.sort_order || 0,
            is_active: service.is_active,
        });
        refreshServiceCatalog(data.service_catalog);
        closeServiceEditor();
        successMessage.value = data.message || 'Service updated.';
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to update service.';
    } finally {
        savingCatalog.value = false;
    }
};

const deactivateServiceItem = async (service) => {
    if (!window.confirm(`Deactivate ${service.name}?`)) return;

    errorMessage.value = '';
    successMessage.value = '';
    savingCatalog.value = true;

    try {
        const { data } = await window.axios.delete(`/api/admin/shop/services/${service.id}`);
        refreshServiceCatalog(data.service_catalog);
        successMessage.value = data.message || 'Service deactivated.';
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Unable to deactivate service.';
    } finally {
        savingCatalog.value = false;
    }
};

const selectLogo = (event) => {
    logoFile.value = event.target.files?.[0] || null;
};

const uploadLogo = async () => {
    if (!logoFile.value) return;

    errorMessage.value = '';
    successMessage.value = '';
    uploadingLogo.value = true;

    const payload = new FormData();
    payload.append('logo', logoFile.value);

    try {
        const { data } = await window.axios.post('/api/admin/shop/logo', payload, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        shop.value = data.shop;
        if (authState.user?.shop) {
            authState.user.shop = {
                ...authState.user.shop,
                logo_url: data.shop.logo_url,
            };
        }
        logoFile.value = null;
        successMessage.value = data.message || 'Shop logo uploaded successfully.';
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to upload shop logo.';
    } finally {
        uploadingLogo.value = false;
    }
};

const deleteLogo = async () => {
    errorMessage.value = '';
    successMessage.value = '';
    uploadingLogo.value = true;

    try {
        const { data } = await window.axios.delete('/api/admin/shop/logo');
        shop.value = data.shop;
        if (authState.user?.shop) {
            authState.user.shop = {
                ...authState.user.shop,
                logo_url: null,
            };
        }
        successMessage.value = data.message || 'Shop logo removed.';
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Unable to remove shop logo.';
    } finally {
        uploadingLogo.value = false;
    }
};

const normalizeNotificationSettings = (settings = {}) => ({
    email_enabled: settings.email_enabled ?? true,
    sms_enabled: settings.sms_enabled ?? false,
    events: Object.fromEntries(
        notificationEvents.map((event) => [
            event.key,
            settings.events?.[event.key] ?? true,
        ])
    ),
});

onMounted(loadShop);
</script>

<template>
  <AdminLayout title="Shop Settings">
    <div v-if="loading" class="card p-6 text-slate-600">Loading shop settings...</div>
    <div v-else-if="forbidden" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">{{ errorMessage || 'You do not have access to this page.' }}</div>

    <div v-else class="space-y-6">
      <section class="admin-hero p-6">
        <p class="text-xs font-bold uppercase text-teal-700">Workspace configuration</p>
        <h3 class="mt-2 font-display text-3xl">Shop Settings</h3>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Manage business profile details and public customer links for tracking and consultation.</p>
      </section>

      <div v-if="successMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700">{{ successMessage }}</div>
      <div v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">{{ errorMessage }}</div>

      <section class="admin-panel p-5 sm:p-6" aria-labelledby="subscription-request-heading">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <h3 id="subscription-request-heading" class="text-lg font-bold text-slate-950">Latest Subscription Request</h3>
            <p class="mt-1 text-sm text-slate-500">Review the status and details of your shop's most recent subscription request.</p>
          </div>
          <div class="flex items-center gap-3">
            <button
              type="button"
              class="text-sm font-semibold text-teal-700 hover:text-teal-800 disabled:opacity-50"
              :disabled="subscriptionRequestLoading"
              @click="loadSubscriptionRequest"
            >Refresh status</button>
            <span
              v-if="subscriptionRequest"
              class="inline-flex w-fit rounded-full px-3 py-1 text-xs font-bold uppercase ring-1"
              :class="subscriptionRequestStatusClass"
            >{{ subscriptionRequest.status }}</span>
          </div>
        </div>

        <p v-if="subscriptionRequestLoading" class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-500" role="status">
          Loading subscription request...
        </p>
        <div v-else-if="subscriptionRequestError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700" role="alert">
          {{ subscriptionRequestError }}
        </div>
        <div v-else-if="subscriptionRequest" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
            <p class="text-xs font-bold uppercase text-slate-500">Plan</p>
            <p class="mt-1 font-semibold text-slate-900">{{ subscriptionRequest.plan_name }}</p>
          </div>
          <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
            <p class="text-xs font-bold uppercase text-slate-500">Amount</p>
            <p class="mt-1 font-semibold text-slate-900">{{ formatCurrency(subscriptionRequest.amount) }}</p>
          </div>
          <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
            <p class="text-xs font-bold uppercase text-slate-500">Payment Method</p>
            <p class="mt-1 font-semibold text-slate-900">{{ subscriptionRequest.payment_method_name }}</p>
          </div>
          <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
            <p class="text-xs font-bold uppercase text-slate-500">Submitted</p>
            <p class="mt-1 font-semibold text-slate-900">{{ subscriptionRequest.created_at_formatted || 'Date unavailable' }}</p>
          </div>
          <div v-if="subscriptionRequest.reviewed_at_formatted" class="rounded-lg border border-slate-200 bg-slate-50 p-3 sm:col-span-2">
            <p class="text-xs font-bold uppercase text-slate-500">Reviewed</p>
            <p class="mt-1 font-semibold text-slate-900">{{ subscriptionRequest.reviewed_at_formatted }}</p>
          </div>
          <div class="sm:col-span-2 lg:col-span-4">
            <a
              v-if="subscriptionRequest.proof_url"
              :href="subscriptionRequest.proof_url"
              target="_blank"
              rel="noopener noreferrer"
              class="inline-flex min-h-10 items-center rounded-lg border border-teal-200 bg-white px-4 py-2 text-sm font-bold text-teal-700 hover:bg-teal-50"
            >View Payment Proof</a>
            <p v-else class="text-sm text-slate-500">No payment proof available for this request.</p>
          </div>
        </div>
        <p v-else class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">
          No subscription requests have been submitted yet.
        </p>
      </section>

      <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">
        <div class="admin-panel p-4 xl:col-span-2">
          <div class="flex items-center justify-between mb-3">
            <div>
              <h3 class="text-lg font-bold text-slate-950">Business Profile</h3>
              <p class="text-xs text-slate-500">This information represents your shop across the workspace.</p>
            </div>
          </div>

          <form class="grid grid-cols-1 gap-3 md:grid-cols-2" @submit.prevent="saveShop">
            <label class="space-y-1.5">
              <span class="text-xs font-bold uppercase text-slate-500">Shop Name</span>
              <input v-model="form.name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
            </label>

            <label class="space-y-1.5">
              <span class="text-xs font-bold uppercase text-slate-500">Shop Code / Slug</span>
              <input v-model="form.slug" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="repair-hub" required />
            </label>

            <label class="space-y-1.5">
              <span class="text-xs font-bold uppercase text-slate-500">Email</span>
              <input v-model="form.email" type="email" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            </label>

            <label class="space-y-1.5">
              <span class="text-xs font-bold uppercase text-slate-500">Phone</span>
              <input v-model="form.phone" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            </label>

            <label class="space-y-1.5 md:col-span-2">
              <span class="text-xs font-bold uppercase text-slate-500">Address</span>
              <input v-model="form.address" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            </label>

            <label class="space-y-1.5 md:col-span-2">
              <span class="text-xs font-bold uppercase text-slate-500">Shop Description <span class="font-normal normal-case text-slate-400">(Optional)</span></span>
              <textarea
                v-model="form.description"
                rows="4"
                maxlength="5000"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100"
                placeholder="Tell customers about your shop and the repair services you provide."
              ></textarea>
              <span class="text-xs text-slate-500">Shown on your public shop profile when provided.</span>
            </label>

            <section class="md:col-span-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
              <div class="mb-3 flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                <div>
                  <h4 class="font-bold text-slate-950">Business Hours</h4>
                  <p class="text-xs text-slate-500">Shown in customer pages.</p>
                </div>
                <label class="flex items-center gap-2 text-sm font-semibold text-slate-700">
                  <input v-model="form.business_hours_configured" type="checkbox" class="rounded border-slate-300 text-teal-600 focus:ring-teal-500" @change="onBusinessHoursToggle" />
                  Set business hours
                </label>
              </div>
              <p v-if="!form.business_hours_configured" class="rounded-lg bg-white p-3 text-sm text-slate-600">Business hours are not configured. Enable this option to publish the shop's schedule.</p>
              <template v-else>
                <p class="mb-3 text-xs text-slate-500">Select each open day and enter its hours. Days left unchecked will be shown as closed.</p>
                <div class="grid grid-cols-1 gap-2 lg:grid-cols-2">
                  <div v-for="day in businessDays" :key="day.key" class="grid grid-cols-[74px_1fr_1fr] items-center gap-2 rounded-lg border border-slate-200 bg-white p-2">
                    <label class="flex items-center gap-2 text-xs font-bold text-slate-800">
                      <input v-model="form.business_hours[day.key].open" type="checkbox" class="rounded border-slate-300 text-teal-600 focus:ring-teal-500" />
                      {{ day.label }}
                    </label>
                    <input v-model="form.business_hours[day.key].open_time" type="time" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-xs disabled:bg-slate-100 disabled:text-slate-400" :disabled="!form.business_hours[day.key].open" :required="form.business_hours[day.key].open" />
                    <input v-model="form.business_hours[day.key].close_time" type="time" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-xs disabled:bg-slate-100 disabled:text-slate-400" :disabled="!form.business_hours[day.key].open" :required="form.business_hours[day.key].open" />
                  </div>
                </div>
              </template>
            </section>

            <label class="space-y-1.5">
              <span class="text-xs font-bold uppercase text-slate-500">Claim Stub Terms</span>
              <textarea v-model="form.claim_terms" rows="4" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="One term per line."></textarea>
              <span class="text-xs leading-5 text-slate-500">Customize the terms displayed on printed Claim Stubs. Leave empty to use the system default terms.</span>
            </label>

            <label class="space-y-1.5">
              <span class="text-xs font-bold uppercase text-slate-500">Warranty Slip Terms</span>
              <textarea v-model="form.warranty_terms" rows="4" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="One term per line."></textarea>
              <span class="text-xs leading-5 text-slate-500">Customize the terms displayed on printed Warranty Slips. Leave empty to use the system default terms. These terms do not determine an individual Job Order's warranty duration.</span>
            </label>

            <section class="md:col-span-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
              <div class="mb-3 flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                <div>
                  <h4 class="font-bold text-slate-950">Notifications</h4>
                  <p class="mt-1 text-xs text-slate-500">Control which customer updates this shop sends automatically.</p>
                </div>
                <span
                  class="inline-flex w-fit rounded-full px-3 py-1 text-xs font-bold ring-1"
                  :class="shop?.notification_capabilities?.sms_configured ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-amber-50 text-amber-700 ring-amber-200'"
                >
                  {{ shop?.notification_capabilities?.sms_configured ? 'SMS configured' : 'SMS not configured' }}
                </span>
              </div>

              <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                <label class="flex items-start gap-2 rounded-lg border border-slate-200 bg-white p-2.5 text-sm">
                  <input v-model="form.notification_settings.email_enabled" type="checkbox" class="mt-1 rounded border-slate-300 text-teal-600 focus:ring-teal-500" />
                  <span>
                    <span class="block font-bold text-slate-800">Email notifications</span>
                    <span class="text-slate-500">Send updates to the customer's email when available.</span>
                  </span>
                </label>
                <label class="flex items-start gap-2 rounded-lg border border-slate-200 bg-white p-2.5 text-sm">
                  <input v-model="form.notification_settings.sms_enabled" type="checkbox" class="mt-1 rounded border-slate-300 text-teal-600 focus:ring-teal-500" />
                  <span>
                    <span class="block font-bold text-slate-800">SMS notifications</span>
                    <span class="text-slate-500">Send updates to the customer's phone when Twilio is ready.</span>
                  </span>
                </label>
              </div>

              <div v-if="form.notification_settings.sms_enabled && !shop?.notification_capabilities?.sms_configured" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                SMS is enabled for this shop, but Twilio is not fully configured yet. SMS records may fail until Twilio credentials and sender number are ready.
              </div>

              <div class="mt-3">
                <p class="mb-2 text-xs font-bold uppercase text-slate-400">Notify Customer When</p>
                <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                  <label v-for="event in notificationEvents" :key="event.key" class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm font-semibold text-slate-700">
                    <input v-model="form.notification_settings.events[event.key]" type="checkbox" class="rounded border-slate-300 text-teal-600 focus:ring-teal-500" />
                    {{ event.label }}
                  </label>
                </div>
              </div>
            </section>

            <section class="md:col-span-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
              <div class="mb-3">
                <h4 class="font-bold text-slate-950">Service Catalog</h4>
                <p class="mt-1 text-xs text-slate-500">Group services by device category. Staff and customers can pick from this list.</p>
              </div>

              <div class="rounded-lg border border-slate-200 bg-white p-3">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
                  <label class="min-w-0 flex-1 space-y-1.5">
                    <span class="text-xs font-bold uppercase text-slate-400">Device Category</span>
                    <select v-model="selectedServiceCategoryId" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-900">
                      <option v-for="category in serviceCatalog" :key="category.id" :value="category.id">
                        {{ category.name }} ({{ category.services.length }})
                      </option>
                    </select>
                  </label>
                  <div class="flex flex-wrap gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold text-slate-700 hover:border-teal-200 hover:text-teal-700" @click="addCategoryOpen = !addCategoryOpen">Add Category</button>
                    <button v-if="selectedServiceCategory" type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold text-slate-700 hover:border-teal-200 hover:text-teal-700" @click="openCategoryEditor">Edit Category</button>
                    <button v-if="selectedServiceCategory" type="button" class="rounded-lg bg-slate-950 px-3 py-2 text-sm font-bold text-white hover:bg-slate-800" @click="addServiceOpen = !addServiceOpen">Add Service</button>
                  </div>
                </div>

                <div v-if="addCategoryOpen" class="mt-4 grid grid-cols-1 gap-3 rounded-lg bg-slate-50 p-3 md:grid-cols-[1fr_1.5fr_auto]">
                  <input v-model="categoryForm.name" class="rounded-lg border border-slate-300 px-3 py-2" placeholder="Laptop, Cellphone, CCTV..." />
                  <input v-model="categoryForm.description" class="rounded-lg border border-slate-300 px-3 py-2" placeholder="Description optional" />
                  <button type="button" class="btn-primary px-4 py-2 disabled:opacity-60" :disabled="savingCatalog || !categoryForm.name.trim()" @click="addServiceCategory">Save Category</button>
                </div>

                <div v-if="selectedServiceCategory && editingServiceCategoryId === selectedServiceCategory.id" class="mt-4 grid grid-cols-1 gap-3 rounded-lg bg-slate-50 p-3 lg:grid-cols-[1fr_1.4fr_auto_auto] lg:items-center">
                  <input v-model="selectedServiceCategory.name" class="rounded-lg border border-slate-300 px-3 py-2 font-bold text-slate-900" />
                  <input v-model="selectedServiceCategory.description" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Description optional" />
                  <label class="flex items-center gap-2 text-sm font-semibold text-slate-700">
                    <input v-model="selectedServiceCategory.is_active" type="checkbox" class="rounded border-slate-300 text-teal-600 focus:ring-teal-500" />
                    Active
                  </label>
                  <div class="flex gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-700 disabled:opacity-60" :disabled="savingCatalog" @click="updateServiceCategory(selectedServiceCategory)">Save</button>
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-700" @click="closeCategoryEditor">Close</button>
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-rose-600 disabled:opacity-60" :disabled="savingCatalog" @click="deactivateServiceCategory(selectedServiceCategory)">Off</button>
                  </div>
                </div>

                <div v-if="selectedServiceCategory" class="mt-4">
                  <div class="mb-2 flex items-center justify-between gap-3">
                    <p class="text-sm font-bold text-slate-900">{{ selectedServiceCategory.name }} Services</p>
                    <p class="text-xs text-slate-500">{{ selectedServiceCategory.services.length }} offered</p>
                  </div>

                  <div class="max-h-72 overflow-y-auto rounded-lg border border-slate-200">
                    <div v-for="service in selectedServiceCategory.services" :key="service.id" class="border-b border-slate-100 p-3 last:border-b-0">
                      <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                          <p class="font-semibold text-slate-900">{{ service.name }}</p>
                          <p v-if="service.description" class="mt-1 text-xs text-slate-500">{{ service.description }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                          <span class="rounded-full px-2 py-1 text-xs font-bold" :class="service.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'">{{ service.is_active ? 'Active' : 'Off' }}</span>
                          <button type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700 hover:border-teal-200 hover:text-teal-700" @click="openServiceEditor(service)">Edit</button>
                        </div>
                      </div>

                      <div v-if="editingServiceItemId === service.id" class="mt-3 grid grid-cols-1 gap-2 rounded-lg bg-slate-50 p-3 md:grid-cols-[1fr_1.3fr_auto_auto] md:items-center">
                        <input v-model="service.name" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold" />
                        <input v-model="service.description" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Description optional" />
                        <label class="flex items-center gap-2 text-sm font-semibold text-slate-700">
                          <input v-model="service.is_active" type="checkbox" class="rounded border-slate-300 text-teal-600 focus:ring-teal-500" />
                          Active
                        </label>
                        <div class="flex gap-2">
                          <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-700 disabled:opacity-60" :disabled="savingCatalog" @click="updateServiceItem(service)">Save</button>
                          <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-700" @click="closeServiceEditor">Close</button>
                          <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-rose-600 disabled:opacity-60" :disabled="savingCatalog" @click="deactivateServiceItem(service)">Off</button>
                        </div>
                      </div>
                    </div>
                    <p v-if="!selectedServiceCategory.services.length" class="p-4 text-sm text-slate-500">No services under this category yet.</p>
                  </div>

                  <div v-if="addServiceOpen" class="mt-3 grid grid-cols-1 gap-2 rounded-lg bg-slate-50 p-3 md:grid-cols-[1fr_1.5fr_auto]">
                    <input v-model="serviceForms[selectedServiceCategory.id].name" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="LCD Replacement, Battery Replacement..." />
                    <input v-model="serviceForms[selectedServiceCategory.id].description" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Description optional" />
                    <button type="button" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-60" :disabled="savingCatalog || !serviceForms[selectedServiceCategory.id]?.name?.trim()" @click="addServiceItem(selectedServiceCategory)">Save Service</button>
                  </div>
                </div>
              </div>
            </section>

            <div class="md:col-span-2 flex justify-end">
              <button type="submit" class="btn-primary px-5 py-3">Save Shop Settings</button>
            </div>
          </form>
        </div>

        <div class="space-y-6">
          <div class="admin-panel p-6">
            <h3 class="text-lg font-bold text-slate-950">Shop Logo</h3>
            <p class="mt-2 text-sm text-slate-500">Use a clear square or horizontal logo for slips, tracking, and customer pages.</p>

            <div class="mt-4 flex items-center gap-4">
              <div class="flex h-20 w-20 items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
                <img v-if="shop?.logo_url" :src="shop.logo_url" alt="Shop logo" class="h-full w-full object-contain p-2" />
                <span v-else class="text-sm font-black text-slate-400">{{ (shop?.name || 'RS').slice(0, 2).toUpperCase() }}</span>
              </div>
              <div class="min-w-0 flex-1">
                <input type="file" accept="image/png,image/jpeg,image/webp" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" @change="selectLogo" />
                <p class="mt-2 text-xs text-slate-500">PNG, JPG, or WebP. Max 4 MB.</p>
              </div>
            </div>

            <div class="mt-4 flex flex-wrap gap-3">
              <button type="button" class="btn-primary px-4 py-2 disabled:opacity-60" :disabled="!logoFile || uploadingLogo" @click="uploadLogo">
                {{ uploadingLogo ? 'Uploading...' : 'Upload Logo' }}
              </button>
              <button v-if="shop?.logo_url" type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-bold text-slate-700 hover:border-rose-200 hover:text-rose-700 disabled:opacity-60" :disabled="uploadingLogo" @click="deleteLogo">
                Remove
              </button>
            </div>
          </div>

          <div class="admin-panel p-6">
            <h3 class="text-lg font-bold text-slate-950">Public Customer Links</h3>
            <p class="mt-2 text-sm text-slate-500">Share these links on receipts, chat replies, or social pages.</p>
            <p class="mt-3 text-xs text-slate-500">Shop code: <span class="font-semibold text-slate-700">{{ shop?.slug }}</span></p>

            <div class="mt-4 space-y-3">
              <div v-for="link in publicLinks" :key="link.key" class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <div class="flex items-start justify-between gap-3">
                  <div>
                    <p class="font-bold text-slate-900">{{ link.label }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ link.description }}</p>
                  </div>
                  <button
                    type="button"
                    class="shrink-0 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 hover:border-teal-200 hover:text-teal-700 disabled:opacity-50"
                    :disabled="!link.url"
                    @click="copyPublicLink(link)"
                  >
                    Copy
                  </button>
                </div>
                <div class="mt-3 rounded-lg border border-slate-200 bg-white p-3 break-all text-sm text-slate-700">
                  {{ link.url || link.empty }}
                </div>
              </div>
            </div>
          </div>

          <div class="admin-panel p-6">
            <h3 class="text-lg font-bold text-slate-950">Workspace Status</h3>
            <div class="mt-4 space-y-3 text-sm">
              <div class="flex items-center justify-between">
                <span class="text-slate-500">Workspace</span>
                <span class="font-semibold" :class="shop?.is_active ? 'text-emerald-700' : 'text-rose-700'">{{ shop?.is_active ? 'Active' : 'Inactive' }}</span>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-slate-500">Current Subscription Plan</span>
                <span class="font-semibold uppercase text-slate-800">{{ shop?.subscription_plan || 'starter' }}</span>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-slate-500">Trial Ends</span>
                <span class="font-semibold text-slate-800">{{ shop?.trial_ends_at_formatted || 'Not set' }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
