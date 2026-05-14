<script setup>
import { onMounted, ref } from 'vue';
import TechnicianLayout from '../../layouts/TechnicianLayout.vue';
import AppLink from '../../components/AppLink.vue';
import { ensureRoles } from '../../composables/auth';
import { navigate } from '../../navigation';

const loading = ref(true);
const forbidden = ref(false);
const errorMessage = ref('');
const technician = ref(null);
const jobs = ref([]);
const allowedStatuses = ref([]);
const statusDrafts = ref({});
const notesDrafts = ref({});

const loadDashboard = async () => {
    const auth = await ensureRoles(['technician']);
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
        const { data } = await window.axios.get('/api/technician/dashboard');
        technician.value = data.technician;
        jobs.value = data.jobs || [];
        allowedStatuses.value = data.allowedStatuses || [];
        statusDrafts.value = Object.fromEntries(jobs.value.map((job) => [job.id, job.current_status]));
        notesDrafts.value = Object.fromEntries(jobs.value.map((job) => [job.id, '']));
    } catch (error) {
        forbidden.value = true;
        errorMessage.value = error?.response?.data?.message || 'Unable to load technician workspace.';
    } finally {
        loading.value = false;
    }
};

const updateStatus = async (job) => {
    errorMessage.value = '';

    try {
        await window.axios.post(`/api/technician/job-orders/${job.id}/update-status`, {
            status: statusDrafts.value[job.id],
            note: notesDrafts.value[job.id] || null,
        });
        await loadDashboard();
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Unable to update job status.';
    }
};

onMounted(loadDashboard);
</script>

<template>
  <TechnicianLayout title="My Jobs">
    <div v-if="loading" class="card p-6 text-slate-600">Loading technician workspace...</div>
    <div v-else-if="forbidden" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">{{ errorMessage || 'You do not have access to this page.' }}</div>

    <div v-else class="space-y-6">
      <div v-if="errorMessage" class="p-3 rounded border border-rose-200 bg-rose-50 text-rose-700">{{ errorMessage }}</div>

      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="metric-card">
          <p class="text-slate-500 text-sm">Technician</p>
          <p class="text-2xl font-bold text-slate-900">{{ technician.name }}</p>
        </div>
        <div class="metric-card">
          <p class="text-slate-500 text-sm">Current Jobs</p>
          <p class="text-3xl font-bold text-slate-900">{{ technician.currentJobsCount }}</p>
        </div>
        <div class="metric-card">
          <p class="text-slate-500 text-sm">Completed Jobs</p>
          <p class="text-3xl font-bold text-slate-900">{{ technician.completedJobsCount }}</p>
        </div>
        <div class="metric-card">
          <p class="text-slate-500 text-sm">Average Rating</p>
          <p class="text-3xl font-bold text-slate-900">{{ technician.averageRating || 'N/A' }}</p>
        </div>
      </div>

      <div class="card overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-slate-100 border-b">
            <tr>
              <th class="px-4 py-3 text-left text-slate-600">Job Order</th>
              <th class="px-4 py-3 text-left text-slate-600">Device</th>
              <th class="px-4 py-3 text-left text-slate-600">Status</th>
              <th class="px-4 py-3 text-left text-slate-600">Due</th>
              <th class="px-4 py-3 text-left text-slate-600">Update</th>
              <th class="px-4 py-3 text-left text-slate-600">Details</th>
            </tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="job in jobs" :key="job.id">
              <td class="px-4 py-3">
                <AppLink :to="`/technician/job-orders/${job.id}`" class="font-semibold text-slate-900">{{ job.job_order_number }}</AppLink>
                <p class="text-xs text-slate-500">{{ job.customer_name }}</p>
              </td>
              <td class="px-4 py-3 text-slate-700">
                <p>{{ [job.brand, job.model].filter(Boolean).join(' ') || job.device_type }}</p>
                <p class="text-xs text-slate-500">{{ job.device_type }}</p>
              </td>
              <td class="px-4 py-3">
                <p class="text-slate-700">{{ job.current_status.replaceAll('_', ' ') }}</p>
                <p v-if="job.is_overdue" class="text-xs font-semibold text-rose-600">Overdue</p>
              </td>
              <td class="px-4 py-3 text-slate-700">{{ job.estimated_completion_formatted || 'Not set' }}</td>
              <td class="px-4 py-3">
                <form class="space-y-2" @submit.prevent="updateStatus(job)">
                  <select v-model="statusDrafts[job.id]" class="w-full px-3 py-2 border border-slate-300 rounded-lg">
                    <option v-for="status in allowedStatuses" :key="status" :value="status">{{ status.replaceAll('_', ' ') }}</option>
                  </select>
                  <input v-model="notesDrafts[job.id]" class="w-full px-3 py-2 border border-slate-300 rounded-lg" placeholder="Note (optional)" />
                  <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white font-semibold py-2 rounded-lg">Save</button>
                </form>
              </td>
              <td class="px-4 py-3 align-top">
                <AppLink
                  :to="`/technician/job-orders/${job.id}`"
                  class="inline-flex items-center justify-center rounded-lg border border-slate-300 px-4 py-2 font-semibold text-slate-900 hover:bg-slate-50"
                >
                  Open Job
                </AppLink>
                <p class="mt-2 text-xs text-slate-500">View parts, request missing items, and manage repair details.</p>
              </td>
            </tr>
            <tr v-if="!jobs.length">
              <td colspan="6" class="px-4 py-8 text-center text-slate-500">No jobs are assigned to your technician account.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </TechnicianLayout>
</template>
