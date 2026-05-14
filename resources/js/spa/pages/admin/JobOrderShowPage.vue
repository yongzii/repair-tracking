<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AppLink from '../../components/AppLink.vue';
import { ensureRoles } from '../../composables/auth';
import { navigate } from '../../navigation';

const props = defineProps({
    params: {
        type: Object,
        default: () => ({}),
    },
});

const loading = ref(true);
const forbidden = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const jobOrder = ref(null);
const statusHistories = ref([]);
const parts = ref([]);
const inventoryParts = ref([]);
const payments = ref([]);

const statusForm = ref({
    status: '',
    note: '',
});

const pickupForm = ref({
    picked_up_by: '',
});

const costForm = ref({
    parts_cost: '',
    labor_cost: '',
});

const paymentForm = ref({
    amount_paid: '',
    payment_type: '',
    payment_method: 'CASH',
    reference_number: '',
    payment_notes: '',
});

const partForm = ref({
    spare_part_id: '',
    part_name: '',
    quantity: 1,
    unit_cost: '',
    notes: '',
});

const balance = computed(() => {
    if (!jobOrder.value) return 0;
    return Number(jobOrder.value.total_cost || 0) - Number(jobOrder.value.amount_paid || 0);
});

const selectedSparePart = computed(() => {
    if (!partForm.value.spare_part_id) return null;
    return inventoryParts.value.find((item) => Number(item.id) === Number(partForm.value.spare_part_id)) || null;
});

const formatCurrency = (value) => {
    const amount = Number(value || 0);
    return `PHP ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

const formatDate = (value, withTime = false) => {
    if (!value) return 'N/A';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    const options = withTime
        ? { month: 'short', day: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }
        : { month: 'short', day: '2-digit', year: 'numeric' };
    return date.toLocaleString(undefined, options);
};

const loadJobOrder = async () => {
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

    try {
        loading.value = true;
        const [jobOrderResponse, inventoryResponse] = await Promise.all([
            window.axios.get(`/api/admin/job-orders/${props.params.id}`),
            window.axios.get('/api/admin/inventory', { params: { all: 1, active_only: 1 } }),
        ]);

        const data = jobOrderResponse.data;
        jobOrder.value = data.jobOrder;
        statusHistories.value = data.statusHistories || [];
        parts.value = data.parts || [];
        payments.value = data.payments || [];
        inventoryParts.value = inventoryResponse.data.items || [];
        costForm.value.parts_cost = jobOrder.value.parts_cost || 0;
        costForm.value.labor_cost = jobOrder.value.labor_cost || 0;
    } catch (error) {
        forbidden.value = true;
        errorMessage.value = error?.response?.data?.message || 'Unable to load job order.';
    } finally {
        loading.value = false;
    }
};

const updateStatus = async () => {
    if (!statusForm.value.status) return;
    successMessage.value = '';
    errorMessage.value = '';

    try {
        const { data } = await window.axios.post(
            `/api/admin/job-orders/${props.params.id}/update-status`,
            statusForm.value
        );
        successMessage.value = data.message || 'Status updated.';
        await loadJobOrder();
        statusForm.value.status = '';
        statusForm.value.note = '';
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Failed to update status.';
    }
};

const markPickedUp = async () => {
    if (!pickupForm.value.picked_up_by.trim()) return;
    successMessage.value = '';
    errorMessage.value = '';

    try {
        const { data } = await window.axios.post(
            `/api/admin/job-orders/${props.params.id}/mark-picked-up`,
            pickupForm.value
        );
        successMessage.value = data.message || 'Marked as picked up.';
        await loadJobOrder();
        pickupForm.value.picked_up_by = '';
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Failed to mark picked up.';
    }
};

const updateCosts = async () => {
    successMessage.value = '';
    errorMessage.value = '';
    try {
        const { data } = await window.axios.post(`/api/admin/job-orders/${props.params.id}/update-costs`, costForm.value);
        successMessage.value = data.message || 'Costs updated.';
        await loadJobOrder();
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Failed to update costs.';
    }
};

const recordPayment = async () => {
    successMessage.value = '';
    errorMessage.value = '';
    try {
        const { data } = await window.axios.post(`/api/admin/job-orders/${props.params.id}/record-payment`, paymentForm.value);
        successMessage.value = data.message || 'Payment recorded.';
        paymentForm.value.amount_paid = '';
        paymentForm.value.payment_type = '';
        paymentForm.value.payment_method = 'CASH';
        paymentForm.value.reference_number = '';
        paymentForm.value.payment_notes = '';
        await loadJobOrder();
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Failed to record payment.';
    }
};

const addPart = async () => {
    successMessage.value = '';
    errorMessage.value = '';
    try {
        const payload = {
            ...partForm.value,
            spare_part_id: partForm.value.spare_part_id || null,
        };
        const { data } = await window.axios.post(`/api/admin/job-orders/${props.params.id}/parts`, payload);
        successMessage.value = data.message || 'Part added.';
        partForm.value = { spare_part_id: '', part_name: '', quantity: 1, unit_cost: '', notes: '' };
        await loadJobOrder();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Failed to add part.';
    }
};

const onSparePartChange = () => {
    if (!selectedSparePart.value) return;
    partForm.value.part_name = selectedSparePart.value.name;
    partForm.value.unit_cost = selectedSparePart.value.unit_cost;
};

const deletePart = async (partId) => {
    if (!window.confirm('Delete this part?')) return;
    successMessage.value = '';
    errorMessage.value = '';
    try {
        const { data } = await window.axios.delete(`/api/admin/job-orders/${props.params.id}/parts/${partId}`);
        successMessage.value = data.message || 'Part deleted.';
        await loadJobOrder();
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Failed to delete part.';
    }
};

onMounted(loadJobOrder);
</script>

<template>
  <AdminLayout :title="jobOrder ? jobOrder.job_order_number : 'Job Order'">
    <div v-if="loading" class="card p-6 text-slate-600">Loading job order...</div>
    <div v-else-if="forbidden" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">{{ errorMessage || 'You do not have access to this page.' }}</div>

    <div v-else-if="jobOrder" class="space-y-6">
      <div v-if="successMessage" class="card p-4 border border-emerald-200 bg-emerald-50 text-emerald-900">{{ successMessage }}</div>
      <div v-if="errorMessage" class="card p-4 border border-rose-200 bg-rose-50 text-rose-900">{{ errorMessage }}</div>

      <div class="flex items-center justify-between gap-3">
        <div>
          <h3 class="text-2xl font-bold text-slate-900">{{ jobOrder.job_order_number }}</h3>
          <p class="text-slate-600">{{ jobOrder.customer_name }} - {{ jobOrder.device_type }}</p>
        </div>
        <div class="flex items-center gap-3">
          <AppLink :to="`/admin/job-orders/${jobOrder.id}/invoice`" class="text-indigo-700 hover:text-indigo-800 font-semibold">Invoice</AppLink>
          <AppLink :to="`/admin/job-orders/${jobOrder.id}/edit`" class="text-slate-900 hover:text-slate-700 font-semibold">Edit</AppLink>
          <AppLink to="/admin/job-orders" class="text-slate-700 hover:text-slate-900 font-semibold">Back to Job Orders</AppLink>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
          <div class="card p-6">
            <h4 class="text-lg font-semibold text-slate-900 mb-4">Details</h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
              <div>
                <p class="text-slate-500">Status</p>
                <p class="font-semibold text-slate-900">{{ jobOrder.current_status.replaceAll('_', ' ') }}</p>
              </div>
              <div>
                <p class="text-slate-500">Priority</p>
                <p class="font-semibold text-slate-900">{{ jobOrder.priority }}</p>
              </div>
              <div>
                <p class="text-slate-500">Received</p>
                <p class="font-semibold text-slate-900">{{ formatDate(jobOrder.created_at) }}</p>
              </div>
              <div>
                <p class="text-slate-500">Payment Status</p>
                <p class="font-semibold text-slate-900">{{ (jobOrder.payment_status || 'PENDING').replaceAll('_', ' ') }}</p>
              </div>
              <div>
                <p class="text-slate-500">Amount Paid</p>
                <p class="font-semibold text-slate-900">{{ formatCurrency(jobOrder.amount_paid) }}</p>
              </div>
              <div>
                <p class="text-slate-500">Balance</p>
                <p class="font-semibold text-slate-900">{{ formatCurrency(balance) }}</p>
              </div>
              <div class="md:col-span-2">
                <p class="text-slate-500">Problem</p>
                <p class="font-semibold text-slate-900">{{ jobOrder.problem_description }}</p>
              </div>
            </div>
          </div>

          <div class="card p-6">
            <h4 class="text-lg font-semibold text-slate-900 mb-4">Timeline</h4>
            <div class="space-y-3">
              <div v-for="entry in statusHistories" :key="entry.id || entry.created_at" class="border-b border-slate-100 pb-3 last:border-0 last:pb-0">
                <p class="font-semibold text-slate-900">{{ entry.status.replaceAll('_', ' ') }}</p>
                <p class="text-sm text-slate-500">{{ formatDate(entry.created_at, true) }}</p>
                <p v-if="entry.note" class="text-sm text-slate-700">{{ entry.note }}</p>
              </div>
              <p v-if="!statusHistories.length" class="text-sm text-slate-500">No history yet.</p>
            </div>
          </div>

          <div class="card p-6">
            <h4 class="text-lg font-semibold text-slate-900 mb-4">Parts</h4>
            <div v-if="parts.length" class="space-y-3 mb-4">
              <div v-for="part in parts" :key="part.id" class="flex items-center justify-between border border-slate-200 rounded-lg px-3 py-2">
                <div>
                  <p class="font-semibold text-slate-900">{{ part.part_name }}</p>
                  <p v-if="part.spare_part" class="text-xs text-slate-500">
                    From inventory · Remaining: {{ part.spare_part.stock_quantity }} {{ part.spare_part.unit || 'pcs' }}
                  </p>
                  <p class="text-sm text-slate-600">Qty {{ part.quantity }} x {{ formatCurrency(part.unit_cost) }}</p>
                </div>
                <div class="flex items-center gap-3">
                  <span class="text-sm font-semibold text-slate-800">{{ formatCurrency(part.total_cost) }}</span>
                  <button type="button" class="text-rose-600 font-semibold" @click="deletePart(part.id)">Delete</button>
                </div>
              </div>
            </div>
            <p v-else class="text-sm text-slate-500 mb-4">No parts recorded yet.</p>

            <form class="grid grid-cols-1 md:grid-cols-4 gap-3" @submit.prevent="addPart">
              <select v-model="partForm.spare_part_id" class="md:col-span-2 px-4 py-2 border border-slate-300 rounded-lg" @change="onSparePartChange">
                <option value="">Custom part (no inventory deduction)</option>
                <option
                  v-for="item in inventoryParts"
                  :key="item.id"
                  :value="String(item.id)"
                  :disabled="item.stock_quantity <= 0"
                >
                  {{ item.name }} · Stock {{ item.stock_quantity }} · {{ formatCurrency(item.unit_cost) }}
                </option>
              </select>
              <input v-model.number="partForm.quantity" type="number" min="1" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Qty" required />
              <input v-model="partForm.unit_cost" type="number" min="0" step="0.01" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Unit cost (auto from inventory)" :readonly="Boolean(selectedSparePart)" required />
              <input v-model="partForm.part_name" class="md:col-span-4 px-4 py-2 border border-slate-300 rounded-lg" placeholder="Part name (required for custom part)" :readonly="Boolean(selectedSparePart)" :required="!selectedSparePart" />
              <input v-model="partForm.notes" class="md:col-span-3 px-4 py-2 border border-slate-300 rounded-lg" placeholder="Notes (optional)" />
              <button type="submit" class="md:col-span-1 bg-slate-900 hover:bg-slate-800 text-white font-semibold py-2 rounded-lg">Add Part</button>
            </form>
          </div>

        </div>

        <div class="space-y-6">
          <div class="card p-6">
            <h4 class="text-lg font-semibold text-slate-900 mb-4">Update Status</h4>
            <form class="space-y-3" @submit.prevent="updateStatus">
              <select v-model="statusForm.status" class="w-full px-4 py-2 border border-slate-300 rounded-lg" required>
                <option value="">Select status</option>
                <option value="RECEIVED">Received</option>
                <option value="DIAGNOSING">Diagnosing</option>
                <option value="WAITING_PARTS">Waiting for Parts</option>
                <option value="IN_PROGRESS">Repair in Progress</option>
                <option value="READY_FOR_PICKUP">Ready for Pickup</option>
                <option value="UNREPAIRABLE">Unrepairable</option>
              </select>
              <textarea v-model="statusForm.note" rows="3" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Note (optional)"></textarea>
              <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white font-semibold py-2 rounded-lg">Save Status</button>
            </form>
          </div>

          <div class="card p-6" v-if="jobOrder.current_status === 'READY_FOR_PICKUP' && !jobOrder.picked_up_at">
            <h4 class="text-lg font-semibold text-slate-900 mb-4">Mark Picked Up</h4>
            <form class="space-y-3" @submit.prevent="markPickedUp">
              <input v-model="pickupForm.picked_up_by" type="text" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Picked up by" required />
              <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2 rounded-lg">Confirm Pickup</button>
            </form>
          </div>

          <div class="card p-6">
            <h4 class="text-lg font-semibold text-slate-900 mb-3">Assets</h4>
            <p class="text-sm text-slate-600">Parts: {{ parts.length }}</p>
          </div>

          <div class="card p-6">
            <h4 class="text-lg font-semibold text-slate-900 mb-4">Update Costs</h4>
            <form class="space-y-3" @submit.prevent="updateCosts">
              <input v-model="costForm.parts_cost" type="number" min="0" step="0.01" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Parts cost" required />
              <input v-model="costForm.labor_cost" type="number" min="0" step="0.01" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Labor cost" required />
              <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white font-semibold py-2 rounded-lg">Save Costs</button>
            </form>
          </div>

          <div class="card p-6">
            <h4 class="text-lg font-semibold text-slate-900 mb-4">Record Payment</h4>
            <form class="space-y-3" @submit.prevent="recordPayment">
              <input v-model="paymentForm.amount_paid" type="number" min="0.01" step="0.01" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Amount paid" required />
              <select v-model="paymentForm.payment_type" class="w-full px-4 py-2 border border-slate-300 rounded-lg">
                <option value="">Auto detect type</option>
                <option value="PARTIAL_PAYMENT">Partial Payment</option>
                <option value="FINAL_PAYMENT">Final Payment</option>
              </select>
              <select v-model="paymentForm.payment_method" class="w-full px-4 py-2 border border-slate-300 rounded-lg">
                <option value="CASH">Cash</option>
                <option value="GCASH">GCash</option>
                <option value="BANK_TRANSFER">Bank Transfer</option>
                <option value="CARD">Card</option>
              </select>
              <input v-model="paymentForm.reference_number" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Reference number (optional)" />
              <textarea v-model="paymentForm.payment_notes" rows="2" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Payment notes"></textarea>
              <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2 rounded-lg">Record</button>
            </form>
          </div>

          <div class="card p-6">
            <h4 class="text-lg font-semibold text-slate-900 mb-4">Payment History</h4>
            <div v-if="payments.length" class="space-y-3">
              <div v-for="payment in payments" :key="payment.id" class="border border-slate-200 rounded-lg p-3">
                <div class="flex items-start justify-between gap-3">
                  <div>
                    <p class="font-semibold text-slate-900">{{ formatCurrency(payment.amount) }}</p>
                    <p class="text-xs text-slate-500">{{ (payment.payment_type || '').replaceAll('_', ' ') }}</p>
                  </div>
                  <p class="text-xs text-slate-500 text-right">{{ payment.received_at_formatted || formatDate(payment.received_at, true) }}</p>
                </div>
                <p class="text-sm text-slate-700 mt-1">
                  {{ payment.payment_method || 'N/A' }}
                  <span v-if="payment.reference_number"> · Ref: {{ payment.reference_number }}</span>
                </p>
                <p v-if="payment.notes" class="text-sm text-slate-600 mt-1">{{ payment.notes }}</p>
                <p v-if="payment.received_by" class="text-xs text-slate-500 mt-1">Received by {{ payment.received_by }}</p>
              </div>
            </div>
            <p v-else class="text-sm text-slate-500">No payment records yet.</p>
          </div>

        </div>
      </div>
    </div>
  </AdminLayout>
</template>
