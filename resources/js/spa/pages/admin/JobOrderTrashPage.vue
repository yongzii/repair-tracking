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

    <div v-else class="admin-panel overflow-hidden">
      <div class="flex items-center justify-between gap-4 border-b border-slate-200 p-6">
        <div>
          <p class="text-xs font-bold uppercase text-rose-600">Archive</p>
          <h3 class="mt-2 text-xl font-bold text-slate-950">Trash</h3>
          <p class="mt-1 text-sm text-slate-500">Restore or permanently delete removed job orders.</p>
        </div>
        <AppLink to="/admin/job-orders" class="btn-soft px-4 py-2">Back</AppLink>
      </div>

      <div class="overflow-x-auto">
      <table class="data-table">
        <thead>
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
            <td class="px-4 py-3">
              <div class="flex gap-2">
                <button type="button" class="rounded-lg border border-emerald-200 px-3 py-1.5 text-xs font-bold text-emerald-700 hover:bg-emerald-50" @click="restore(job.id)">Restore</button>
                <button type="button" class="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-bold text-rose-600 hover:bg-rose-50" @click="forceDelete(job.id)">Delete Forever</button>
              </div>
            </td>
          </tr>
          <tr v-if="!jobOrders.data.length">
            <td colspan="3" class="px-4 py-8 text-center text-slate-500">Trash is empty.</td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>
  </AdminLayout>
</template>
