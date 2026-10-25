<script setup>
import { computed, onMounted, ref } from 'vue';
import PlatformLayout from '../../layouts/PlatformLayout.vue';
import { ensurePlatformAdmin } from '../../composables/auth';
import { navigate } from '../../navigation';

const loading = ref(true);
const forbidden = ref(false);
const errorMessage = ref('');
const failedJobs = ref(null);
const actionSuccess = ref('');
const actionError = ref('');
const retryingJobId = ref(null);
const deletingJobId = ref(null);
const actionInProgress = computed(() => retryingJobId.value !== null || deletingJobId.value !== null);

const loadFailedJobs = async (page = 1) => {
    loading.value = true;
    errorMessage.value = '';
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
        const { data } = await window.axios.get('/api/platform/failed-jobs', { params: { page } });
        failedJobs.value = data.failedJobs;
    } catch (error) {
        failedJobs.value = null;
        if (error?.response?.status === 403) {
            forbidden.value = true;
        } else {
            errorMessage.value = error?.response?.data?.message || 'Unable to load failed jobs. Please try again.';
        }
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
    <div v-if="loading" class="space-y-4" role="status" aria-label="Loading failed jobs">
      <div class="animate-pulse rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="h-3 w-24 rounded bg-slate-200"></div>
        <div class="mt-3 h-7 w-48 rounded bg-slate-200"></div>
        <div class="mt-2 h-4 w-full max-w-md rounded bg-slate-100"></div>
      </div>
      <div v-for="item in 3" :key="item" class="animate-pulse rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="h-4 w-32 rounded bg-slate-200"></div>
        <div class="mt-3 h-4 w-48 rounded bg-slate-100"></div>
      </div>
      <span class="sr-only">Loading failed jobs...</span>
    </div>
    <div v-else-if="forbidden" class="platform-card p-5 text-sm font-semibold text-rose-700">You do not have access to this page.</div>

    <div v-else class="space-y-4">
      <section class="platform-page-heading">
        <div>
          <p class="platform-eyebrow">PLATFORM ADMIN</p>
          <h1>Failed Jobs</h1>
          <p>Review failed queue records and retry or remove them.</p>
        </div>
      </section>
      <div v-if="actionSuccess" role="status" class="platform-alert platform-alert-success">{{ actionSuccess }}</div>
      <div v-if="actionError || errorMessage" role="alert" class="platform-alert platform-alert-danger">{{ actionError || errorMessage }}</div>
      <div v-if="errorMessage && !failedJobs" class="platform-card p-5">
        <button type="button" class="platform-button platform-button-secondary" @click="loadFailedJobs">Try again</button>
      </div>

      <section v-if="failedJobs" class="space-y-3 xl:hidden" aria-label="Failed job records">
        <article v-for="job in failedJobs.data" :key="`mobile-${job.id}`" class="platform-card space-y-3 p-4">
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Failed job #{{ job.id }}</p>
              <p class="mt-1 break-all font-semibold text-slate-950">{{ job.queue }}</p>
            </div>
            <span class="inline-flex shrink-0 rounded-full bg-rose-50 px-2.5 py-1 text-xs font-bold text-rose-700 ring-1 ring-rose-200">Failed</span>
          </div>
          <p class="text-sm text-slate-500"><span class="font-semibold text-slate-700">Failed at:</span> {{ job.failed_at }}</p>
          <div class="grid grid-cols-2 gap-2">
            <button type="button" class="min-h-10 rounded-lg border border-teal-200 bg-white px-3 py-2 text-sm font-bold text-teal-700 hover:bg-teal-50 disabled:cursor-not-allowed disabled:opacity-60" :disabled="actionInProgress" @click="retryJob(job.id)">{{ retryingJobId === job.id ? 'Retrying...' : 'Retry' }}</button>
            <button type="button" class="min-h-10 rounded-lg border border-rose-200 bg-white px-3 py-2 text-sm font-bold text-rose-700 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60" :disabled="actionInProgress" @click="deleteJob(job.id)">{{ deletingJobId === job.id ? 'Deleting...' : 'Delete' }}</button>
          </div>
        </article>
        <div v-if="!failedJobs.data.length" class="platform-card p-8 text-center">
          <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-emerald-50 text-emerald-700" aria-hidden="true"><i class="fas fa-check"></i></span>
          <p class="mt-3 font-semibold text-slate-800">No failed jobs found</p>
          <p class="mt-1 text-sm text-slate-500">Queue failures will appear here if they need attention.</p>
        </div>
      </section>

      <section v-if="failedJobs" class="platform-card hidden overflow-hidden xl:block" aria-label="Failed job records">
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
                <td class="px-4 py-3 font-semibold text-slate-900">{{ job.id }}</td>
                <td class="break-all px-4 py-3 text-slate-700">{{ job.queue }}</td>
                <td class="px-4 py-3 text-slate-700">{{ job.failed_at }}</td>
                <td class="px-4 py-3">
                  <div class="flex gap-2">
                    <button type="button" class="min-h-9 rounded-lg border border-teal-200 px-3 py-1.5 text-xs font-bold text-teal-700 hover:bg-teal-50 disabled:cursor-not-allowed disabled:opacity-60" :disabled="actionInProgress" @click="retryJob(job.id)">{{ retryingJobId === job.id ? 'Retrying...' : 'Retry' }}</button>
                    <button type="button" class="min-h-9 rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-bold text-rose-700 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60" :disabled="actionInProgress" @click="deleteJob(job.id)">{{ deletingJobId === job.id ? 'Deleting...' : 'Delete' }}</button>
                  </div>
                </td>
              </tr>
              <tr v-if="!failedJobs.data.length">
                <td colspan="4" class="px-4 py-8 text-center text-slate-500">No failed jobs found.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <footer v-if="failedJobs && failedJobs.last_page > 1" class="platform-pagination">
        <span>Page {{ failedJobs.current_page }} of {{ failedJobs.last_page }} · {{ failedJobs.total }} failed jobs</span>
        <div class="flex gap-2">
          <button type="button" class="btn-soft px-3 py-2 text-sm" :disabled="failedJobs.current_page <= 1 || actionInProgress" @click="loadFailedJobs(failedJobs.current_page - 1)">Previous</button>
          <button type="button" class="btn-soft px-3 py-2 text-sm" :disabled="failedJobs.current_page >= failedJobs.last_page || actionInProgress" @click="loadFailedJobs(failedJobs.current_page + 1)">Next</button>
        </div>
      </footer>
    </div>
  </PlatformLayout>
</template>
