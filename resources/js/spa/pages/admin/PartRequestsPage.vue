<script setup>
import { onMounted, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AppLink from '../../components/AppLink.vue';
import { ensureRoles } from '../../composables/auth';
import { navigate } from '../../navigation';

const loading = ref(true);
const forbidden = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const requests = ref([]);
const fulfillingKey = ref('');
const summary = ref({
    total: 0,
    withStockMatches: 0,
    withoutStockMatches: 0,
});

const formatCurrency = (value) => {
    const amount = Number(value || 0);
    return `PHP ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

const loadRequests = async () => {
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

    try {
        const { data } = await window.axios.get('/api/admin/part-requests');
        requests.value = data.requests || [];
        summary.value = data.summary || summary.value;
    } catch (error) {
        if (error?.response?.status === 403) {
            forbidden.value = true;
        } else {
            errorMessage.value = error?.response?.data?.message || 'Unable to load parts requests.';
        }
    } finally {
        loading.value = false;
    }
};

const fulfillRequest = async (request, match) => {
    errorMessage.value = '';
    successMessage.value = '';
    fulfillingKey.value = `${request.job_id}:${match.id}`;

    try {
        const { data } = await window.axios.post(`/api/admin/part-requests/${request.job_id}/fulfill`, {
            spare_part_id: match.id,
            quantity: request.requested_quantity,
            note: `Fulfilled from queue by matching inventory item ${match.name}.`,
        });
        successMessage.value = data.message || 'Parts request fulfilled successfully.';
        await loadRequests();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to fulfill parts request.';
    } finally {
        fulfillingKey.value = '';
    }
};

onMounted(loadRequests);
</script>

<template>
  <AdminLayout title="Part Requests">
    <div v-if="loading" class="card p-6 text-slate-600">Loading parts requests...</div>
    <div v-else-if="forbidden" class="card border border-rose-200 bg-rose-50 p-6 text-rose-700">You do not have access to this page.</div>

    <div v-else class="space-y-6">
      <div v-if="successMessage" class="card border border-emerald-200 bg-emerald-50 p-4 text-emerald-900">{{ successMessage }}</div>
      <div v-if="errorMessage" class="card border border-rose-200 bg-rose-50 p-4 text-rose-900">{{ errorMessage }}</div>

      <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <div class="metric-card">
          <p class="text-sm text-slate-500">Open Requests</p>
          <p class="text-3xl font-bold text-slate-900">{{ summary.total }}</p>
        </div>
        <div class="metric-card">
          <p class="text-sm text-slate-500">With Inventory Matches</p>
          <p class="text-3xl font-bold text-emerald-700">{{ summary.withStockMatches }}</p>
        </div>
        <div class="metric-card">
          <p class="text-sm text-slate-500">No Match Found Yet</p>
          <p class="text-3xl font-bold text-amber-700">{{ summary.withoutStockMatches }}</p>
        </div>
      </div>

      <div class="card p-6">
        <div class="mb-4 flex items-center justify-between gap-3">
          <div>
            <h3 class="text-lg font-semibold text-slate-900">Technician Requests Waiting on Parts</h3>
            <p class="text-sm text-slate-500">These jobs were marked as waiting for parts from the technician portal.</p>
          </div>
          <AppLink to="/admin/job-orders" class="font-semibold text-slate-700 hover:text-slate-900">View Job Orders</AppLink>
        </div>

        <div v-if="requests.length" class="space-y-4">
          <div v-for="request in requests" :key="request.job_id" class="rounded-xl border border-slate-200 p-4">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
              <div class="space-y-2">
                <div>
                  <AppLink :to="`/admin/job-orders/${request.job_id}`" class="font-semibold text-slate-900 hover:text-slate-700">
                    {{ request.job_order_number }}
                  </AppLink>
                  <p class="text-sm text-slate-600">{{ request.customer_name }} - {{ request.device_type }}</p>
                  <p class="text-xs text-slate-500">
                    Requested by {{ request.technician_name || 'Unassigned technician' }} on {{ request.requested_at_formatted }}
                  </p>
                </div>

                <div class="rounded-lg bg-slate-50 p-3">
                  <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Requested Part</p>
                  <p class="text-lg font-semibold text-slate-900">{{ request.requested_part }}</p>
                  <p class="text-sm text-slate-600">Quantity needed: {{ request.requested_quantity }}</p>
                  <p v-if="request.request_note" class="mt-1 text-sm text-slate-600">Note: {{ request.request_note }}</p>
                </div>
              </div>

              <div class="w-full max-w-xl rounded-lg border border-slate-200 bg-white p-3">
                <p class="mb-2 text-sm font-semibold text-slate-900">Suggested Inventory Matches</p>
                <div v-if="request.inventory_matches.length" class="space-y-2">
                  <div v-for="match in request.inventory_matches" :key="match.id" class="rounded-lg border border-slate-100 bg-slate-50 p-3">
                    <div class="flex items-start justify-between gap-3">
                      <div>
                        <p class="font-semibold text-slate-900">{{ match.name }}</p>
                        <p class="text-xs text-slate-500">{{ match.part_number || 'No part number' }}</p>
                      </div>
                      <div class="text-right">
                        <p class="text-sm font-semibold" :class="match.has_stock ? 'text-emerald-700' : 'text-rose-700'">
                          {{ match.stock_quantity }} {{ match.unit || 'pcs' }}
                        </p>
                        <p class="text-xs text-slate-500">{{ formatCurrency(match.unit_cost) }}</p>
                      </div>
                    </div>
                    <div class="mt-3 flex items-center justify-between gap-3">
                      <p class="text-xs text-slate-500">
                        Needs {{ request.requested_quantity }} {{ match.unit || 'pcs' }}.
                      </p>
                      <button
                        type="button"
                        class="rounded-lg px-3 py-2 text-sm font-semibold text-white"
                        :class="match.stock_quantity >= request.requested_quantity ? 'bg-slate-900 hover:bg-slate-800' : 'bg-slate-300 cursor-not-allowed'"
                        :disabled="match.stock_quantity < request.requested_quantity || fulfillingKey === `${request.job_id}:${match.id}`"
                        @click="fulfillRequest(request, match)"
                      >
                        {{
                          fulfillingKey === `${request.job_id}:${match.id}`
                            ? 'Fulfilling...'
                            : match.stock_quantity >= request.requested_quantity
                              ? 'Use For Job'
                              : 'Not Enough Stock'
                        }}
                      </button>
                    </div>
                  </div>
                </div>
                <p v-else class="text-sm text-slate-500">No active inventory item matched this request yet.</p>
              </div>
            </div>
          </div>
        </div>

        <p v-else class="py-6 text-center text-sm text-slate-500">No open technician part requests right now.</p>
      </div>
    </div>
  </AdminLayout>
</template>
