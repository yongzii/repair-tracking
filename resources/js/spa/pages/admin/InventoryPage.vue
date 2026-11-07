<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import { adminRoles, authState, ensureRoles } from '../../composables/auth';
import { navigate } from '../../navigation';

const loading = ref(true);
const forbidden = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const inventory = ref({ data: [] });
const summary = ref({
    total_parts: 0,
    active_parts: 0,
    low_stock_parts: 0,
});
const canWriteInventory = computed(() => [...adminRoles, 'staff'].includes(authState.user?.role));
const filters = reactive({
    search: '',
    active_only: true,
    low_stock: false,
});

const createForm = reactive({
    name: '',
    part_number: '',
    category: '',
    stock_quantity: '',
    reorder_level: '',
    unit_cost: '',
    is_active: true,
});

const editingId = ref(null);
const originalStockQuantity = ref(null);
const editForm = reactive({
    name: '',
    part_number: '',
    category: '',
    stock_quantity: '',
    stock_note: '',
    reorder_level: '',
    unit_cost: '',
    is_active: true,
});

const adjustModal = ref({
    open: false,
    partId: null,
    partName: '',
    action: 'in',
    quantity: 0,
    note: '',
});
const orderModal = ref({
    open: false,
    partId: null,
    partName: '',
    quantity: 1,
});

const activeFilters = computed(() => ({
    search: filters.search || undefined,
    active_only: filters.active_only ? 1 : 0,
    low_stock: filters.low_stock ? 1 : 0,
}));
const stockQuantityChanging = computed(() => (
    editingId.value !== null
    && Number(editForm.stock_quantity) !== originalStockQuantity.value
));

const loadInventory = async () => {
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
        const { data } = await window.axios.get('/api/admin/inventory', { params: activeFilters.value });
        inventory.value = data.items;
        summary.value = data.summary;
    } catch (error) {
        forbidden.value = true;
        errorMessage.value = error?.response?.data?.message || 'Unable to load inventory.';
    } finally {
        loading.value = false;
    }
};

const createPart = async () => {
    errorMessage.value = '';
    successMessage.value = '';
    try {
        const payload = {
            ...createForm,
            part_number: createForm.part_number || null,
            category: createForm.category || null,
            unit_cost: 0,
        };
        const { data } = await window.axios.post('/api/admin/inventory', payload);
        successMessage.value = data.message || 'Spare part created.';
        Object.assign(createForm, {
            name: '',
            part_number: '',
            category: '',
            stock_quantity: '',
            reorder_level: '',
            unit_cost: '',
            is_active: true,
        });
        await loadInventory();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || error?.message || 'Unable to create spare part.';
    }
};

const startEdit = (item) => {
    editingId.value = item.id;
    originalStockQuantity.value = Number(item.stock_quantity);
    Object.assign(editForm, {
        name: item.name,
        part_number: item.part_number || '',
        category: item.category || '',
        stock_quantity: item.stock_quantity,
        stock_note: '',
        reorder_level: item.reorder_level,
        unit_cost: Number(item.unit_cost || 0),
        is_active: item.is_active,
    });
};

const cancelEdit = () => {
    editingId.value = null;
    originalStockQuantity.value = null;
};

const saveEdit = async (partId) => {
    errorMessage.value = '';
    successMessage.value = '';
    try {
        const payload = {
            ...editForm,
            part_number: editForm.part_number || null,
            category: editForm.category || null,
            stock_note: editForm.stock_note || null,
        };
        const { data } = await window.axios.put(`/api/admin/inventory/${partId}`, payload);
        successMessage.value = data.message || 'Spare part updated.';
        editingId.value = null;
        originalStockQuantity.value = null;
        await loadInventory();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || error?.message || 'Unable to update spare part.';
    }
};

const deletePart = async (partId) => {
    if (!window.confirm('Delete this spare part?')) return;
    errorMessage.value = '';
    successMessage.value = '';
    try {
        const { data } = await window.axios.delete(`/api/admin/inventory/${partId}`);
        successMessage.value = data.message || 'Spare part deleted.';
        await loadInventory();
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Unable to delete spare part.';
    }
};

const openAdjust = (item, action) => {
    adjustModal.value = {
        open: true,
        partId: item.id,
        partName: item.name,
        action,
        quantity: action === 'set' ? item.stock_quantity : 0,
        note: '',
    };
};

const submitAdjust = async () => {
    errorMessage.value = '';
    successMessage.value = '';
    try {
        const { data } = await window.axios.post(
            `/api/admin/inventory/${adjustModal.value.partId}/adjust`,
            {
                action: adjustModal.value.action,
                quantity: adjustModal.value.quantity,
                note: adjustModal.value.note || null,
            }
        );
        successMessage.value = data.message || 'Stock updated.';
        adjustModal.value.open = false;
        await loadInventory();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to adjust stock.';
    }
};

const openForOrder = (item) => {
    orderModal.value = {
        open: true,
        partId: item.id,
        partName: item.name,
        quantity: Math.max(1, Number(item.reorder_level || 1) - Number(item.stock_quantity || 0)),
    };
};

const submitForOrder = async () => {
    errorMessage.value = '';
    successMessage.value = '';
    try {
        const { data } = await window.axios.post('/api/admin/part-requests', {
            part_name: orderModal.value.partName,
            category: inventory.value.data.find((item) => item.id === orderModal.value.partId)?.category || null,
            quantity: orderModal.value.quantity,
            note: `For Order from inventory: ${orderModal.value.partName}.`,
            idempotency_key: window.crypto.randomUUID(),
        });
        successMessage.value = data.message || 'Part added to For Order.';
        orderModal.value.open = false;
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to add part to For Order.';
    }
};

onMounted(loadInventory);
</script>

<template>
  <AdminLayout title="Inventory">
    <div v-if="loading" class="card p-6 text-slate-600">Loading inventory...</div>
    <div v-else-if="forbidden" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">You do not have access to this page.</div>

    <div v-else class="space-y-6">
      <section class="admin-hero p-6">
        <p class="text-xs font-bold uppercase text-teal-700">Spare parts control</p>
        <h3 class="mt-2 font-display text-3xl">Inventory</h3>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Monitor spare parts, stock levels, and stock movements used in repair jobs.</p>
      </section>

      <div v-if="successMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700">{{ successMessage }}</div>
      <div v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">{{ errorMessage }}</div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="metric-card">
          <p class="text-slate-500 text-sm">Total Parts</p>
          <p class="text-3xl font-bold text-slate-900">{{ summary.total_parts }}</p>
        </div>
        <div class="metric-card">
          <p class="text-slate-500 text-sm">Active Parts</p>
          <p class="text-3xl font-bold text-slate-900">{{ summary.active_parts }}</p>
        </div>
        <div class="metric-card">
          <p class="text-slate-500 text-sm">Low Stock</p>
          <p class="text-3xl font-bold text-rose-700">{{ summary.low_stock_parts }}</p>
        </div>
      </div>

      <div v-if="canWriteInventory" class="admin-panel p-6">
        <h3 class="text-lg font-bold text-slate-950 mb-4">Add Spare Part</h3>
        <form class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3" @submit.prevent="createPart">
          <input v-model="createForm.name" class="border border-slate-300 px-4 py-3" placeholder="Part name" required />
          <input v-model="createForm.part_number" class="border border-slate-300 px-4 py-3" placeholder="Item code (optional)" />
          <input v-model="createForm.category" class="border border-slate-300 px-4 py-3" placeholder="Category" />
          <input v-model.number="createForm.stock_quantity" type="number" min="0" class="border border-slate-300 px-4 py-3" placeholder="Initial stock" required />
          <input v-model.number="createForm.reorder_level" type="number" min="0" class="border border-slate-300 px-4 py-3" placeholder="Reorder level" required />
          <label class="inline-flex w-fit items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700">
            <input v-model="createForm.is_active" type="checkbox" />
            Active
          </label>
          <button type="submit" class="btn-primary py-3">Save Part</button>
        </form>
      </div>

      <div class="admin-panel p-6">
        <div class="flex flex-wrap items-center gap-3 mb-4">
          <input v-model="filters.search" class="flex-1 min-w-[200px] border border-slate-300 px-4 py-3" placeholder="Search part name, number, category" />
          <label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700">
            <input v-model="filters.active_only" type="checkbox" />
            Active only
          </label>
          <label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700">
            <input v-model="filters.low_stock" type="checkbox" />
            Low stock only
          </label>
          <button type="button" class="btn-primary px-4 py-3" @click="loadInventory">Apply</button>
        </div>

        <div class="overflow-x-auto">
          <table class="data-table">
            <thead>
              <tr>
                <th class="px-4 py-3 text-left text-slate-600">Part</th>
                <th class="px-4 py-3 text-left text-slate-600">Stock</th>
                <th class="px-4 py-3 text-left text-slate-600">Status</th>
                <th class="px-4 py-3 text-left text-slate-600">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in inventory.data" :key="item.id">
                <td class="px-4 py-3">
                  <p class="font-semibold text-slate-900">{{ item.name }}</p>
                  <p class="text-xs text-slate-500">{{ item.part_number || 'No item code' }} - {{ item.category || 'No category' }}</p>
                </td>
                <td class="px-4 py-3">
                  <p class="font-semibold" :class="item.is_low_stock ? 'text-rose-700' : 'text-slate-900'">
                    {{ item.stock_quantity }} {{ item.unit || 'pcs' }}
                  </p>
                  <p class="text-xs text-slate-500">Reorder at {{ item.reorder_level }}</p>
                </td>
                <td class="px-4 py-3">
                  <span class="badge" :class="item.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700'">
                    {{ item.is_active ? 'Active' : 'Inactive' }}
                  </span>
                </td>
                <td class="px-4 py-3">
                  <div v-if="canWriteInventory" class="flex flex-wrap items-center gap-2">
                    <button type="button" class="rounded-lg border border-teal-200 px-3 py-1.5 text-xs font-bold text-teal-700 hover:bg-teal-50" @click="openForOrder(item)">+ For Order</button>
                    <button type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700 hover:border-teal-200 hover:text-teal-700" @click="startEdit(item)">Edit</button>
                    <button type="button" class="rounded-lg border border-emerald-200 px-3 py-1.5 text-xs font-bold text-emerald-700 hover:bg-emerald-50" @click="openAdjust(item, 'in')">+Stock</button>
                    <button type="button" class="rounded-lg border border-amber-200 px-3 py-1.5 text-xs font-bold text-amber-700 hover:bg-amber-50" @click="openAdjust(item, 'out')">-Stock</button>
                    <button type="button" class="rounded-lg border border-blue-200 px-3 py-1.5 text-xs font-bold text-blue-700 hover:bg-blue-50" @click="openAdjust(item, 'set')">Set</button>
                    <button type="button" class="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-bold text-rose-600 hover:bg-rose-50" @click="deletePart(item.id)">Delete</button>
                  </div>
                  <span v-else class="text-sm text-slate-500">Read-only</span>
                </td>
              </tr>
              <tr v-if="!inventory.data?.length">
                <td colspan="4" class="px-4 py-8 text-center text-slate-500">No spare parts found.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="canWriteInventory && editingId" class="card p-6">
        <h3 class="text-lg font-semibold text-slate-900 mb-4">Edit Spare Part</h3>
        <form class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3" @submit.prevent="saveEdit(editingId)">
          <input v-model="editForm.name" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Part name" required />
          <input v-model="editForm.part_number" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Item code (optional)" />
          <input v-model="editForm.category" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Category" />
          <input v-model.number="editForm.stock_quantity" type="number" min="0" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Stock" required />
          <input v-model="editForm.stock_note" class="px-4 py-2 border border-slate-300 rounded-lg" :placeholder="stockQuantityChanging ? 'Reason required for stock change' : 'Reason if changing stock quantity'" :required="stockQuantityChanging" />
          <input v-model.number="editForm.reorder_level" type="number" min="0" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Reorder level" required />
          <label class="inline-flex w-fit items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-4 py-2 text-sm text-slate-700">
            <input v-model="editForm.is_active" type="checkbox" />
            Active
          </label>
          <div class="flex gap-3">
            <button type="submit" class="flex-1 bg-slate-900 hover:bg-slate-800 text-white font-semibold py-2 rounded-lg">Save Changes</button>
            <button type="button" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2 rounded-lg" @click="cancelEdit">Cancel</button>
          </div>
        </form>
      </div>

      <div v-if="canWriteInventory && adjustModal.open" class="fixed inset-0 bg-black/40 z-40 flex items-center justify-center px-4">
        <div class="bg-white w-full max-w-md rounded-xl p-6">
          <h3 class="text-lg font-semibold text-slate-900 mb-4">Adjust Stock: {{ adjustModal.partName }}</h3>
          <form class="space-y-3" @submit.prevent="submitAdjust">
            <select v-model="adjustModal.action" class="w-full px-4 py-2 border border-slate-300 rounded-lg">
              <option value="in">Add Stock</option>
              <option value="out">Deduct Stock</option>
              <option value="set">Set Exact Stock</option>
            </select>
            <input v-model.number="adjustModal.quantity" type="number" :min="adjustModal.action === 'set' ? 0 : 1" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Quantity" required />
            <input v-model="adjustModal.note" class="w-full px-4 py-2 border border-slate-300 rounded-lg" :placeholder="adjustModal.action === 'in' ? 'Note (optional)' : 'Reason (required)'" :required="adjustModal.action !== 'in'" />
            <div class="flex gap-3 pt-2">
              <button type="submit" class="flex-1 bg-slate-900 hover:bg-slate-800 text-white font-semibold py-2 rounded-lg">Apply</button>
              <button type="button" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2 rounded-lg" @click="adjustModal.open = false">Cancel</button>
            </div>
          </form>
        </div>
      </div>

      <div v-if="canWriteInventory && orderModal.open" class="fixed inset-0 bg-black/40 z-40 flex items-center justify-center px-4">
        <div class="bg-white w-full max-w-md rounded-xl p-6">
          <h3 class="text-lg font-semibold text-slate-900">Add to For Order</h3>
          <p class="mt-1 text-sm text-slate-600">{{ orderModal.partName }}</p>
          <form class="mt-4 space-y-4" @submit.prevent="submitForOrder">
            <label class="block text-sm font-semibold text-slate-700">Quantity to order
              <input v-model.number="orderModal.quantity" type="number" min="1" max="1000000" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required />
            </label>
            <div class="flex gap-3">
              <button type="submit" class="btn-primary flex-1 py-2">Add to For Order</button>
              <button type="button" class="flex-1 rounded-lg bg-slate-100 py-2 font-semibold text-slate-700" @click="orderModal.open = false">Cancel</button>
            </div>
          </form>
        </div>
      </div>

    </div>
  </AdminLayout>
</template>
