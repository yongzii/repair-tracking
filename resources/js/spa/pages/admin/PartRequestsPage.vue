<script setup>
import { computed, onMounted, ref } from 'vue';
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

const metricCards = computed(() => [
    {
        label: 'Open Requests',
        value: summary.value.total,
        hint: 'Waiting on inventory action',
        accent: 'text-slate-950',
        tone: 'bg-slate-950',
    },
    {
        label: 'With Inventory Matches',
        value: summary.value.withStockMatches,
        hint: 'Potential parts found',
        accent: 'text-emerald-700',
        tone: 'bg-emerald-500',
    },
    {
        label: 'No Match Found Yet',
        value: summary.value.withoutStockMatches,
        hint: 'Needs inventory review',
        accent: 'text-amber-700',
        tone: 'bg-amber-400',
    },
]);

const formatCurrency = (value) => {
    const amount = Number(value || 0);
    return `PHP ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

const stockBadgeClass = (match, requestedQuantity) => {
    return match.stock_quantity >= requestedQuantity
        ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
        : 'bg-rose-50 text-rose-700 ring-rose-200';
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
      <div v-if="successMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700">{{ successMessage }}</div>
      <div v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">{{ errorMessage }}</div>

      <section class="rounded-2xl border border-slate-200 bg-slate-950 p-6 text-white shadow-xl shadow-slate-900/10">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <p class="text-xs font-bold uppercase text-teal-300">Inventory support queue</p>
            <h3 class="mt-2 font-display text-3xl leading-tight">Part requests from technicians</h3>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">
              Review parts requested from active repairs, match them against inventory, and return jobs back to progress once stock is issued.
            </p>
          </div>
          <AppLink to="/admin/job-orders" class="inline-flex rounded-lg bg-white/10 px-4 py-2 text-sm font-bold text-white hover:bg-white/15">
            View Job Orders
          </AppLink>
        </div>
      </section>

      <section class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <div v-for="metric in metricCards" :key="metric.label" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
          <div class="flex items-start justify-between gap-4">
            <div>
              <p class="text-sm font-semibold text-slate-500">{{ metric.label }}</p>
              <p class="mt-2 text-3xl font-bold" :class="metric.accent">{{ metric.value }}</p>
              <p class="mt-2 text-xs text-slate-500">{{ metric.hint }}</p>
            </div>
            <span class="mt-1 h-3 w-3 rounded-full" :class="metric.tone"></span>
          </div>
        </div>
      </section>

      <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="mb-5 flex items-center justify-between gap-3">
          <div>
            <h3 class="text-lg font-bold text-slate-950">Technician Requests Waiting on Parts</h3>
            <p class="mt-1 text-sm text-slate-500">Jobs marked as waiting for parts from the technician portal.</p>
          </div>
        </div>

        <div v-if="requests.length" class="space-y-4">
          <div v-for="request in requests" :key="request.job_id" class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
            <div class="grid gap-4 xl:grid-cols-[0.8fr_1.2fr]">
              <div class="rounded-xl bg-white p-4 ring-1 ring-slate-200">
                <div class="flex items-start justify-between gap-4">
                  <div>
                    <AppLink :to="`/admin/job-orders/${request.job_id}`" class="font-bold text-slate-950 hover:text-teal-700">
                      {{ request.job_order_number }}
                    </AppLink>
                    <p class="mt-1 text-sm font-semibold text-slate-700">{{ request.customer_name }} - {{ request.device_type }}</p>
                    <p class="mt-1 text-xs text-slate-500">
                      Requested by {{ request.technician_name || 'Unassigned technician' }}
                    </p>
                  </div>
                  <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-700 ring-1 ring-amber-200">
                    Waiting Parts
                  </span>
                </div>

                <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
                  <p class="text-xs font-bold uppercase text-slate-400">Requested Part</p>
                  <p class="mt-2 text-xl font-bold text-slate-950">{{ request.requested_part }}</p>
                  <div class="mt-3 grid grid-cols-2 gap-3 text-sm">
                    <div>
                      <p class="text-xs font-semibold text-slate-400">Needed</p>
                      <p class="font-bold text-slate-800">{{ request.requested_quantity }} pcs</p>
                    </div>
                    <div>
                      <p class="text-xs font-semibold text-slate-400">Requested</p>
                      <p class="font-bold text-slate-800">{{ request.requested_at_formatted }}</p>
                    </div>
                  </div>
                  <p v-if="request.request_note" class="mt-3 rounded-lg bg-white p-3 text-sm text-slate-600">Note: {{ request.request_note }}</p>
                </div>
              </div>

              <div class="rounded-xl bg-white p-4 ring-1 ring-slate-200">
                <div class="mb-3 flex items-center justify-between gap-3">
                  <div>
                    <p class="font-bold text-slate-950">Suggested Inventory Matches</p>
                    <p class="text-xs text-slate-500">Matched from active inventory items.</p>
                  </div>
                </div>

                <div v-if="request.inventory_matches.length" class="space-y-3">
                  <div v-for="match in request.inventory_matches" :key="match.id" class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                      <div class="min-w-0">
                        <p class="font-bold text-slate-950">{{ match.name }}</p>
                        <p class="text-xs text-slate-500">{{ match.part_number || 'No part number' }}</p>
                        <p class="mt-2 text-xs text-slate-500">Unit cost: {{ formatCurrency(match.unit_cost) }}</p>
                      </div>
                      <div class="shrink-0 text-left md:text-right">
                        <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold ring-1" :class="stockBadgeClass(match, request.requested_quantity)">
                          Available: {{ match.stock_quantity }} {{ match.unit || 'pcs' }}
                        </span>
                        <p class="mt-2 text-xs font-semibold text-slate-500">Needs {{ request.requested_quantity }} {{ match.unit || 'pcs' }}</p>
                      </div>
                    </div>

                    <div class="mt-4 flex flex-col gap-3 border-t border-slate-200 pt-3 sm:flex-row sm:items-center sm:justify-between">
                      <p class="text-xs text-slate-500">
                        {{ match.stock_quantity >= request.requested_quantity ? 'Ready to issue from inventory.' : 'Insufficient stock for this request.' }}
                      </p>
                      <button
                        type="button"
                        class="rounded-lg px-4 py-2 text-sm font-bold"
                        :class="match.stock_quantity >= request.requested_quantity ? 'bg-slate-950 text-white hover:bg-slate-800' : 'cursor-not-allowed bg-slate-200 text-slate-500'"
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
                <p v-else class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">No active inventory item matched this request yet.</p>
              </div>
            </div>
          </div>
        </div>

        <p v-else class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">No open technician part requests right now.</p>
      </section>
    </div>
  </AdminLayout>
</template>
