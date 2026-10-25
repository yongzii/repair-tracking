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
const requestError = ref('');
const requestSuccess = ref('');
const submittingRequest = ref(false);
const requestIdempotencyKey = ref(null);
const requestForm = ref({
    part_name: '',
    quantity: 1,
    note: '',
});

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

const submitPartRequest = async () => {
    requestError.value = '';
    requestSuccess.value = '';
    submittingRequest.value = true;
    if (!requestIdempotencyKey.value) {
        requestIdempotencyKey.value = window.crypto.randomUUID();
    }

    try {
        const { data } = await window.axios.post('/api/technician/part-requests', {
            part_name: requestForm.value.part_name,
            quantity: requestForm.value.quantity,
            note: requestForm.value.note || null,
            idempotency_key: requestIdempotencyKey.value,
        });
        requestSuccess.value = data.message || 'Internal purchasing request submitted.';
        requestForm.value = { part_name: '', quantity: 1, note: '' };
        requestIdempotencyKey.value = null;
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        requestError.value = firstError || error?.response?.data?.message || 'Unable to submit part request.';
    } finally {
        submittingRequest.value = false;
    }
};

const formatStatus = (status) => {
    if (status === 'PICKED_UP') return 'Released';
    if (status === 'RTO_RELEASED') return 'RTO Released';
    if (status === 'RTO' || status === 'UNREPAIRABLE') return 'RTO';
    return status ? status.replaceAll('_', ' ') : '';
};

onMounted(loadDashboard);
</script>

<template>
  <TechnicianLayout title="My Jobs">
    <div v-if="loading" class="card p-6 text-slate-600">Loading technician workspace...</div>
    <div v-else-if="forbidden" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">{{ errorMessage || 'You do not have access to this page.' }}</div>

    <div v-else class="space-y-6">
      <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-teal-700">Technician workspace</p>
        <h3 class="mt-2 font-display text-3xl text-slate-950">My Assigned Jobs</h3>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Update repair progress, submit internal purchasing requests, and open job details for repair work.</p>
      </section>

      <div v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">{{ errorMessage }}</div>

      <section class="card p-5 sm:p-6">
        <h4 class="text-lg font-bold text-slate-950">Internal Purchase / Restock Request</h4>
        <p class="mt-1 text-sm text-slate-600">Request parts for shop inventory or future repairs. This does not require a Job Order and never changes repair status.</p>
        <div v-if="requestSuccess" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm font-semibold text-emerald-700">{{ requestSuccess }}</div>
        <div v-if="requestError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm font-semibold text-rose-700">{{ requestError }}</div>
        <form class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="submitPartRequest">
          <input v-model="requestForm.part_name" class="rounded-lg border border-slate-300 px-4 py-3" placeholder="Part name" required />
          <input v-model.number="requestForm.quantity" type="number" min="1" class="rounded-lg border border-slate-300 px-4 py-3" placeholder="Quantity" required />
          <input v-model="requestForm.note" class="rounded-lg border border-slate-300 px-4 py-3 lg:col-span-2" placeholder="Restock / future need note (optional)" />
          <button type="submit" class="btn-primary px-4 py-3 sm:col-span-2 lg:col-span-4" :disabled="submittingRequest">
            {{ submittingRequest ? 'Submitting...' : 'Submit Purchase Request' }}
          </button>
        </form>
      </section>

      <div class="grid grid-cols-2 gap-3 xl:grid-cols-4 sm:gap-4">
        <div class="metric-card">
          <p class="text-slate-500 text-sm">Technician</p>
          <p class="mt-2 break-words text-lg font-bold text-slate-900 sm:text-2xl">{{ technician.name }}</p>
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

      <div class="admin-panel overflow-hidden">
        <div class="divide-y divide-slate-100 md:hidden">
          <article v-for="job in jobs" :key="`mobile-${job.id}`" class="space-y-3 p-4">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <AppLink :to="`/technician/job-orders/${job.id}`" class="font-bold text-slate-950">{{ job.job_order_number }}</AppLink>
                <p class="mt-1 truncate text-sm text-slate-600">{{ job.customer_name }}</p>
                <p class="truncate text-xs text-slate-500">{{ [job.brand, job.model].filter(Boolean).join(' ') || job.device_type }}</p>
              </div>
              <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-bold ring-1" :class="job.is_overdue ? 'bg-rose-50 text-rose-700 ring-rose-200' : 'bg-teal-50 text-teal-700 ring-teal-200'">{{ formatStatus(job.current_status) }}</span>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
              <span>Due {{ job.estimated_completion_formatted || 'Not set' }}</span>
              <span v-if="job.is_overdue" class="font-bold text-rose-600">Overdue</span>
            </div>
            <form class="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_1fr_auto]" @submit.prevent="updateStatus(job)">
              <select v-model="statusDrafts[job.id]" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                <option v-for="status in allowedStatuses" :key="status" :value="status">{{ formatStatus(status) }}</option>
              </select>
              <input v-model="notesDrafts[job.id]" class="w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="Note (optional)" />
              <button type="submit" class="btn-primary px-4 py-2">Save</button>
            </form>
            <AppLink :to="`/technician/job-orders/${job.id}`" class="btn-soft inline-flex w-full items-center justify-center px-4 py-2 text-sm">Open Job Details</AppLink>
          </article>
          <p v-if="!jobs.length" class="p-8 text-center text-sm text-slate-500">No jobs are assigned to your technician account.</p>
        </div>
        <div class="hidden overflow-x-auto md:block">
        <table class="data-table">
          <thead>
            <tr>
              <th class="px-4 py-3 text-left text-slate-600">Job Order</th>
              <th class="px-4 py-3 text-left text-slate-600">Device</th>
              <th class="px-4 py-3 text-left text-slate-600">Status</th>
              <th class="px-4 py-3 text-left text-slate-600">Due</th>
              <th class="px-4 py-3 text-left text-slate-600">Update</th>
              <th class="px-4 py-3 text-left text-slate-600">Details</th>
            </tr>
          </thead>
          <tbody>
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
                <span class="rounded-full px-3 py-1 text-xs font-bold ring-1" :class="job.is_overdue ? 'bg-rose-50 text-rose-700 ring-rose-200' : 'bg-teal-50 text-teal-700 ring-teal-200'">{{ formatStatus(job.current_status) }}</span>
                <p v-if="job.is_overdue" class="mt-2 text-xs font-semibold text-rose-600">Overdue</p>
              </td>
              <td class="px-4 py-3 text-slate-700">{{ job.estimated_completion_formatted || 'Not set' }}</td>
              <td class="px-4 py-3">
                <form class="space-y-2" @submit.prevent="updateStatus(job)">
                  <select v-model="statusDrafts[job.id]" class="w-full border border-slate-300 px-3 py-2">
                    <option v-for="status in allowedStatuses" :key="status" :value="status">{{ formatStatus(status) }}</option>
                  </select>
                  <input v-model="notesDrafts[job.id]" class="w-full border border-slate-300 px-3 py-2" placeholder="Note (optional)" />
                  <button type="submit" class="btn-primary w-full py-2">Save</button>
                </form>
              </td>
              <td class="px-4 py-3 align-top">
                <AppLink
                  :to="`/technician/job-orders/${job.id}`"
                  class="btn-soft inline-flex items-center justify-center px-4 py-2"
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
    </div>
  </TechnicianLayout>
</template>
