<script setup>
import { onMounted, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AppLink from '../../components/AppLink.vue';
import { ensureAdmin } from '../../composables/auth';
import { navigate } from '../../navigation';

const loading = ref(true);
const forbidden = ref(false);
const jobOrders = ref(null);

const loadTrash = async () => {
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
        const { data } = await window.axios.get('/api/admin/job-orders/trash');
        jobOrders.value = data.jobOrders;
    } catch {
        forbidden.value = true;
    } finally {
        loading.value = false;
    }
};

const restore = async (id) => {
    await window.axios.post(`/api/admin/job-orders/${id}/restore`);
    await loadTrash();
};

const forceDelete = async (id) => {
    if (!window.confirm('Permanently delete this job order?')) return;
    await window.axios.delete(`/api/admin/job-orders/${id}/force-delete`);
    await loadTrash();
};

onMounted(loadTrash);
</script>

<template>
  <AdminLayout title="Trashed Job Orders">
    <div v-if="loading" class="card p-6 text-slate-600">Loading trashed records...</div>
    <div v-else-if="forbidden" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">You do not have access to this page.</div>

    <div v-else class="card p-6">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold text-slate-900">Trash</h3>
        <AppLink to="/admin/job-orders" class="text-slate-700 hover:text-slate-900 font-semibold">Back</AppLink>
      </div>

      <table class="w-full text-sm">
        <thead class="bg-slate-100">
          <tr>
            <th class="px-4 py-3 text-left text-slate-600">Job Order</th>
            <th class="px-4 py-3 text-left text-slate-600">Customer</th>
            <th class="px-4 py-3 text-left text-slate-600">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="job in jobOrders.data" :key="job.id">
            <td class="px-4 py-3 font-semibold text-slate-900">{{ job.job_order_number }}</td>
            <td class="px-4 py-3 text-slate-700">{{ job.customer_name }}</td>
            <td class="px-4 py-3 flex gap-3">
              <button type="button" class="text-emerald-700 font-semibold" @click="restore(job.id)">Restore</button>
              <button type="button" class="text-rose-600 font-semibold" @click="forceDelete(job.id)">Delete Forever</button>
            </td>
          </tr>
          <tr v-if="!jobOrders.data.length">
            <td colspan="3" class="px-4 py-8 text-center text-slate-500">Trash is empty.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </AdminLayout>
</template>
