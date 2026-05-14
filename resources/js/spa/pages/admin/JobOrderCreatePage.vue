<script setup>
import { nextTick, onMounted, reactive, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AppLink from '../../components/AppLink.vue';
import { ensureAdmin } from '../../composables/auth';
import { navigate } from '../../navigation';

const loading = ref(true);
const forbidden = ref(false);
const technicians = ref([]);
const errorMessage = ref('');
const creating = ref(false);
const invoiceModalOpen = ref(false);
const invoiceError = ref('');
const createdJobOrderId = ref(null);
const invoiceData = ref(null);
const qrContainer = ref(null);

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

const loadMeta = async () => {
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

    try {
        const { data } = await window.axios.get('/api/admin/job-orders/meta');
        technicians.value = data.technicians || [];
    } catch {
        forbidden.value = true;
    } finally {
        loading.value = false;
    }
};

const formatCurrency = (value) => {
    const amount = Number(value || 0);
    return `PHP ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
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

const submit = async () => {
    errorMessage.value = '';
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
        if (form.open_invoice_preview) {
            await openInvoiceModal(data.jobOrder.id);
        } else {
            navigate(`/admin/job-orders/${data.jobOrder.id}`);
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

onMounted(loadMeta);
</script>

<template>
  <AdminLayout title="Create Job Order">
    <div v-if="loading" class="card p-6 text-slate-600">Loading form...</div>
    <div v-else-if="forbidden" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">You do not have access to this page.</div>

    <div v-else class="card p-6 no-print">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold text-slate-900">New Job Order</h3>
        <AppLink to="/admin/job-orders" class="text-slate-700 hover:text-slate-900 font-semibold">Back</AppLink>
      </div>

      <div v-if="errorMessage" class="mb-4 p-3 rounded border border-rose-200 bg-rose-50 text-rose-700">{{ errorMessage }}</div>
      <div v-if="invoiceError" class="mb-4 p-3 rounded border border-amber-200 bg-amber-50 text-amber-700">{{ invoiceError }}</div>

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
        <select v-model="form.technician_id" class="px-4 py-2 border border-slate-300 rounded-lg">
          <option value="">No technician assigned</option>
          <option v-for="tech in technicians" :key="tech.id" :value="tech.id">{{ tech.name }}</option>
        </select>
        <textarea v-model="form.problem_description" class="md:col-span-2 px-4 py-2 border border-slate-300 rounded-lg" rows="4" placeholder="Problem description" required></textarea>
        <label class="md:col-span-2 flex items-center gap-3 text-sm text-slate-600">
          <input v-model="form.open_invoice_preview" type="checkbox" class="rounded border-slate-300" />
          Open invoice preview after saving
        </label>
        <button type="submit" class="md:col-span-2 bg-slate-900 hover:bg-slate-800 text-white font-semibold py-2 rounded-lg disabled:opacity-60" :disabled="creating">
          {{ creating ? 'Creating...' : 'Create Job Order' }}
        </button>
      </form>
    </div>

    <div v-if="invoiceModalOpen && invoiceData" class="fixed inset-0 bg-black/50 z-50 px-4 py-6 overflow-y-auto print-modal-container">
      <div class="max-w-4xl mx-auto bg-white rounded-2xl shadow-2xl border border-slate-200 print-modal-card">
        <div class="p-6 border-b border-slate-200 flex items-center justify-between gap-4 no-print">
          <h3 class="text-xl font-semibold text-slate-900">Customer Invoice Preview</h3>
          <button type="button" class="text-slate-500 hover:text-slate-700 font-semibold" @click="closeInvoiceModal">Close</button>
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

          <div class="grid grid-cols-2 md:grid-cols-5 gap-3 text-sm">
            <div class="bg-slate-50 border border-slate-200 rounded-lg p-3">
              <p class="text-slate-500 text-xs">Estimated</p>
              <p class="font-semibold text-slate-900">{{ formatCurrency(invoiceData.costs.estimated_cost) }}</p>
            </div>
            <div class="bg-slate-50 border border-slate-200 rounded-lg p-3">
              <p class="text-slate-500 text-xs">Parts</p>
              <p class="font-semibold text-slate-900">{{ formatCurrency(invoiceData.costs.parts_cost) }}</p>
            </div>
            <div class="bg-slate-50 border border-slate-200 rounded-lg p-3">
              <p class="text-slate-500 text-xs">Labor</p>
              <p class="font-semibold text-slate-900">{{ formatCurrency(invoiceData.costs.labor_cost) }}</p>
            </div>
            <div class="bg-slate-50 border border-slate-200 rounded-lg p-3">
              <p class="text-slate-500 text-xs">Total</p>
              <p class="font-semibold text-slate-900">{{ formatCurrency(invoiceData.costs.total_cost) }}</p>
            </div>
            <div class="bg-rose-50 border border-rose-200 rounded-lg p-3">
              <p class="text-rose-700 text-xs">Balance</p>
              <p class="font-semibold text-rose-800">{{ formatCurrency(invoiceData.costs.balance) }}</p>
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
