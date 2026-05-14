<script setup>
import { onMounted, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import { ensureAdmin } from '../../composables/auth';
import { navigate } from '../../navigation';

const loading = ref(true);
const forbidden = ref(false);
const failedJobs = ref(null);

const loadFailedJobs = async () => {
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
        const { data } = await window.axios.get('/api/admin/failed-jobs');
        failedJobs.value = data.failedJobs;
    } catch {
        forbidden.value = true;
    } finally {
        loading.value = false;
    }
};

const retryJob = async (id) => {
    await window.axios.post(`/api/admin/failed-jobs/${id}/retry`);
    await loadFailedJobs();
};

const deleteJob = async (id) => {
    if (!window.confirm('Delete this failed job record?')) return;
    await window.axios.delete(`/api/admin/failed-jobs/${id}`);
    await loadFailedJobs();
};

onMounted(loadFailedJobs);
</script>

<template>
  <AdminLayout title="Failed Jobs">
    <div v-if="loading" class="card p-6 text-slate-600">Loading failed jobs...</div>
    <div v-else-if="forbidden" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">You do not have access to this page.</div>

    <div v-else class="card p-6">
      <table class="w-full text-sm">
        <thead class="bg-slate-100">
          <tr>
            <th class="px-4 py-3 text-left text-slate-600">ID</th>
            <th class="px-4 py-3 text-left text-slate-600">Queue</th>
            <th class="px-4 py-3 text-left text-slate-600">Failed At</th>
            <th class="px-4 py-3 text-left text-slate-600">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="job in failedJobs.data" :key="job.id">
            <td class="px-4 py-3 text-slate-900 font-semibold">{{ job.id }}</td>
            <td class="px-4 py-3 text-slate-700">{{ job.queue }}</td>
            <td class="px-4 py-3 text-slate-700">{{ job.failed_at }}</td>
            <td class="px-4 py-3 flex gap-3">
              <button type="button" class="text-emerald-700 font-semibold" @click="retryJob(job.id)">Retry</button>
              <button type="button" class="text-rose-600 font-semibold" @click="deleteJob(job.id)">Delete</button>
            </td>
          </tr>
          <tr v-if="!failedJobs.data.length">
            <td colspan="4" class="px-4 py-8 text-center text-slate-500">No failed jobs found.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </AdminLayout>
</template>
