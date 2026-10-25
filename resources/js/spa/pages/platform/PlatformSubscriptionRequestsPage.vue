<script setup>
import { computed, onMounted, ref } from 'vue';
import PlatformLayout from '../../layouts/PlatformLayout.vue';
import { ensurePlatformAdmin } from '../../composables/auth';
import { navigate } from '../../navigation';

const loading = ref(true);
const forbidden = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const requests = ref(null);
const status = ref('pending');
const busyRequestId = ref(null);
const notes = ref({});

const tabs = [
    { value: 'pending', label: 'Pending' },
    { value: 'approved', label: 'Approved' },
    { value: 'rejected', label: 'Rejected' },
];

const pageCountLabel = computed(() => {
    const total = requests.value?.total ?? 0;
    return `${total} request${total === 1 ? '' : 's'}`;
});

const formatCurrency = (value) => {
    const amount = Number(value || 0);
    return `PHP ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

const loadRequests = async (page = 1) => {
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

    loading.value = true;
    errorMessage.value = '';
    try {
        const { data } = await window.axios.get('/api/platform/subscription-requests', {
            params: { status: status.value, per_page: 15, page },
        });
        requests.value = data.subscription_requests;
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Unable to load subscription requests.';
    } finally {
        loading.value = false;
    }
};

const changeTab = async (nextStatus) => {
    if (status.value === nextStatus) return;
    status.value = nextStatus;
    successMessage.value = '';
    requests.value = null;
    await loadRequests(1);
};

const reviewRequest = async (request, decision) => {
    if (busyRequestId.value !== null || request.status !== 'pending') return;
    const verb = decision === 'approve' ? 'approve' : 'reject';
    if (!window.confirm(`Are you sure you want to ${verb} the subscription request for ${request.shop_name}?`)) return;

    busyRequestId.value = request.id;
    errorMessage.value = '';
    successMessage.value = '';
    try {
        const { data } = await window.axios.post(
            `/api/platform/subscription-requests/${request.id}/${verb}`,
            { admin_notes: notes.value[request.id] || null },
        );
        successMessage.value = data.message || `Subscription request ${decision === 'approve' ? 'approved' : 'rejected'}.`;
        await loadRequests(requests.value?.current_page || 1);
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || `Unable to ${verb} this subscription request.`;
    } finally {
        busyRequestId.value = null;
    }
};

onMounted(() => loadRequests());
</script>

<template>
  <PlatformLayout title="Subscription Requests">
    <section class="platform-page-heading">
      <div>
        <p class="platform-eyebrow">PLATFORM ADMIN</p>
        <h1>Subscription Requests</h1>
        <p>Review submitted payment proofs and manage subscription approvals.</p>
      </div>
      <span v-if="requests" class="platform-count">{{ pageCountLabel }}</span>
    </section>

    <div v-if="successMessage" class="platform-alert platform-alert-success" role="status">{{ successMessage }}</div>
    <div v-if="errorMessage" class="platform-alert platform-alert-danger" role="alert">{{ errorMessage }}</div>

    <section class="platform-card">
      <div class="platform-tabs" role="tablist" aria-label="Filter subscription requests">
        <button
          v-for="tab in tabs"
          :key="tab.value"
          type="button"
          role="tab"
          :aria-selected="status === tab.value"
          :class="status === tab.value ? 'platform-tab-active' : ''"
          @click="changeTab(tab.value)"
        >{{ tab.label }}</button>
      </div>

      <div v-if="loading" class="platform-state" role="status">
        <span class="platform-spinner" aria-hidden="true"></span>
        <span>Loading subscription requests...</span>
      </div>
      <div v-else-if="forbidden" class="platform-state platform-state-danger">Only platform admins can access subscription requests.</div>
      <div v-else-if="errorMessage && !requests" class="platform-state" role="alert">
        <span class="platform-state-icon platform-state-icon-danger" aria-hidden="true"><i class="fas fa-triangle-exclamation"></i></span>
        <strong>Requests could not be loaded</strong>
        <span>Please retry to load the selected request status.</span>
        <button type="button" class="btn-soft mt-2 px-4 py-2 text-sm" @click="loadRequests(1)">Try again</button>
      </div>
      <div v-else-if="!requests?.data?.length" class="platform-state">
        <span class="platform-state-icon" aria-hidden="true"><i class="fas fa-inbox"></i></span>
        <strong>No {{ status }} requests</strong>
        <span>Subscription requests matching this status will appear here.</span>
      </div>
      <div v-else class="divide-y divide-slate-100">
        <article v-for="request in requests.data" :key="request.id" class="platform-request">
          <div class="platform-request-heading">
            <div>
              <h2>{{ request.shop_name }}</h2>
              <p>{{ request.shop_slug }}<span v-if="request.requested_by?.email"> · {{ request.requested_by.email }}</span></p>
            </div>
            <span class="status-pill" :class="{
              'bg-amber-50 text-amber-700 ring-amber-200': request.status === 'pending',
              'bg-emerald-50 text-emerald-700 ring-emerald-200': request.status === 'approved',
              'bg-rose-50 text-rose-700 ring-rose-200': request.status === 'rejected',
            }">{{ request.status_label }}</span>
          </div>

          <dl class="platform-request-details">
            <div><dt>Plan</dt><dd>{{ request.plan_name }} · {{ request.duration_months }} month{{ request.duration_months === 1 ? '' : 's' }}</dd></div>
            <div><dt>Amount</dt><dd>{{ formatCurrency(request.amount) }}</dd></div>
            <div><dt>Payment method</dt><dd>{{ request.payment_method_name }}</dd></div>
            <div><dt>Submitted</dt><dd>{{ request.created_at_formatted }}</dd></div>
            <div v-if="request.reference_number"><dt>Reference</dt><dd>{{ request.reference_number }}</dd></div>
            <div v-if="request.reviewed_at_formatted"><dt>Reviewed</dt><dd>{{ request.reviewed_at_formatted }}</dd></div>
          </dl>

          <a
            v-if="request.proof_url"
            :href="request.proof_url"
            target="_blank"
            rel="noopener noreferrer"
            class="btn-soft inline-flex min-h-10 items-center px-3 py-2 text-sm"
          ><i class="fas fa-arrow-up-right-from-square mr-2" aria-hidden="true"></i>View payment proof</a>
          <p v-else class="text-sm text-slate-500">No payment proof attached.</p>

          <p v-if="request.admin_notes" class="platform-admin-note"><strong>Admin note:</strong> {{ request.admin_notes }}</p>

          <template v-if="request.status === 'pending'">
            <label class="platform-field">
              <span>Admin notes <span class="font-normal text-slate-400">(optional)</span></span>
              <textarea v-model="notes[request.id]" rows="2" maxlength="1000" placeholder="Add a note for this review"></textarea>
            </label>
            <div class="flex flex-wrap justify-end gap-2">
              <button
                type="button"
                class="platform-button platform-button-danger"
                :disabled="busyRequestId !== null"
                @click="reviewRequest(request, 'reject')"
              >{{ busyRequestId === request.id ? 'Processing...' : 'Reject' }}</button>
              <button
                type="button"
                class="platform-button platform-button-primary"
                :disabled="busyRequestId !== null"
                @click="reviewRequest(request, 'approve')"
              >{{ busyRequestId === request.id ? 'Processing...' : 'Approve request' }}</button>
            </div>
          </template>
        </article>
      </div>

      <footer v-if="requests && !loading && requests.last_page > 1" class="platform-pagination">
        <span>Page {{ requests.current_page }} of {{ requests.last_page }}</span>
        <div class="flex gap-2">
          <button type="button" class="btn-soft px-3 py-2 text-sm" :disabled="requests.current_page <= 1" @click="loadRequests(requests.current_page - 1)">Previous</button>
          <button type="button" class="btn-soft px-3 py-2 text-sm" :disabled="requests.current_page >= requests.last_page" @click="loadRequests(requests.current_page + 1)">Next</button>
        </div>
      </footer>
    </section>
  </PlatformLayout>
</template>
