<script setup>
import { computed, onMounted, ref } from 'vue';
import PlatformLayout from '../../layouts/PlatformLayout.vue';
import { ensurePlatformAdmin } from '../../composables/auth';
import { navigate } from '../../navigation';

const loading = ref(true);
const forbidden = ref(false);
const failedJobs = ref(null);
const actionSuccess = ref('');
const actionError = ref('');
const retryingJobId = ref(null);
const deletingJobId = ref(null);
const actionInProgress = computed(() => retryingJobId.value !== null || deletingJobId.value !== null);

const loadFailedJobs = async () => {
    const auth = await ensurePlatformAdmin();
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
        const { data } = await window.axios.get('/api/platform/failed-jobs');
        failedJobs.value = data.failedJobs;
    } catch {
        forbidden.value = true;
    } finally {
        loading.value = false;
    }
};

const retryJob = async (id) => {
    if (actionInProgress.value) return;

    actionSuccess.value = '';
    actionError.value = '';
    retryingJobId.value = id;

    try {
        await window.axios.post(`/api/platform/failed-jobs/${id}/retry`);
        actionSuccess.value = `Failed job #${id} was queued for retry.`;
        await loadFailedJobs();
    } catch (error) {
        actionError.value = error?.response?.status === 403
            ? 'You do not have access to this action.'
            : 'Unable to retry this failed job. Please try again.';
    } finally {
        retryingJobId.value = null;
    }
};

const deleteJob = async (id) => {
    if (actionInProgress.value) return;
    if (!window.confirm('Delete this failed job record?')) return;

    actionSuccess.value = '';
    actionError.value = '';
    deletingJobId.value = id;

    try {
        await window.axios.delete(`/api/platform/failed-jobs/${id}`);
        actionSuccess.value = `Failed job #${id} was deleted.`;
        await loadFailedJobs();
    } catch (error) {
        actionError.value = error?.response?.status === 403
            ? 'You do not have access to this action.'
            : 'Unable to delete this failed job. Please try again.';
    } finally {
        deletingJobId.value = null;
    }
};

onMounted(loadFailedJobs);
</script>

<template>
  <PlatformLayout title="Failed Jobs">
    <div v-if="loading" class="card p-6 text-slate-600">Loading failed jobs...</div>
    <div v-else-if="forbidden" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">You do not have access to this page.</div>

    <div v-else class="admin-panel overflow-hidden">
      <div class="border-b border-slate-200 p-6">
        <p class="text-xs font-bold uppercase text-rose-600">Queue monitor</p>
        <h3 class="mt-2 text-xl font-bold text-slate-950">Failed Jobs</h3>
        <p class="mt-1 text-sm text-slate-500">Review failed queue records and retry or remove them.</p>
        <div v-if="actionSuccess" role="status" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ actionSuccess }}</div>
        <div v-if="actionError" role="alert" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">{{ actionError }}</div>
      </div>
      <div class="overflow-x-auto">
      <table class="data-table">
        <thead>
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
            <td class="px-4 py-3">
              <div class="flex gap-2">
                <button type="button" class="rounded-lg border border-emerald-200 px-3 py-1.5 text-xs font-bold text-emerald-700 hover:bg-emerald-50 disabled:cursor-not-allowed disabled:opacity-60" :disabled="actionInProgress" @click="retryJob(job.id)">{{ retryingJobId === job.id ? 'Retrying...' : 'Retry' }}</button>
                <button type="button" class="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-bold text-rose-600 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60" :disabled="actionInProgress" @click="deleteJob(job.id)">{{ deletingJobId === job.id ? 'Deleting...' : 'Delete' }}</button>
              </div>
            </td>
          </tr>
          <tr v-if="!failedJobs.data.length">
            <td colspan="4" class="px-4 py-8 text-center text-slate-500">No failed jobs found.</td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>
  </PlatformLayout>
</template>
