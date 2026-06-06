<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AppLink from '../../components/AppLink.vue';
import { adminRoles, authState, ensureRoles } from '../../composables/auth';
import { useNavigation, navigate } from '../../navigation';

const props = defineProps({
    params: {
        type: Object,
        default: () => ({}),
    },
});

const loading = ref(true);
const forbidden = ref(false);
const jobOrders = ref(null);
const technicians = ref([]);
const techniciansLoaded = ref(false);
const loadingTechnicians = ref(false);
const filterOptions = ref({
    statuses: [],
    priorities: [],
    paymentStatuses: [],
});
const createModalOpen = ref(false);
const creating = ref(false);
const quickUpdatingId = ref(null);
const errorMessage = ref('');
const successMessage = ref('');
const invoiceModalOpen = ref(false);
const invoiceError = ref('');
const createdJobOrderId = ref(null);
const invoiceData = ref(null);
const qrContainer = ref(null);
const canManageTrash = computed(() => adminRoles.includes(authState.user?.role));

const activeFilterCount = computed(() => [
    filters.search.trim(),
    filters.status,
    filters.priority,
    filters.payment_status,
    filters.technician_id,
    filters.overdue_only,
].filter(Boolean).length);

const { location } = useNavigation();

const form = reactive({
    customer_name: '',
    customer_email: '',
    customer_phone: '',
    device_type: '',
    brand: '',
    model: '',
    serial_number: '',
    problem_description: '',
    priority: 'STANDARD',
    estimated_cost: '',
    down_payment: '',
    warranty_days: 30,
    estimated_completion: '',
    technician_id: '',
    open_invoice_preview: true,
});

const filters = reactive({
    search: '',
    status: '',
    priority: '',
    payment_status: '',
    technician_id: '',
    overdue_only: false,
});

const resetForm = () => {
    form.customer_name = '';
    form.customer_email = '';
    form.customer_phone = '';
    form.device_type = '';
    form.brand = '';
    form.model = '';
    form.serial_number = '';
    form.problem_description = '';
    form.priority = 'STANDARD';
    form.estimated_cost = '';
    form.down_payment = '';
    form.warranty_days = 30;
    form.estimated_completion = '';
    form.technician_id = '';
    form.open_invoice_preview = true;
};

const isCreatePath = computed(() => location.path === '/admin/job-orders/create');

const currentPage = computed(() => {
    const params = new URLSearchParams(location.search);
    const page = Number(params.get('page') || 1);
    return Number.isNaN(page) || page < 1 ? 1 : page;
});

const syncFiltersFromLocation = () => {
    const params = new URLSearchParams(location.search);
    filters.search = params.get('search') || '';
    filters.status = params.get('status') || '';
    filters.priority = params.get('priority') || '';
    filters.payment_status = params.get('payment_status') || '';
    filters.technician_id = params.get('technician_id') || '';
    filters.overdue_only = params.get('overdue_only') === '1';
};

const loadJobOrders = async () => {
    const auth = await ensureRoles(['admin', 'staff']);
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
        const { data } = await window.axios.get('/api/admin/job-orders', {
            params: {
                page: currentPage.value,
                search: filters.search || undefined,
                status: filters.status || undefined,
                priority: filters.priority || undefined,
                payment_status: filters.payment_status || undefined,
                technician_id: filters.technician_id || undefined,
                overdue_only: filters.overdue_only ? 1 : undefined,
            },
        });
        jobOrders.value = data.jobOrders;
    } catch {
        forbidden.value = true;
    } finally {
        loading.value = false;
    }
};

watch(
    () => location.search,
    () => {
        syncFiltersFromLocation();
        loadJobOrders();
    },
    { immediate: true }
);

const gotoPage = (url) => {
    if (!url) return;
    const parsed = new URL(url, window.location.origin);
    navigate(`/admin/job-orders${parsed.search ? parsed.search : ''}`);
};

const formatCurrency = (value) => {
    const amount = Number(value || 0);
    return `PHP ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

const statusClass = (status, overdue = false) => {
    if (overdue) return 'bg-rose-50 text-rose-700 ring-rose-200';
    if (status === 'READY_FOR_PICKUP' || status === 'PICKED_UP') return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    if (status === 'WAITING_PARTS') return 'bg-amber-50 text-amber-700 ring-amber-200';
    if (status === 'UNREPAIRABLE') return 'bg-slate-100 text-slate-700 ring-slate-200';
    return 'bg-teal-50 text-teal-700 ring-teal-200';
};

const paymentClass = (status) => {
    if (status === 'PAID') return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    if (status === 'PARTIAL') return 'bg-amber-50 text-amber-700 ring-amber-200';
    return 'bg-rose-50 text-rose-700 ring-rose-200';
};

const buildFilterQuery = (includePage = false) => {
    const params = new URLSearchParams();

    if (filters.search.trim()) params.set('search', filters.search.trim());
    if (filters.status) params.set('status', filters.status);
    if (filters.priority) params.set('priority', filters.priority);
    if (filters.payment_status) params.set('payment_status', filters.payment_status);
    if (filters.technician_id) params.set('technician_id', filters.technician_id);
    if (filters.overdue_only) params.set('overdue_only', '1');
    if (includePage && currentPage.value > 1) params.set('page', String(currentPage.value));

    return params.toString();
};

const applyFilters = () => {
    const query = buildFilterQuery(false);
    navigate(`/admin/job-orders${query ? `?${query}` : ''}`);
};

const clearFilters = () => {
    filters.search = '';
    filters.status = '';
    filters.priority = '';
    filters.payment_status = '';
    filters.technician_id = '';
    filters.overdue_only = false;
    navigate('/admin/job-orders');
};

const loadMeta = async () => {
    if (techniciansLoaded.value || loadingTechnicians.value) return;

    loadingTechnicians.value = true;
    try {
        const { data } = await window.axios.get('/api/admin/job-orders/meta');
        technicians.value = data.technicians || [];
        filterOptions.value = {
            statuses: data.statuses || [],
            priorities: data.priorities || [],
            paymentStatuses: data.paymentStatuses || [],
        };
        techniciansLoaded.value = true;
    } catch {
        errorMessage.value = 'Unable to load technician list.';
    } finally {
        loadingTechnicians.value = false;
    }
};

onMounted(loadMeta);

const openCreateModal = async () => {
    errorMessage.value = '';
    successMessage.value = '';
    createModalOpen.value = true;
    await loadMeta();
};

const closeCreateModal = () => {
    createModalOpen.value = false;
    errorMessage.value = '';
    resetForm();

    if (isCreatePath.value) {
        navigate('/admin/job-orders', { replace: true });
    }
};

const renderQr = async () => {
    await nextTick();

    if (!invoiceData.value?.invoice?.qr_value || !qrContainer.value) return;
    qrContainer.value.innerHTML = '';

    if (window.QRCode) {
        new window.QRCode(qrContainer.value, {
            text: invoiceData.value.invoice.qr_value,
            width: 130,
            height: 130,
            colorDark: '#0f172a',
            colorLight: '#ffffff',
            correctLevel: window.QRCode.CorrectLevel.M,
        });
    } else {
        qrContainer.value.innerHTML = `<p class="text-xs text-slate-500 break-all">${invoiceData.value.invoice.qr_value}</p>`;
    }
};

const openInvoiceModal = async (jobOrderId) => {
    invoiceError.value = '';
    createdJobOrderId.value = jobOrderId;

    try {
        const { data } = await window.axios.get(`/api/admin/job-orders/${jobOrderId}/invoice`);
        invoiceData.value = data;
        invoiceModalOpen.value = true;
        await renderQr();
    } catch (error) {
        invoiceError.value = error?.response?.data?.message || 'Job order created, but invoice preview failed to load.';
    }
};

const closeInvoiceModal = () => {
    invoiceModalOpen.value = false;
};

const printModalInvoice = () => {
    window.print();
};

const openFullInvoice = () => {
    if (!createdJobOrderId.value) return;
    navigate(`/admin/job-orders/${createdJobOrderId.value}/invoice`);
};

const openJobOrder = () => {
    if (!createdJobOrderId.value) return;
    navigate(`/admin/job-orders/${createdJobOrderId.value}`);
};

const submit = async () => {
    errorMessage.value = '';
    invoiceError.value = '';
    creating.value = true;

    try {
        const payload = {
            ...form,
            estimated_cost: form.estimated_cost || null,
            down_payment: form.down_payment || null,
            estimated_completion: form.estimated_completion || null,
            technician_id: form.technician_id || null,
        };

        const { data } = await window.axios.post('/api/admin/job-orders', payload);

        successMessage.value = `Job order ${data.jobOrder.job_order_number || `#${data.jobOrder.id}`} created.`;
        resetForm();
        createModalOpen.value = false;

        if (isCreatePath.value) {
            navigate('/admin/job-orders', { replace: true });
        }

        await loadJobOrders();
        if (form.open_invoice_preview) {
            await openInvoiceModal(data.jobOrder.id);
        }
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to create job order.';
    } finally {
        creating.value = false;
    }
};

const deleteJobOrder = async (id) => {
    const confirmed = window.confirm('Delete this job order?');
    if (!confirmed) return;

    try {
        await window.axios.delete(`/api/admin/job-orders/${id}`);
        await loadJobOrders();
    } catch {
        window.alert('Unable to delete job order.');
    }
};

const markReadyForPickup = async (job) => {
    if (quickUpdatingId.value) return;
    if (!window.confirm(`Mark ${job.job_order_number} as ready for pickup?`)) return;

    successMessage.value = '';
    errorMessage.value = '';
    quickUpdatingId.value = job.id;

    try {
        const { data } = await window.axios.post(`/api/admin/job-orders/${job.id}/update-status`, {
            status: 'READY_FOR_PICKUP',
            note: 'Marked ready for pickup from job order list.',
        });
        successMessage.value = data.message || `${job.job_order_number} marked ready for pickup.`;
        await loadJobOrders();
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Unable to update status.';
    } finally {
        quickUpdatingId.value = null;
    }
};

watch(
    () => props.params?.openCreate,
    (openCreate) => {
        if (openCreate) {
            openCreateModal();
        }
    },
    { immediate: true }
);
</script>

<template>
  <AdminLayout title="Job Orders">
    <div v-if="loading" class="card p-6 text-slate-600">Loading job orders...</div>
    <div v-else-if="forbidden" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">You do not have access to this page.</div>

    <div v-else class="space-y-6">
      <div v-if="successMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700">{{ successMessage }}</div>
      <div v-if="invoiceError" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-700">{{ invoiceError }}</div>
      <div v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">{{ errorMessage }}</div>

      <section class="rounded-2xl border border-slate-200 bg-slate-950 p-6 text-white shadow-xl shadow-slate-900/10">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <p class="text-xs font-bold uppercase text-teal-300">Repair queue</p>
            <h3 class="mt-2 font-display text-3xl leading-tight">Job order workspace</h3>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">
              Search, filter, review, and update repair transactions from the shop queue.
            </p>
          </div>
          <div class="flex flex-wrap gap-3">
            <AppLink v-if="canManageTrash" to="/admin/job-orders/trash" class="rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-sm font-bold text-white hover:bg-white/10">Trash</AppLink>
            <button type="button" class="rounded-lg bg-amber-400 px-5 py-2 text-sm font-bold text-slate-950 shadow-lg shadow-amber-400/15 hover:bg-amber-300" @click="openCreateModal">New Job Order</button>
          </div>
        </div>
      </section>

      <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <form class="grid grid-cols-1 gap-3 md:grid-cols-3 lg:grid-cols-6" @submit.prevent="applyFilters">
          <div class="lg:col-span-2">
            <label class="mb-2 block text-xs font-bold uppercase text-slate-400">Search</label>
            <input v-model="filters.search" class="w-full rounded-lg border border-slate-300 px-4 py-3 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-teal-500 focus:ring-4 focus:ring-teal-100" placeholder="Job #, customer, phone, device..." />
          </div>
          <div>
            <label class="mb-2 block text-xs font-bold uppercase text-slate-400">Status</label>
            <select v-model="filters.status" class="w-full rounded-lg border border-slate-300 px-4 py-3 text-slate-900 outline-none transition focus:border-teal-500 focus:ring-4 focus:ring-teal-100">
            <option value="">All statuses</option>
            <option v-for="status in filterOptions.statuses" :key="status" :value="status">{{ status.replaceAll('_', ' ') }}</option>
          </select>
          </div>
          <div>
            <label class="mb-2 block text-xs font-bold uppercase text-slate-400">Priority</label>
            <select v-model="filters.priority" class="w-full rounded-lg border border-slate-300 px-4 py-3 text-slate-900 outline-none transition focus:border-teal-500 focus:ring-4 focus:ring-teal-100">
            <option value="">All priorities</option>
            <option v-for="priority in filterOptions.priorities" :key="priority" :value="priority">{{ priority }}</option>
          </select>
          </div>
          <div>
            <label class="mb-2 block text-xs font-bold uppercase text-slate-400">Payment</label>
            <select v-model="filters.payment_status" class="w-full rounded-lg border border-slate-300 px-4 py-3 text-slate-900 outline-none transition focus:border-teal-500 focus:ring-4 focus:ring-teal-100">
            <option value="">All payment states</option>
            <option v-for="status in filterOptions.paymentStatuses" :key="status" :value="status">{{ status }}</option>
          </select>
          </div>
          <div>
            <label class="mb-2 block text-xs font-bold uppercase text-slate-400">Technician</label>
            <select v-model="filters.technician_id" class="w-full rounded-lg border border-slate-300 px-4 py-3 text-slate-900 outline-none transition focus:border-teal-500 focus:ring-4 focus:ring-teal-100">
            <option value="">All technicians</option>
            <option v-for="tech in technicians" :key="tech.id" :value="String(tech.id)">{{ tech.name }}</option>
          </select>
          </div>
          <label class="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-600 md:col-span-2 lg:col-span-2">
            <input v-model="filters.overdue_only" type="checkbox" class="rounded border-slate-300 text-teal-600 focus:ring-teal-500" />
            Show overdue repairs only
          </label>
          <div class="flex flex-wrap items-center justify-end gap-3 md:col-span-3 lg:col-span-4">
            <span v-if="activeFilterCount" class="mr-auto rounded-full bg-teal-50 px-3 py-1 text-xs font-bold text-teal-700 ring-1 ring-teal-200">{{ activeFilterCount }} active filter{{ activeFilterCount > 1 ? 's' : '' }}</span>
            <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 font-bold text-slate-700 hover:bg-slate-100" @click="clearFilters">Clear</button>
            <button type="submit" class="rounded-lg bg-slate-950 px-5 py-2 font-bold text-white hover:bg-slate-800">Apply Filters</button>
          </div>
        </form>
      </div>

      <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
          <div>
            <h3 class="text-lg font-bold text-slate-950">Repair Jobs</h3>
            <p class="text-sm text-slate-500">{{ jobOrders.total || jobOrders.data.length }} record{{ (jobOrders.total || jobOrders.data.length) === 1 ? '' : 's' }} found</p>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full min-w-[1060px] text-sm">
            <thead>
              <tr class="border-b border-slate-200 bg-slate-50 text-xs uppercase text-slate-500">
                <th class="px-6 py-3 text-left font-bold">Job Order</th>
                <th class="px-6 py-3 text-left font-bold">Customer</th>
                <th class="px-6 py-3 text-left font-bold">Device</th>
                <th class="px-6 py-3 text-left font-bold">Status</th>
                <th class="px-6 py-3 text-left font-bold">Payment</th>
                <th class="px-6 py-3 text-left font-bold">Due</th>
                <th class="px-6 py-3 text-left font-bold">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="job in jobOrders.data" :key="job.id" class="hover:bg-slate-50/80">
              <td class="px-6 py-4">
                <AppLink :to="`/admin/job-orders/${job.id}`" class="font-bold text-slate-950 hover:text-teal-700">{{ job.job_order_number }}</AppLink>
                <p class="text-xs text-slate-500">{{ job.created_at_formatted }}</p>
              </td>
              <td class="px-6 py-4">
                <p class="font-semibold text-slate-800">{{ job.customer_name }}</p>
                <p v-if="job.customer_phone" class="text-xs text-slate-500">{{ job.customer_phone }}</p>
              </td>
              <td class="px-6 py-4">
                <p class="font-semibold text-slate-800">{{ [job.brand, job.model].filter(Boolean).join(' ') || job.device_type }}</p>
                <p class="text-xs text-slate-500">{{ job.device_type }}</p>
                <p v-if="job.technician_name" class="text-xs text-slate-500">Tech: {{ job.technician_name }}</p>
              </td>
              <td class="px-6 py-4">
                <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold ring-1" :class="statusClass(job.current_status, job.is_overdue)">
                  {{ job.current_status.replaceAll('_', ' ') }}
                </span>
                <p class="mt-2 text-xs" :class="job.is_overdue ? 'font-bold text-rose-600' : 'text-slate-500'">
                  {{ job.is_overdue ? 'Overdue' : job.priority }}
                </p>
              </td>
              <td class="px-6 py-4">
                <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold ring-1" :class="paymentClass(job.payment_status)">
                  {{ job.payment_status }}
                </span>
                <p class="mt-2 text-xs text-slate-500">Balance: {{ formatCurrency(job.balance) }}</p>
              </td>
              <td class="px-6 py-4">
                <p class="font-semibold text-slate-700">{{ job.estimated_completion_formatted || 'Not set' }}</p>
              </td>
              <td class="px-6 py-4">
                <div class="flex flex-wrap items-center gap-2">
                  <AppLink :to="`/admin/job-orders/${job.id}`" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700 hover:border-teal-200 hover:text-teal-700">View</AppLink>
                  <button
                    v-if="!['READY_FOR_PICKUP', 'PICKED_UP', 'UNREPAIRABLE'].includes(job.current_status)"
                    type="button"
                    class="rounded-lg border border-emerald-200 px-3 py-1.5 text-xs font-bold text-emerald-700 hover:bg-emerald-50 disabled:opacity-60"
                    :disabled="quickUpdatingId === job.id"
                    @click="markReadyForPickup(job)"
                  >
                    {{ quickUpdatingId === job.id ? 'Saving...' : 'Set Ready' }}
                  </button>
                  <button v-if="canManageTrash" type="button" class="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-bold text-rose-600 hover:bg-rose-50" @click="deleteJobOrder(job.id)">Delete</button>
                </div>
              </td>
            </tr>
            <tr v-if="!jobOrders.data.length">
              <td colspan="7" class="px-6 py-12 text-center text-slate-500">No job orders found.</td>
            </tr>
          </tbody>
          </table>
        </div>
      </div>

      <div class="flex flex-wrap gap-2">
        <button
          v-for="link in jobOrders.links"
          :key="link.label + String(link.url)"
          type="button"
          class="px-3 py-2 rounded text-sm"
          :class="link.active ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 disabled:text-slate-300'"
          :disabled="!link.url"
          @click="gotoPage(link.url)"
          v-html="link.label"
        ></button>
      </div>
    </div>

    <div v-if="createModalOpen" class="fixed inset-0 z-50 bg-black/45 px-4 py-6 overflow-y-auto no-print" @click.self="closeCreateModal">
      <div class="max-w-5xl mx-auto bg-white rounded-2xl shadow-2xl border border-slate-200">
        <div class="p-5 border-b border-slate-200 flex items-center justify-between gap-4">
          <h3 class="text-xl font-semibold text-slate-900">Create Job Order</h3>
          <button type="button" class="w-9 h-9 rounded-full border border-slate-200 text-slate-500 hover:text-slate-700 hover:bg-slate-100 text-xl leading-none" aria-label="Close" @click="closeCreateModal">&times;</button>
        </div>

        <div class="p-6">
          <div v-if="errorMessage" class="mb-4 p-3 rounded border border-rose-200 bg-rose-50 text-rose-700">{{ errorMessage }}</div>

          <form class="grid grid-cols-1 md:grid-cols-2 gap-4" @submit.prevent="submit">
            <input v-model="form.customer_name" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Customer name" required />
            <input v-model="form.customer_email" type="email" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Customer email" />
            <input v-model="form.customer_phone" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Customer phone" />
            <input v-model="form.device_type" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Device type" required />
            <input v-model="form.brand" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Brand" />
            <input v-model="form.model" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Model" />
            <input v-model="form.serial_number" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Serial number" />
            <select v-model="form.priority" class="px-4 py-2 border border-slate-300 rounded-lg">
              <option value="STANDARD">STANDARD</option>
              <option value="EXPRESS">EXPRESS</option>
              <option value="EMERGENCY">EMERGENCY</option>
            </select>
            <input v-model="form.estimated_cost" type="number" step="0.01" min="0" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Estimated cost" />
            <input v-model="form.down_payment" type="number" step="0.01" min="0" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Down payment (DP)" />
            <input v-model="form.warranty_days" type="number" min="0" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Warranty days" />
            <input v-model="form.estimated_completion" type="datetime-local" class="px-4 py-2 border border-slate-300 rounded-lg" />
            <select v-model="form.technician_id" class="px-4 py-2 border border-slate-300 rounded-lg" :disabled="loadingTechnicians">
              <option value="">No technician assigned</option>
              <option v-for="tech in technicians" :key="tech.id" :value="tech.id">{{ tech.name }}</option>
            </select>
            <textarea v-model="form.problem_description" class="md:col-span-2 px-4 py-2 border border-slate-300 rounded-lg" rows="4" placeholder="Problem description" required></textarea>
            <label class="md:col-span-2 flex items-center gap-3 text-sm text-slate-600">
              <input v-model="form.open_invoice_preview" type="checkbox" class="rounded border-slate-300" />
              Open invoice preview after saving
            </label>

            <div class="md:col-span-2 flex justify-end gap-3 pt-2">
              <button type="button" class="px-5 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-100 font-semibold" @click="closeCreateModal">Cancel</button>
              <button type="submit" class="px-5 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white font-semibold disabled:opacity-60" :disabled="creating">
                {{ creating ? 'Creating...' : 'Create Job Order' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div v-if="invoiceModalOpen && invoiceData" class="fixed inset-0 bg-black/50 z-50 px-4 py-6 overflow-y-auto print-modal-container">
      <div class="max-w-4xl mx-auto bg-white rounded-2xl shadow-2xl border border-slate-200 print-modal-card">
        <div class="p-6 border-b border-slate-200 flex items-center justify-between gap-4 no-print">
          <h3 class="text-xl font-semibold text-slate-900">Customer Invoice Preview</h3>
          <button type="button" class="w-9 h-9 rounded-full border border-slate-200 text-slate-500 hover:text-slate-700 hover:bg-slate-100 text-xl leading-none" aria-label="Close" @click="closeInvoiceModal">&times;</button>
        </div>

        <div class="p-6 space-y-6">
          <div class="flex items-start justify-between gap-4 border-b border-slate-200 pb-5">
            <div>
              <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Invoice</p>
              <h4 class="text-2xl font-semibold text-slate-900">{{ invoiceData.invoice.invoice_number }}</h4>
              <p class="text-sm text-slate-600 mt-1">Issued: {{ invoiceData.invoice.issued_at_formatted }}</p>
            </div>
            <div class="text-right">
              <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Track Number</p>
              <p class="text-xl font-bold text-slate-900">{{ invoiceData.invoice.track_number }}</p>
              <p class="text-xs text-slate-500 mt-1">{{ invoiceData.invoice.track_url }}</p>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
              <div>
                <p class="text-slate-500 uppercase text-xs font-semibold">Customer</p>
                <p class="font-semibold text-slate-900">{{ invoiceData.customer.name }}</p>
                <p class="text-slate-700">{{ invoiceData.customer.phone || invoiceData.customer.email || invoiceData.customer.contact || 'N/A' }}</p>
              </div>
              <div>
                <p class="text-slate-500 uppercase text-xs font-semibold">Device</p>
                <p class="font-semibold text-slate-900">{{ invoiceData.device.type }}</p>
                <p class="text-slate-700">{{ [invoiceData.device.brand, invoiceData.device.model].filter(Boolean).join(' ') || 'N/A' }}</p>
              </div>
              <div class="md:col-span-2">
                <p class="text-slate-500 uppercase text-xs font-semibold">Problem</p>
                <p class="text-slate-800">{{ invoiceData.device.problem_description }}</p>
              </div>
            </div>

            <div class="border border-slate-200 rounded-lg p-3 flex flex-col items-center">
              <div ref="qrContainer" class="w-[130px] h-[130px] bg-white"></div>
              <p class="text-xs text-slate-500 mt-3">Scan to track status</p>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
            <div class="bg-slate-50 border border-slate-200 rounded-lg p-3">
              <p class="text-slate-500 text-xs">Estimated Cost</p>
              <p class="font-semibold text-slate-900">{{ formatCurrency(invoiceData.costs.estimated_cost) }}</p>
            </div>
            <div class="bg-slate-50 border border-slate-200 rounded-lg p-3">
              <p class="text-slate-500 text-xs">Down Payment (DP)</p>
              <p class="font-semibold text-slate-900">{{ formatCurrency(invoiceData.costs.down_payment) }}</p>
            </div>
            <div class="bg-rose-50 border border-rose-200 rounded-lg p-3">
              <p class="text-rose-700 text-xs">Remaining (Estimate)</p>
              <p class="font-semibold text-rose-800">{{ formatCurrency(invoiceData.costs.estimated_balance) }}</p>
            </div>
          </div>
        </div>

        <div class="p-6 border-t border-slate-200 flex flex-wrap gap-3 no-print">
          <button type="button" class="px-4 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white font-semibold" @click="printModalInvoice">Print</button>
          <button type="button" class="px-4 py-2 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold" @click="openFullInvoice">Open Full Invoice</button>
          <button type="button" class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white font-semibold" @click="openJobOrder">Go to Job Order</button>
          <button type="button" class="px-4 py-2 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold ml-auto" @click="closeInvoiceModal">Close</button>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

<style scoped>
@media print {
  .no-print {
    display: none !important;
  }

  :deep(.sidebar),
  :deep(.content-header) {
    display: none !important;
  }

  :deep(.app-shell),
  :deep(.p-6),
  :deep(.space-y-4) {
    padding: 0 !important;
    margin: 0 !important;
    background: #fff !important;
  }

  .print-modal-container {
    position: static !important;
    background: #fff !important;
    overflow: visible !important;
  }

  .print-modal-card {
    max-width: none !important;
    width: 100% !important;
    box-shadow: none !important;
    border: none !important;
    border-radius: 0 !important;
  }
}
</style>
