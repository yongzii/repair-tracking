<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AppLink from '../../components/AppLink.vue';
import { ensureRoles } from '../../composables/auth';
import { navigate } from '../../navigation';

const transitions = {
    pending: ['approved', 'cancelled'],
    approved: ['ordered', 'cancelled'],
    ordered: ['cancelled'],
    received: [],
    cancelled: [],
};

const loading = ref(true);
const forbidden = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const requests = ref([]);
const blockedRequests = ref([]);
const partOrders = ref([]);
const inventoryItems = ref([]);
const activeTab = ref('for_order');
const addPartOpen = ref(false);
const savingNewPart = ref(false);
const addPartIdempotencyKey = ref(null);
const jobOrders = ref([]);
const addPartForm = ref({
    part_name: '',
    quantity: 1,
    job_order_id: '',
    note: '',
    estimated_unit_price: '',
});
const statusDrafts = ref({});
const decisionDrafts = ref({});
const purchasingDrafts = ref({});
const receiveDrafts = ref({});
const savingId = ref(null);
const fulfillingKey = ref('');
const savingOrderId = ref(null);

const openCount = computed(() => requests.value.filter((request) => !['received', 'cancelled'].includes(request.status)).length);
const readyOrderCount = computed(() => partOrders.value.filter((order) => order.status === 'ready_to_order').length);
const receivedOrderCount = computed(() => partOrders.value.filter((order) => order.display_status === 'received').length);
const visibleForOrderCount = computed(() => requests.value.filter((request) => ['pending', 'approved', 'cancelled'].includes(request.status)).length
    + partOrders.value.filter((order) => ['awaiting_approval', 'awaiting_downpayment', 'ready_to_order', 'declined', 'cancelled'].includes(order.display_status)).length);
const orderedCount = computed(() => requests.value.filter((request) => request.status === 'ordered').length
    + partOrders.value.filter((order) => order.status === 'ordered').length);
const receivedCount = computed(() => requests.value.filter((request) => request.status === 'received').length
    + partOrders.value.filter((order) => ['received', 'issued_to_job_order'].includes(order.display_status)).length);
const visibleGeneralRequests = computed(() => {
    if (activeTab.value === 'ordered') return requests.value.filter((request) => request.status === 'ordered');
    if (activeTab.value === 'received') return requests.value.filter((request) => request.status === 'received');
    return requests.value.filter((request) => ['pending', 'approved', 'cancelled'].includes(request.status));
});
const visiblePartOrders = computed(() => partOrders.value.filter((order) => {
    if (activeTab.value === 'ordered') return order.status === 'ordered';
    if (activeTab.value === 'received') return ['received', 'issued_to_job_order'].includes(order.display_status);
    return ['awaiting_approval', 'awaiting_downpayment', 'ready_to_order', 'declined', 'cancelled'].includes(order.display_status);
}));

const loadRequests = async () => {
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
        const [{ data }, blockedResponse, partOrderResponse, inventoryResponse, jobsResponse] = await Promise.all([
            window.axios.get('/api/admin/part-requests'),
            window.axios.get('/api/admin/blocked-job-requests'),
            window.axios.get('/api/admin/job-order-part-orders'),
            window.axios.get('/api/admin/inventory', { params: { all: 1, active_only: 1 } }),
            window.axios.get('/api/admin/job-orders', { params: { per_page: 100 } }),
        ]);
        requests.value = data.requests || [];
        blockedRequests.value = blockedResponse.data.requests || [];
        partOrders.value = partOrderResponse.data.orders || [];
        inventoryItems.value = inventoryResponse.data.items || [];
        jobOrders.value = jobsResponse.data.jobOrders?.data || [];
        statusDrafts.value = Object.fromEntries(requests.value.map((request) => [request.id, request.status]));
        decisionDrafts.value = Object.fromEntries(partOrders.value.map((order) => [order.id, {
            required_downpayment_amount: order.required_downpayment_amount || '',
            customer_decision_note: '',
        }]));
        purchasingDrafts.value = Object.fromEntries(partOrders.value.map((order) => [order.id, {
            supplier_name: order.supplier_name || '',
            purchase_reference: order.purchase_reference || '',
            expected_arrival_date: order.expected_arrival_date || '',
        }]));
        receiveDrafts.value = Object.fromEntries([
            ...partOrders.value.map((order) => {
            const match = inventoryItems.value.find((item) => item.name.toLowerCase() === order.part_name.toLowerCase());
            return [order.id, match?.id || ''];
            }),
            ...requests.value.map((request) => {
                const match = inventoryItems.value.find((item) => item.name.toLowerCase() === request.part_name.toLowerCase());
                return [`request-${request.id}`, match?.id || ''];
            }),
        ]);
    } catch (error) {
        if (error?.response?.status === 403) {
            forbidden.value = true;
        } else {
            errorMessage.value = error?.response?.data?.message || 'Unable to load part requests.';
        }
    } finally {
        loading.value = false;
    }
};

const addPart = async () => {
    errorMessage.value = '';
    successMessage.value = '';
    savingNewPart.value = true;
    if (!addPartIdempotencyKey.value) {
        addPartIdempotencyKey.value = window.crypto.randomUUID();
    }
    try {
        let response;
        if (addPartForm.value.job_order_id) {
            response = await window.axios.post(`/api/admin/job-orders/${addPartForm.value.job_order_id}/part-orders`, {
                part_name: addPartForm.value.part_name,
                quantity: addPartForm.value.quantity,
                reason: addPartForm.value.note || 'Customer-specific part order created from Parts & Orders.',
                estimated_unit_price: addPartForm.value.estimated_unit_price,
                customer_decision: 'pending',
                idempotency_key: addPartIdempotencyKey.value,
            });
        } else {
            response = await window.axios.post('/api/admin/part-requests', {
                part_name: addPartForm.value.part_name,
                quantity: addPartForm.value.quantity,
                note: addPartForm.value.note || null,
                idempotency_key: addPartIdempotencyKey.value,
            });
        }
        successMessage.value = response.data.message || 'Part added to For Order.';
        addPartForm.value = { part_name: '', quantity: 1, job_order_id: '', note: '', estimated_unit_price: '' };
        addPartIdempotencyKey.value = null;
        addPartOpen.value = false;
        activeTab.value = 'for_order';
        await loadRequests();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to add this part.';
    } finally {
        savingNewPart.value = false;
    }
};

const customerDecision = async (order, decision) => {
    errorMessage.value = '';
    successMessage.value = '';
    savingOrderId.value = order.id;
    try {
        const { data } = await window.axios.patch(`/api/admin/job-order-part-orders/${order.id}/customer-decision`, {
            decision,
            ...decisionDrafts.value[order.id],
        });
        successMessage.value = data.message || 'Customer decision recorded.';
        await loadRequests();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to record customer decision.';
    } finally {
        savingOrderId.value = null;
    }
};

const placeSupplierOrder = async (order) => {
    errorMessage.value = '';
    successMessage.value = '';
    savingOrderId.value = order.id;
    try {
        const { data } = await window.axios.patch(`/api/admin/job-order-part-orders/${order.id}/place-order`, purchasingDrafts.value[order.id]);
        successMessage.value = data.message || 'Supplier order recorded.';
        await loadRequests();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to place supplier order.';
    } finally {
        savingOrderId.value = null;
    }
};

const receivePartOrder = async (order) => {
    errorMessage.value = '';
    successMessage.value = '';
    savingOrderId.value = order.id;
    try {
        const { data } = await window.axios.patch(`/api/admin/job-order-part-orders/${order.id}/receive`, {
            spare_part_id: receiveDrafts.value[order.id],
        });
        successMessage.value = data.message || 'Parts received into inventory.';
        await loadRequests();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to receive this parts order.';
    } finally {
        savingOrderId.value = null;
    }
};

const receiveGeneralRequest = async (request) => {
    errorMessage.value = '';
    successMessage.value = '';
    savingId.value = request.id;
    try {
        const { data } = await window.axios.patch(`/api/admin/part-requests/${request.id}/receive`, {
            spare_part_id: receiveDrafts.value[`request-${request.id}`],
        });
        successMessage.value = data.message || 'Part received into inventory.';
        await loadRequests();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to receive this part request.';
    } finally {
        savingId.value = null;
    }
};

const cancelPartOrder = async (order) => {
    const reason = window.prompt('Why is this parts order being cancelled?');
    if (!reason?.trim()) return;

    errorMessage.value = '';
    successMessage.value = '';
    savingOrderId.value = order.id;
    try {
        const { data } = await window.axios.patch(`/api/admin/job-order-part-orders/${order.id}/cancel`, {
            cancellation_reason: reason.trim(),
        });
        successMessage.value = data.message || 'Parts order cancelled.';
        await loadRequests();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to cancel this parts order.';
    } finally {
        savingOrderId.value = null;
    }
};

const updateDeviceLocation = async (order, deviceLocation) => {
    errorMessage.value = '';
    successMessage.value = '';
    savingOrderId.value = order.id;
    try {
        const { data } = await window.axios.patch(`/api/admin/job-orders/${order.job_order_id}/device-location`, {
            device_location: deviceLocation,
        });
        successMessage.value = data.message || 'Device location updated.';
        await loadRequests();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to update device location.';
    } finally {
        savingOrderId.value = null;
    }
};

const fulfillBlockedRequest = async (request, part) => {
    errorMessage.value = '';
    successMessage.value = '';
    fulfillingKey.value = `${request.job_id}:${part.id}`;

    try {
        const { data } = await window.axios.post(`/api/admin/blocked-job-orders/${request.job_id}/fulfill-parts-request`, {
            spare_part_id: part.id,
            quantity: request.requested_quantity,
            note: `Issued from inventory by matching item ${part.name}.`,
        });
        successMessage.value = data.message || 'Part issued and repair resumed.';
        await loadRequests();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to issue part to this Job Order.';
    } finally {
        fulfillingKey.value = '';
    }
};

const availableStatuses = (request) => [
    request.status,
    ...(request.status === 'pending'
        ? ['ordered', 'approved', 'cancelled']
        : (transitions[request.status] || [])),
];

const updateStatus = async (request) => {
    errorMessage.value = '';
    successMessage.value = '';
    savingId.value = request.id;

    try {
        const { data } = await window.axios.patch(`/api/admin/part-requests/${request.id}/status`, {
            status: statusDrafts.value[request.id],
        });
        successMessage.value = data.message || 'Part request status updated.';
        await loadRequests();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to update part request.';
        statusDrafts.value[request.id] = request.status;
    } finally {
        savingId.value = null;
    }
};

const statusLabel = (status) => ({
    awaiting_approval: 'Awaiting customer decision',
    awaiting_downpayment: 'Awaiting downpayment',
    ready_to_order: 'Ready to order',
    ordered: 'Ordered from supplier',
    received: 'Received',
    issued_to_job_order: 'Issued to Job Order',
    declined: 'Declined',
    cancelled: 'Cancelled',
}[status] || status.replaceAll('_', ' '));
const decisionLabel = (decision) => decision === 'rejected' ? 'Declined' : statusLabel(decision);
const formatDateTime = (value) => value ? new Date(value).toLocaleString() : '';
const formatCurrency = (value) => `PHP ${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const matchingInventoryItems = (order) => inventoryItems.value.filter((item) => item.name.trim().toLowerCase() === order.part_name.trim().toLowerCase());

onMounted(loadRequests);
</script>

<template>
  <AdminLayout title="Parts & Orders">
    <div v-if="loading" class="card p-6 text-slate-600">Loading parts and orders...</div>
    <div v-else-if="forbidden" class="card border border-rose-200 bg-rose-50 p-6 text-rose-700">You do not have access to this page.</div>

    <div v-else class="space-y-6">
      <div v-if="successMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700">{{ successMessage }}</div>
      <div v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">{{ errorMessage }}</div>

      <section class="rounded-2xl border border-slate-200 bg-slate-950 p-6 text-white shadow-xl shadow-slate-900/10">
        <p class="text-xs font-bold uppercase text-teal-300">Internal shop purchasing</p>
        <h3 class="mt-2 font-display text-3xl leading-tight">Parts &amp; Orders</h3>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-300">
          Manage shop restocking separately from customer-approved parts orders linked to repairs. Customer orders require explicit approval and verified payments before they can be placed.
        </p>
      </section>

      <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card p-5">
          <p class="text-sm font-semibold text-slate-500">Open Restock Requests</p>
          <p class="mt-2 text-3xl font-bold text-slate-950">{{ openCount }}</p>
        </div>
        <div class="card p-5">
          <p class="text-sm font-semibold text-slate-500">Customer Orders Ready</p>
          <p class="mt-2 text-3xl font-bold text-teal-700">{{ readyOrderCount }}</p>
        </div>
        <div class="card p-5">
          <p class="text-sm font-semibold text-slate-500">Parts Received</p>
          <p class="mt-2 text-3xl font-bold text-emerald-700">{{ receivedOrderCount }}</p>
        </div>
        <div class="card p-5">
          <p class="text-sm font-semibold text-slate-500">Repair-Blocked Jobs</p>
          <p class="mt-2 text-3xl font-bold text-amber-700">{{ blockedRequests.length }}</p>
        </div>
      </section>

      <section class="card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-5">
          <div>
            <h4 class="text-lg font-bold text-slate-950">Parts Queue</h4>
            <p class="mt-1 text-sm text-slate-500">General stock requests and customer-specific orders are tracked separately and shown together by purchasing stage.</p>
          </div>
          <button type="button" class="btn-primary px-4 py-2 text-sm" @click="addPartOpen = true">+ Add Part</button>
        </div>
        <div class="flex flex-wrap gap-2 border-b border-slate-200 px-5 py-3" role="tablist" aria-label="Parts order status">
          <button v-for="tab in [
            { id: 'for_order', label: 'For Order', count: visibleForOrderCount },
            { id: 'ordered', label: 'Ordered', count: orderedCount },
            { id: 'received', label: 'Received', count: receivedCount },
          ]" :key="tab.id" type="button" role="tab" :aria-selected="activeTab === tab.id"
            class="rounded-lg px-4 py-2 text-sm font-bold"
            :class="activeTab === tab.id ? 'bg-slate-950 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
            @click="activeTab = tab.id">
            {{ tab.label }} <span class="ml-1 opacity-75">{{ tab.count }}</span>
          </button>
        </div>
        <p class="px-5 pt-4 text-xs text-slate-500">Receiving a general stock request increases the matching inventory item once. Customer-specific orders remain subject to approval and downpayment checks.</p>

        <div v-if="visibleGeneralRequests.length" class="overflow-x-auto p-4">
          <table class="data-table">
            <thead>
              <tr>
                <th class="px-4 py-3 text-left">Part</th>
                <th class="px-4 py-3 text-left">Qty</th>
                <th class="px-4 py-3 text-left">Request / Note</th>
                <th class="px-4 py-3 text-left">Requested By</th>
                <th class="px-4 py-3 text-left">Status</th>
                <th class="px-4 py-3 text-left">Action</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="request in visibleGeneralRequests" :key="request.id">
                <td class="px-4 py-3 font-semibold text-slate-900">{{ request.part_name }}</td>
                <td class="px-4 py-3 text-slate-700">{{ request.quantity }}</td>
                <td class="px-4 py-3 text-slate-700">
                  <span v-if="request.job_order"><AppLink :to="`/admin/job-orders/${request.job_order.id}`" class="font-semibold text-teal-700">{{ request.job_order.job_order_number }}</AppLink></span>
                  <span v-else>General stock</span>
                  <p v-if="request.note" class="mt-1 max-w-xs whitespace-normal text-xs text-slate-500">{{ request.note }}</p>
                  <p v-if="request.received_by" class="mt-1 text-xs text-slate-500">Received by {{ request.received_by }} · {{ formatDateTime(request.received_at) }}</p>
                </td>
                <td class="px-4 py-3 text-slate-700">{{ request.requested_by || 'Legacy request' }}</td>
                <td class="px-4 py-3"><span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold capitalize text-slate-700">{{ request.status }}</span></td>
                <td class="px-4 py-3">
                  <form v-if="activeTab === 'for_order' && transitions[request.status]?.length" class="flex min-w-52 gap-2" @submit.prevent="updateStatus(request)">
                    <select v-model="statusDrafts[request.id]" class="min-w-0 rounded-lg border border-slate-300 px-2 py-2 text-sm">
                      <option v-for="status in availableStatuses(request)" :key="status" :value="status">{{ statusLabel(status) }}</option>
                    </select>
                    <button type="submit" class="btn-primary px-3 py-2 text-xs" :disabled="savingId === request.id || statusDrafts[request.id] === request.status">
                      {{ savingId === request.id ? 'Saving...' : 'Update' }}
                    </button>
                  </form>
                  <div v-else-if="activeTab === 'ordered'" class="flex min-w-64 flex-wrap gap-2">
                    <select v-model="receiveDrafts[`request-${request.id}`]" class="min-w-52 rounded-lg border border-slate-300 px-2 py-2 text-sm">
                      <option value="">Select matching inventory item</option>
                      <option v-for="item in matchingInventoryItems(request)" :key="item.id" :value="item.id">{{ item.name }} · On hand {{ item.stock_quantity }}</option>
                    </select>
                    <button type="button" class="rounded-lg bg-emerald-700 px-3 py-2 text-xs font-bold text-white disabled:opacity-50" :disabled="!receiveDrafts[`request-${request.id}`] || savingId === request.id" @click="receiveGeneralRequest(request)">
                      {{ savingId === request.id ? 'Receiving...' : 'Receive' }}
                    </button>
                  </div>
                  <span v-else class="text-xs text-slate-500">{{ request.inventory_spare_part?.name || 'Received into inventory' }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else class="p-6 text-sm text-slate-500">No general stock requests in this tab.</p>

      </section>

      <section v-if="visiblePartOrders.length" class="card overflow-hidden">
        <div class="border-b border-slate-200 p-5">
          <h4 class="text-lg font-bold text-slate-950">Customer-Specific Orders</h4>
          <p class="mt-1 text-sm text-slate-500">These orders are linked to existing Job Orders. Approval remains a staff-recorded attestation, and supplier ordering is blocked until eligible payments meet the required downpayment.</p>
        </div>
        <div class="divide-y divide-slate-200">
          <article v-for="order in visiblePartOrders" :key="order.id" class="space-y-4 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <AppLink :to="`/admin/job-orders/${order.job_order_id}`" class="font-bold text-teal-700 hover:text-teal-800">{{ order.job_order_number }}</AppLink>
                <p class="mt-1 font-semibold text-slate-900">{{ order.part_name }} · Qty {{ order.quantity }} · {{ formatCurrency(order.estimated_total) }}</p>
                <p class="mt-1 text-sm text-slate-600">{{ order.reason }}</p>
                <p class="mt-1 text-xs text-slate-500">Customer: {{ order.customer_name }} · Requested by {{ order.requested_by || 'Technician' }}</p>
                <p class="mt-1 text-xs text-slate-500">Customer decision: {{ decisionLabel(order.customer_decision) }}<span v-if="order.customer_decision_recorded_by">, recorded by {{ order.customer_decision_recorded_by }}</span></p>
              </div>
              <span class="rounded-full bg-teal-50 px-3 py-1 text-xs font-bold text-teal-800 ring-1 ring-teal-200">{{ statusLabel(order.display_status || order.status) }}</span>
            </div>

            <div class="flex flex-wrap items-center gap-2 text-xs">
              <span class="font-semibold text-slate-600">Device: {{ order.device_location === 'AT_SHOP' ? 'At Shop' : order.device_location === 'WITH_CUSTOMER' ? 'With Customer' : 'Location not recorded' }}</span>
              <span v-if="order.device_location_updated_at" class="text-slate-500">Updated {{ formatDateTime(order.device_location_updated_at) }} by {{ order.device_location_updated_by || 'a shop user' }}</span>
              <button v-if="order.device_location !== 'AT_SHOP'" type="button" class="rounded border border-slate-300 px-2 py-1 font-bold text-slate-700" :disabled="savingOrderId === order.id" @click="updateDeviceLocation(order, 'AT_SHOP')">Record Returned to Shop</button>
              <button v-if="order.device_location !== 'WITH_CUSTOMER'" type="button" class="rounded border border-slate-300 px-2 py-1 font-bold text-slate-700" :disabled="savingOrderId === order.id" @click="updateDeviceLocation(order, 'WITH_CUSTOMER')">Record Released to Customer</button>
            </div>

            <div v-if="order.status === 'awaiting_approval'" class="rounded-lg border border-slate-200 bg-slate-50 p-4">
              <p class="text-sm font-bold text-slate-800">Record the customer's explicit decision</p>
              <p class="mt-1 text-xs text-slate-500">Use only after receiving the customer's decision through the shop's existing consent process. This action records staff attestation; it does not contact the customer.</p>
              <div class="mt-3 grid gap-3 md:grid-cols-2">
                <label class="text-xs font-semibold text-slate-600">Required downpayment (PHP)
                  <input v-model="decisionDrafts[order.id].required_downpayment_amount" type="number" min="0.01" :max="order.estimated_total" step="0.01" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                </label>
                <label class="text-xs font-semibold text-slate-600">Customer name / decision evidence
                  <input v-model="decisionDrafts[order.id].customer_decision_note" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Name and consent method or note" maxlength="255" />
                </label>
              </div>
              <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" class="rounded-lg bg-teal-700 px-4 py-2 text-xs font-bold text-white hover:bg-teal-800 disabled:opacity-60" :disabled="savingOrderId === order.id" @click="customerDecision(order, 'approved')">Record Approval</button>
                <button type="button" class="rounded-lg border border-rose-300 px-4 py-2 text-xs font-bold text-rose-700 hover:bg-rose-50 disabled:opacity-60" :disabled="savingOrderId === order.id || !decisionDrafts[order.id].customer_decision_note.trim()" @click="customerDecision(order, 'rejected')">Record Rejection</button>
              </div>
            </div>

            <div v-else-if="['awaiting_downpayment', 'ready_to_order'].includes(order.status)" class="rounded-lg border border-slate-200 p-4">
              <p class="text-sm text-slate-700">Required: {{ formatCurrency(order.required_downpayment_amount) }} · Eligible recorded payments: {{ formatCurrency(order.eligible_paid_amount) }} · Remaining: {{ formatCurrency(order.remaining_downpayment_amount) }}</p>
              <p v-if="!order.can_order" class="mt-1 text-xs font-semibold text-amber-700">Ordering remains blocked until the required amount is covered by recorded eligible payments.</p>
              <div class="mt-3 grid gap-3 md:grid-cols-3">
                <input v-model="purchasingDrafts[order.id].supplier_name" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Supplier" maxlength="150" />
                <input v-model="purchasingDrafts[order.id].purchase_reference" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Purchase reference (optional)" maxlength="100" />
                <input v-model="purchasingDrafts[order.id].expected_arrival_date" type="date" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
              </div>
              <button type="button" class="mt-3 rounded-lg bg-slate-900 px-4 py-2 text-xs font-bold text-white hover:bg-slate-800 disabled:opacity-50" :disabled="!order.can_order || !purchasingDrafts[order.id].supplier_name.trim() || savingOrderId === order.id" @click="placeSupplierOrder(order)">Place Supplier Order</button>
            </div>

            <div v-else-if="order.status === 'ordered'" class="rounded-lg border border-slate-200 bg-slate-50 p-4">
              <p class="text-sm text-slate-700">Supplier: {{ order.supplier_name }}<span v-if="order.purchase_reference"> · Reference {{ order.purchase_reference }}</span><span v-if="order.expected_arrival_date"> · Expected {{ order.expected_arrival_date }}</span></p>
              <div class="mt-3 flex flex-wrap gap-2">
                <select v-model="receiveDrafts[order.id]" class="min-w-56 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                  <option value="">Select received inventory item</option>
                  <option v-for="item in matchingInventoryItems(order)" :key="item.id" :value="item.id">{{ item.name }} · On hand {{ item.stock_quantity }}</option>
                </select>
                <button type="button" class="rounded-lg bg-emerald-700 px-4 py-2 text-xs font-bold text-white hover:bg-emerald-800 disabled:opacity-50" :disabled="!receiveDrafts[order.id] || savingOrderId === order.id" @click="receivePartOrder(order)">Receive Full Quantity</button>
              </div>
              <p v-if="!matchingInventoryItems(order).length" class="mt-2 text-xs text-amber-700">Create an active inventory item named “{{ order.part_name }}” before recording receipt.</p>
              <p class="mt-2 text-xs text-slate-500">This receives the full ordered quantity once and records an inventory movement; partial receipts are not supported.</p>
            </div>

            <div v-else-if="order.status === 'received'" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
              <p class="text-sm font-semibold text-emerald-800">{{ order.quantity }} received into {{ order.inventory_spare_part?.name || 'inventory' }}<span v-if="order.received_at"> · {{ order.received_at }}</span></p>
              <p v-if="order.device_location === 'WITH_CUSTOMER'" class="mt-2 text-xs font-semibold text-amber-800">Contact the customer to arrange the device's return before continuing repair.</p>
              <a v-if="order.device_location === 'WITH_CUSTOMER' && order.customer_phone" :href="`tel:${order.customer_phone}`" class="mt-2 inline-flex text-xs font-bold text-teal-800 underline">Call {{ order.customer_name }}</a>
              <p v-if="order.issued_at" class="mt-2 text-xs text-emerald-800">Issued to this repair; Job Order status was not changed.</p>
              <p v-else-if="order.device_location === 'AT_SHOP'" class="mt-2 text-xs text-slate-600">Technician can issue the received part from the Job Order workspace. Repair status remains independent.</p>
            </div>

            <div v-if="order.status === 'cancelled'" class="text-xs text-slate-500">Cancelled<span v-if="order.cancellation_reason">: {{ order.cancellation_reason }}</span></div>
            <p v-if="['awaiting_approval', 'awaiting_downpayment', 'ready_to_order'].includes(order.status)" class="text-xs text-slate-500">Cancelling does not change Job Order payments or issue a refund. Supplier orders cannot be cancelled in this workflow.</p>
            <button v-if="['awaiting_approval', 'awaiting_downpayment', 'ready_to_order'].includes(order.status)" type="button" class="text-xs font-semibold text-rose-700 hover:text-rose-800 disabled:opacity-50" :disabled="savingOrderId === order.id" @click="cancelPartOrder(order)">Cancel Parts Order</button>
          </article>
        </div>
      </section>

      <section class="card overflow-hidden">
        <div class="border-b border-slate-200 p-5">
          <h4 class="text-lg font-bold text-slate-950">Repair-Blocked Jobs</h4>
          <p class="mt-1 text-sm text-slate-500">Separate, explicitly Job Order-linked requests where a repair cannot proceed. Issuing stock here deducts inventory and resumes that Job Order.</p>
        </div>
        <div v-if="blockedRequests.length" class="divide-y divide-slate-200">
          <article v-for="request in blockedRequests" :key="request.job_id" class="space-y-4 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <AppLink :to="`/admin/job-orders/${request.job_id}`" class="font-bold text-teal-700 hover:text-teal-800">{{ request.job_order_number }}</AppLink>
                <p class="mt-1 font-semibold text-slate-900">{{ request.requested_part }} · {{ request.requested_quantity }} requested</p>
                <p class="mt-1 text-xs text-slate-500">Requested by {{ request.technician_name || 'Technician' }} · {{ request.requested_at_formatted }}</p>
                <p v-if="request.request_note" class="mt-2 text-sm text-slate-600">{{ request.request_note }}</p>
              </div>
              <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-800 ring-1 ring-amber-200">Waiting for Parts</span>
            </div>
            <div v-if="request.inventory_matches.length" class="grid gap-3 lg:grid-cols-2">
              <div v-for="part in request.inventory_matches" :key="part.id" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
                <div>
                  <p class="font-semibold text-slate-900">{{ part.name }}</p>
                  <p class="text-xs text-slate-500">Available: {{ part.stock_quantity }} {{ part.unit || 'pcs' }}</p>
                </div>
                <button
                  type="button"
                  class="rounded-lg px-3 py-2 text-xs font-bold"
                  :class="part.stock_quantity >= request.requested_quantity ? 'bg-slate-950 text-white hover:bg-slate-800' : 'cursor-not-allowed bg-slate-200 text-slate-500'"
                  :disabled="part.stock_quantity < request.requested_quantity || fulfillingKey === `${request.job_id}:${part.id}`"
                  @click="fulfillBlockedRequest(request, part)"
                >
                  {{ fulfillingKey === `${request.job_id}:${part.id}` ? 'Issuing...' : part.stock_quantity >= request.requested_quantity ? 'Issue to Job' : 'Insufficient Stock' }}
                </button>
              </div>
            </div>
            <p v-else class="rounded-lg border border-dashed border-slate-300 p-4 text-sm text-slate-500">No matching active inventory item is available.</p>
          </article>
        </div>
        <p v-else class="p-8 text-center text-sm text-slate-500">No repairs are currently blocked by a part request.</p>
      </section>

      <div v-if="addPartOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4" role="dialog" aria-modal="true" aria-labelledby="add-part-title" @click.self="addPartOpen = false">
        <form class="w-full max-w-lg space-y-4 rounded-2xl bg-white p-6 shadow-2xl" @submit.prevent="addPart">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h4 id="add-part-title" class="text-xl font-bold text-slate-950">Add Part</h4>
              <p class="mt-1 text-sm text-slate-500">Choose a Job Order only for a customer-specific order.</p>
            </div>
            <button type="button" class="rounded-lg px-2 py-1 text-slate-500 hover:bg-slate-100" aria-label="Close" @click="addPartOpen = false">✕</button>
          </div>
          <label class="block text-sm font-semibold text-slate-700">Part name
            <input v-model="addPartForm.part_name" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" maxlength="150" required />
          </label>
          <div class="grid grid-cols-2 gap-3">
            <label class="text-sm font-semibold text-slate-700">Quantity
              <input v-model.number="addPartForm.quantity" type="number" min="1" max="1000000" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required />
            </label>
            <label class="text-sm font-semibold text-slate-700">Job Order (optional)
              <select v-model="addPartForm.job_order_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                <option value="">General stock request</option>
                <option v-for="job in jobOrders" :key="job.id" :value="String(job.id)">
                  {{ job.job_order_number }} · {{ job.customer_name }} · {{ job.current_status }}
                </option>
              </select>
            </label>
          </div>
          <label v-if="addPartForm.job_order_id" class="block text-sm font-semibold text-slate-700">Estimated unit price (PHP)
            <input v-model.number="addPartForm.estimated_unit_price" type="number" min="0.01" step="0.01" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required />
          </label>
          <label class="block text-sm font-semibold text-slate-700">Note (optional)
            <textarea v-model="addPartForm.note" rows="2" maxlength="255" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></textarea>
          </label>
          <p v-if="addPartForm.job_order_id" class="rounded-lg bg-amber-50 p-3 text-xs text-amber-900">This creates a customer-specific order for the selected existing job. It starts Awaiting customer decision; approval and downpayment checks remain required before ordering.</p>
          <p v-else class="rounded-lg bg-slate-50 p-3 text-xs text-slate-600">This creates a general shop stock request. It does not need a Job Order.</p>
          <div class="flex justify-end gap-2">
            <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold" @click="addPartOpen = false">Cancel</button>
            <button type="submit" class="btn-primary px-4 py-2 text-sm disabled:opacity-60" :disabled="savingNewPart">
              {{ savingNewPart ? 'Saving...' : 'Add to For Order' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </AdminLayout>
</template>
