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
const isAdmin = computed(() => adminRoles.includes(authState.user?.role));
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
    unit: 'pcs',
    stock_quantity: 0,
    reorder_level: 5,
    unit_cost: 0,
    description: '',
    is_active: true,
});

const editingId = ref(null);
const editForm = reactive({
    name: '',
    part_number: '',
    category: '',
    unit: 'pcs',
    stock_quantity: 0,
    reorder_level: 5,
    unit_cost: 0,
    description: '',
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

const activeFilters = computed(() => ({
    search: filters.search || undefined,
    active_only: filters.active_only ? 1 : 0,
    low_stock: filters.low_stock ? 1 : 0,
}));

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
            description: createForm.description || null,
        };
        const { data } = await window.axios.post('/api/admin/inventory', payload);
        successMessage.value = data.message || 'Spare part created.';
        Object.assign(createForm, {
            name: '',
            part_number: '',
            category: '',
            unit: 'pcs',
            stock_quantity: 0,
            reorder_level: 5,
            unit_cost: 0,
            description: '',
            is_active: true,
        });
        await loadInventory();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to create spare part.';
    }
};

const startEdit = (item) => {
    editingId.value = item.id;
    Object.assign(editForm, {
        name: item.name,
        part_number: item.part_number || '',
        category: item.category || '',
        unit: item.unit || 'pcs',
        stock_quantity: item.stock_quantity,
        reorder_level: item.reorder_level,
        unit_cost: Number(item.unit_cost || 0),
        description: item.description || '',
        is_active: item.is_active,
    });
};

const cancelEdit = () => {
    editingId.value = null;
};

const saveEdit = async (partId) => {
    errorMessage.value = '';
    successMessage.value = '';
    try {
        const payload = {
            ...editForm,
            part_number: editForm.part_number || null,
            category: editForm.category || null,
            description: editForm.description || null,
        };
        const { data } = await window.axios.put(`/api/admin/inventory/${partId}`, payload);
        successMessage.value = data.message || 'Spare part updated.';
        editingId.value = null;
        await loadInventory();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to update spare part.';
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

const formatCurrency = (value) => {
    const amount = Number(value || 0);
    return `PHP ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

onMounted(loadInventory);
</script>

<template>
  <AdminLayout title="Inventory">
    <div v-if="loading" class="card p-6 text-slate-600">Loading inventory...</div>
    <div v-else-if="forbidden" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">You do not have access to this page.</div>

    <div v-else class="space-y-6">
      <div v-if="successMessage" class="card p-4 border border-emerald-200 bg-emerald-50 text-emerald-900">{{ successMessage }}</div>
      <div v-if="errorMessage" class="card p-4 border border-rose-200 bg-rose-50 text-rose-900">{{ errorMessage }}</div>

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

      <div v-if="canWriteInventory" class="card p-6">
        <h3 class="text-lg font-semibold text-slate-900 mb-4">Add Spare Part</h3>
        <form class="grid grid-cols-1 md:grid-cols-4 gap-3" @submit.prevent="createPart">
          <input v-model="createForm.name" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Part name" required />
          <input v-model="createForm.part_number" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Part number (optional)" />
          <input v-model="createForm.category" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Category" />
          <input v-model="createForm.unit" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Unit (pcs)" />
          <input v-model.number="createForm.stock_quantity" type="number" min="0" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Initial stock" required />
          <input v-model.number="createForm.reorder_level" type="number" min="0" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Reorder level" required />
          <input v-model.number="createForm.unit_cost" type="number" min="0" step="0.01" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Unit cost" required />
          <label class="flex items-center gap-2 text-sm text-slate-700 px-2">
            <input v-model="createForm.is_active" type="checkbox" />
            Active
          </label>
          <input v-model="createForm.description" class="md:col-span-3 px-4 py-2 border border-slate-300 rounded-lg" placeholder="Description (optional)" />
          <button type="submit" class="md:col-span-1 bg-slate-900 hover:bg-slate-800 text-white font-semibold py-2 rounded-lg">Save Part</button>
        </form>
      </div>

      <div class="card p-6">
        <div class="flex flex-wrap items-center gap-3 mb-4">
          <input v-model="filters.search" class="flex-1 min-w-[200px] px-4 py-2 border border-slate-300 rounded-lg" placeholder="Search part name, number, category" />
          <label class="flex items-center gap-2 text-sm text-slate-700">
            <input v-model="filters.active_only" type="checkbox" />
            Active only
          </label>
          <label class="flex items-center gap-2 text-sm text-slate-700">
            <input v-model="filters.low_stock" type="checkbox" />
            Low stock only
          </label>
          <button type="button" class="bg-slate-900 hover:bg-slate-800 text-white font-semibold px-4 py-2 rounded-lg" @click="loadInventory">Apply</button>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-slate-100 border-b">
              <tr>
                <th class="px-4 py-3 text-left text-slate-600">Part</th>
                <th class="px-4 py-3 text-left text-slate-600">Stock</th>
                <th class="px-4 py-3 text-left text-slate-600">Cost</th>
                <th class="px-4 py-3 text-left text-slate-600">Status</th>
                <th class="px-4 py-3 text-left text-slate-600">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y">
              <tr v-for="item in inventory.data" :key="item.id">
                <td class="px-4 py-3">
                  <p class="font-semibold text-slate-900">{{ item.name }}</p>
                  <p class="text-xs text-slate-500">{{ item.part_number || 'No part number' }} - {{ item.category || 'No category' }}</p>
                </td>
                <td class="px-4 py-3">
                  <p class="font-semibold" :class="item.is_low_stock ? 'text-rose-700' : 'text-slate-900'">
                    {{ item.stock_quantity }} {{ item.unit }}
                  </p>
                  <p class="text-xs text-slate-500">Reorder at {{ item.reorder_level }}</p>
                </td>
                <td class="px-4 py-3 text-slate-700">{{ formatCurrency(item.unit_cost) }}</td>
                <td class="px-4 py-3">
                  <span class="badge" :class="item.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700'">
                    {{ item.is_active ? 'Active' : 'Inactive' }}
                  </span>
                </td>
                <td class="px-4 py-3">
                  <div v-if="isAdmin" class="flex flex-wrap items-center gap-2">
                    <button type="button" class="text-slate-900 font-semibold" @click="startEdit(item)">Edit</button>
                    <button type="button" class="text-emerald-700 font-semibold" @click="openAdjust(item, 'in')">+Stock</button>
                    <button type="button" class="text-amber-700 font-semibold" @click="openAdjust(item, 'out')">-Stock</button>
                    <button type="button" class="text-blue-700 font-semibold" @click="openAdjust(item, 'set')">Set</button>
                    <button type="button" class="text-rose-600 font-semibold" @click="deletePart(item.id)">Delete</button>
                  </div>
                  <div v-else-if="canWriteInventory" class="flex flex-wrap items-center gap-2">
                    <button type="button" class="text-emerald-700 font-semibold" @click="openAdjust(item, 'in')">+Stock</button>
                  </div>
                  <span v-else class="text-sm text-slate-500">Read-only</span>
                </td>
              </tr>
              <tr v-if="!inventory.data?.length">
                <td colspan="5" class="px-4 py-8 text-center text-slate-500">No spare parts found.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="isAdmin && editingId" class="card p-6">
        <h3 class="text-lg font-semibold text-slate-900 mb-4">Edit Spare Part</h3>
        <form class="grid grid-cols-1 md:grid-cols-4 gap-3" @submit.prevent="saveEdit(editingId)">
          <input v-model="editForm.name" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Part name" required />
          <input v-model="editForm.part_number" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Part number" />
          <input v-model="editForm.category" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Category" />
          <input v-model="editForm.unit" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Unit" />
          <input v-model.number="editForm.stock_quantity" type="number" min="0" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Stock" required />
          <input v-model.number="editForm.reorder_level" type="number" min="0" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Reorder level" required />
          <input v-model.number="editForm.unit_cost" type="number" min="0" step="0.01" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Unit cost" required />
          <label class="flex items-center gap-2 text-sm text-slate-700 px-2">
            <input v-model="editForm.is_active" type="checkbox" />
            Active
          </label>
          <input v-model="editForm.description" class="md:col-span-4 px-4 py-2 border border-slate-300 rounded-lg" placeholder="Description" />
          <div class="md:col-span-4 flex gap-3">
            <button type="submit" class="flex-1 bg-slate-900 hover:bg-slate-800 text-white font-semibold py-2 rounded-lg">Save Changes</button>
            <button type="button" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2 rounded-lg" @click="cancelEdit">Cancel</button>
          </div>
        </form>
      </div>

      <div v-if="canWriteInventory && adjustModal.open" class="fixed inset-0 bg-black/40 z-40 flex items-center justify-center px-4">
        <div class="bg-white w-full max-w-md rounded-xl p-6">
          <h3 class="text-lg font-semibold text-slate-900 mb-4">Adjust Stock: {{ adjustModal.partName }}</h3>
          <form class="space-y-3" @submit.prevent="submitAdjust">
            <select v-if="isAdmin" v-model="adjustModal.action" class="w-full px-4 py-2 border border-slate-300 rounded-lg">
              <option value="in">Add Stock</option>
              <option value="out">Deduct Stock</option>
              <option value="set">Set Exact Stock</option>
            </select>
            <div v-else class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-800">
              Staff can add stock only.
            </div>
            <input v-model.number="adjustModal.quantity" type="number" min="0" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Quantity" required />
            <input v-model="adjustModal.note" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Note (optional)" />
            <div class="flex gap-3 pt-2">
              <button type="submit" class="flex-1 bg-slate-900 hover:bg-slate-800 text-white font-semibold py-2 rounded-lg">Apply</button>
              <button type="button" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2 rounded-lg" @click="adjustModal.open = false">Cancel</button>
            </div>
          </form>
        </div>
      </div>

      <div v-if="!isAdmin" class="card border border-blue-200 bg-blue-50 p-4 text-blue-900">
        Staff can add new inventory items and restock existing ones here. Editing item details, deducting stock, setting exact stock, and deleting inventory remain admin-only.
      </div>
    </div>
  </AdminLayout>
</template>
