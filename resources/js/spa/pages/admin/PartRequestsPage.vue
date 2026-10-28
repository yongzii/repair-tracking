<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import { ensureRoles } from '../../composables/auth';
import { navigate } from '../../navigation';

const loading = ref(true);
const forbidden = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const activeTab = ref('for_order');
const checklist = ref([]);
const requests = ref([]);
const inventoryItems = ref([]);
const selectedParts = ref({});
const quantityDrafts = ref({});
const savingOrder = ref(false);
const addPartOpen = ref(false);
const savingNewPart = ref(false);
const addPartForm = ref({ part_name: '', category: '', quantity: 1 });
const receiveId = ref(null);
const receiveDrafts = ref({});
const receiveCategoryDrafts = ref({});

const orderedRequests = computed(() => requests.value.filter((request) => request.status === 'ordered'));
const receivedRequests = computed(() => requests.value.filter((request) => request.status === 'received'));
const selectedCount = computed(() => Object.values(selectedParts.value).filter(Boolean).length);
const checklistRows = computed(() => checklist.value.map((item) => {
    const key = item.part_name.trim().toLowerCase();
    const latest = requests.value.find((request) => request.part_name.trim().toLowerCase() === key
        && ['pending', 'approved'].includes(request.status));
    return {
        ...item,
        key,
        quantity: quantityDrafts.value[key] ?? latest?.quantity ?? 1,
        selected: Boolean(selectedParts.value[key]),
        category: item.category || '',
    };
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
        const [{ data }, inventoryResponse] = await Promise.all([
            window.axios.get('/api/admin/part-requests'),
            window.axios.get('/api/admin/inventory', { params: { all: 1, active_only: 1 } }),
        ]);
        checklist.value = data.checklist || [];
        requests.value = data.requests || [];
        inventoryItems.value = inventoryResponse.data.items || [];
        receiveDrafts.value = Object.fromEntries(
            requests.value.map((request) => {
                const match = inventoryItems.value.find((item) => item.name.trim().toLowerCase() === request.part_name.trim().toLowerCase());
                return [request.id, match?.id || ''];
            }),
        );
        receiveCategoryDrafts.value = Object.fromEntries(
            requests.value.map((request) => {
                const match = inventoryItems.value.find((item) => item.name.trim().toLowerCase() === request.part_name.trim().toLowerCase());
                return [request.id, match?.category || ''];
            }),
        );
    } catch (error) {
        forbidden.value = error?.response?.status === 403;
        if (!forbidden.value) errorMessage.value = error?.response?.data?.message || 'Unable to load the parts checklist.';
    } finally {
        loading.value = false;
    }
};

const setQuantity = (row, value) => {
    const quantity = Number(value);
    quantityDrafts.value[row.key] = value === '' ? '' : quantity;
    if (selectedParts.value[row.key]) selectedParts.value[row.key].quantity = quantity;
};

const togglePart = (row, selected) => {
    if (selected) {
        selectedParts.value[row.key] = {
            part_name: row.part_name,
            category: row.category || null,
            quantity: Math.max(1, Number(row.quantity) || 1),
        };
    } else {
        delete selectedParts.value[row.key];
    }
};

const orderSelected = async () => {
    errorMessage.value = '';
    successMessage.value = '';
    const items = Object.values(selectedParts.value);
    if (!items.length) return;
    if (items.some((item) => !Number.isInteger(Number(item.quantity)) || Number(item.quantity) < 1)) {
        errorMessage.value = 'Each selected quantity must be a positive whole number.';
        return;
    }

    savingOrder.value = true;
    try {
        for (const item of items) {
            const key = window.crypto.randomUUID();
            const { data } = await window.axios.post('/api/admin/part-requests', {
                part_name: item.part_name,
                category: item.category,
                quantity: Number(item.quantity),
                note: 'Created from the reusable parts checklist.',
                status: 'ordered',
                idempotency_key: key,
            });
        }
        selectedParts.value = {};
        activeTab.value = 'ordered';
        successMessage.value = `${items.length} part order${items.length === 1 ? '' : 's'} created.`;
        await loadRequests();
    } catch (error) {
        const firstError = error?.response?.data?.errors ? Object.values(error.response.data.errors)[0]?.[0] : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to create the selected orders.';
    } finally {
        savingOrder.value = false;
    }
};

const addPart = async () => {
    errorMessage.value = '';
    successMessage.value = '';
    const name = addPartForm.value.part_name.trim();
    const category = addPartForm.value.category.trim();
    const quantity = Number(addPartForm.value.quantity);
    if (!name) {
        errorMessage.value = 'Part name is required.';
        return;
    }
    if (!Number.isInteger(quantity) || quantity < 1) {
        errorMessage.value = 'Quantity must be a positive whole number.';
        return;
    }
    if (checklist.value.some((item) => item.part_name.trim().toLowerCase() === name.toLowerCase())) {
        errorMessage.value = 'This part is already in the reusable checklist.';
        return;
    }

    savingNewPart.value = true;
    try {
        await window.axios.post('/api/admin/part-requests', {
            part_name: name,
            category: category || null,
            quantity,
            register_part: true,
            idempotency_key: window.crypto.randomUUID(),
        });
        addPartForm.value = { part_name: '', category: '', quantity: 1 };
        addPartOpen.value = false;
        successMessage.value = 'Part added to the reusable checklist.';
        await loadRequests();
    } catch (error) {
        const firstError = error?.response?.data?.errors ? Object.values(error.response.data.errors)[0]?.[0] : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to add this part.';
    } finally {
        savingNewPart.value = false;
    }
};

const receiveRequest = async (request) => {
    errorMessage.value = '';
    successMessage.value = '';
    receiveId.value = request.id;
    try {
        const { data } = await window.axios.patch(`/api/admin/part-requests/${request.id}/receive`, {
            spare_part_id: receiveDrafts.value[request.id],
        });
        successMessage.value = data.message || 'Parts received into inventory.';
        await loadRequests();
    } catch (error) {
        const firstError = error?.response?.data?.errors ? Object.values(error.response.data.errors)[0]?.[0] : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to receive this order.';
    } finally {
        receiveId.value = null;
    }
};

const inventoryCategories = computed(() => [...new Set(
    inventoryItems.value
        .map((item) => item.category?.trim())
        .map((category) => category || '__uncategorized__'),
)].sort((first, second) => first.localeCompare(second)));
const categoryLabel = (category) => category === '__uncategorized__' ? 'No category' : category;
const inventoryItemsByCategory = (request) => {
    const category = receiveCategoryDrafts.value[request.id];
    if (!category) return [];

    return inventoryItems.value.filter((item) => (item.category?.trim() || '__uncategorized__') === category);
};
const setReceiveCategory = (request, category) => {
    receiveCategoryDrafts.value[request.id] = category;
    const selectedItem = inventoryItems.value.find((item) => String(item.id) === String(receiveDrafts.value[request.id]));
    if (selectedItem?.category?.trim() !== category) {
        receiveDrafts.value[request.id] = '';
    }
};
const formatDate = (value) => value ? new Date(value).toLocaleDateString() : '—';
const statusClass = (status) => status === 'received' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700';

onMounted(loadRequests);
</script>

<template>
  <AdminLayout title="For Order">
    <div v-if="loading" class="card p-6 text-slate-600">Loading parts checklist...</div>
    <div v-else-if="forbidden" class="card border border-rose-200 bg-rose-50 p-6 text-rose-700">You do not have access to this page.</div>
    <div v-else class="space-y-6">
      <div v-if="successMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700">{{ successMessage }}</div>
      <div v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">{{ errorMessage }}</div>

      <section class="admin-hero p-6">
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-teal-700">Internal shop purchasing</p>
        <h3 class="mt-2 font-display text-3xl leading-tight">For Order</h3>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Choose parts from your reusable checklist, set quantities, and order only what you select.</p>
      </section>

      <section class="card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-5">
          <div>
            <h4 class="text-lg font-bold text-slate-950">Parts checklist</h4>
            <p class="mt-1 text-sm text-slate-500">Part names stay here after ordering so they can be purchased again.</p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm font-semibold text-slate-500">{{ selectedCount }} selected</span>
            <button type="button" class="btn-primary px-4 py-2.5 text-sm disabled:cursor-not-allowed disabled:opacity-50" :disabled="!selectedCount || savingOrder" @click="orderSelected">
              {{ savingOrder ? 'Ordering...' : '+ Order' }}
            </button>
            <button type="button" class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:border-teal-300 hover:text-teal-700" @click="addPartOpen = true">+ Add Part</button>
          </div>
        </div>
        <div v-if="activeTab === 'for_order'" class="p-4">
          <div v-if="checklistRows.length" class="divide-y divide-slate-100 rounded-xl border border-slate-200">
            <div v-for="row in checklistRows" :key="row.key" class="flex flex-wrap items-center gap-4 px-4 py-3">
              <input :checked="row.selected" type="checkbox" class="h-5 w-5 rounded border-slate-300 text-teal-600 focus:ring-teal-500" @change="togglePart(row, $event.target.checked)" />
              <span class="min-w-48 flex-1 font-semibold text-slate-900">
                {{ row.part_name }}
                <span v-if="row.category" class="ml-2 rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-500">{{ row.category }}</span>
              </span>
              <label class="flex items-center gap-2 text-sm font-semibold text-slate-600">
                Qty
                <input :value="row.quantity" type="number" min="1" step="1" class="w-24 rounded-lg border border-slate-300 px-3 py-2 text-slate-900" @input="setQuantity(row, $event.target.value)" />
              </label>
            </div>
          </div>
          <p v-else class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">No parts yet. Add your first commonly purchased part.</p>
        </div>

        <div v-else-if="activeTab === 'ordered'" class="overflow-x-auto p-4">
          <table class="data-table min-w-[720px]">
            <thead><tr><th>Part</th><th>Quantity</th><th>Order date</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
              <tr v-for="request in orderedRequests" :key="request.id">
                <td class="font-semibold">{{ request.part_name }} <span v-if="request.category" class="text-xs font-medium text-slate-500">({{ request.category }})</span></td><td>{{ request.quantity }}</td><td>{{ formatDate(request.created_at) }}</td>
                <td><span class="rounded-full px-3 py-1 text-xs font-bold capitalize" :class="statusClass(request.status)">{{ request.status }}</span></td>
                <td>
                  <div class="flex min-w-64 gap-2">
                    <select :value="receiveCategoryDrafts[request.id]" class="rounded-lg border border-slate-300 px-2 py-2 text-sm" @change="setReceiveCategory(request, $event.target.value)">
                      <option value="">Select category</option>
                      <option v-for="category in inventoryCategories" :key="category" :value="category">{{ categoryLabel(category) }}</option>
                    </select>
                    <select v-model="receiveDrafts[request.id]" class="rounded-lg border border-slate-300 px-2 py-2 text-sm" :disabled="!receiveCategoryDrafts[request.id]">
                      <option value="">Select inventory item</option>
                      <option v-for="item in inventoryItemsByCategory(request)" :key="item.id" :value="item.id">{{ item.name }} · On hand {{ item.stock_quantity }}</option>
                    </select>
                    <button type="button" class="rounded-lg bg-emerald-700 px-3 py-2 text-xs font-bold text-white disabled:opacity-50" :disabled="!receiveDrafts[request.id] || receiveId === request.id" @click="receiveRequest(request)">{{ receiveId === request.id ? 'Receiving...' : 'Receive' }}</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
          <p v-if="!orderedRequests.length" class="p-6 text-center text-sm text-slate-500">No orders yet.</p>
        </div>

        <div v-else class="overflow-x-auto p-4">
          <table class="data-table min-w-[620px]">
            <thead><tr><th>Part</th><th>Quantity</th><th>Received date</th><th>Status</th></tr></thead>
            <tbody><tr v-for="request in receivedRequests" :key="request.id"><td class="font-semibold">{{ request.part_name }}</td><td>{{ request.quantity }}</td><td>{{ formatDate(request.received_at) }}</td><td><span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">Received</span></td></tr></tbody>
          </table>
          <p v-if="!receivedRequests.length" class="p-6 text-center text-sm text-slate-500">No received orders yet.</p>
        </div>

        <div class="flex flex-wrap gap-2 border-t border-slate-200 px-5 py-3">
          <button v-for="tab in [{ id: 'for_order', label: 'For Order', count: checklist.length }, { id: 'ordered', label: 'Ordered', count: orderedRequests.length }, { id: 'received', label: 'Received', count: receivedRequests.length }]" :key="tab.id" type="button" class="rounded-lg px-4 py-2 text-sm font-bold" :class="activeTab === tab.id ? 'bg-slate-950 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'" @click="activeTab = tab.id">{{ tab.label }} <span class="ml-1 opacity-75">{{ tab.count }}</span></button>
        </div>
      </section>

    </div>

    <div v-if="addPartOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4" role="dialog" aria-modal="true">
      <form class="w-full max-w-md space-y-5 rounded-2xl bg-white p-6 shadow-2xl" @submit.prevent="addPart">
        <div class="flex items-start justify-between"><h4 class="text-xl font-bold text-slate-950">Add Part</h4><button type="button" class="text-slate-500" @click="addPartOpen = false">✕</button></div>
        <label class="block text-sm font-semibold text-slate-700">Part name<input v-model="addPartForm.part_name" maxlength="150" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5" required /></label>
        <label class="block text-sm font-semibold text-slate-700">Category
          <input v-model="addPartForm.category" maxlength="100" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5" placeholder="LCD, Battery, Keyboard" required />
        </label>
        <label class="block text-sm font-semibold text-slate-700">Quantity<input v-model.number="addPartForm.quantity" type="number" min="1" step="1" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5" required /></label>
        <div class="flex justify-end gap-2"><button type="button" class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700" @click="addPartOpen = false">Cancel</button><button type="submit" class="btn-primary px-4 py-2.5 text-sm disabled:opacity-50" :disabled="savingNewPart">{{ savingNewPart ? 'Saving...' : 'Add Part' }}</button></div>
      </form>
    </div>

  </AdminLayout>
</template>
