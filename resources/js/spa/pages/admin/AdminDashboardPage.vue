<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AppLink from '../../components/AppLink.vue';
import { adminRoles, authState, ensureRoles } from '../../composables/auth';
import { navigate } from '../../navigation';

const loading = ref(true);
const forbidden = ref(false);
const stats = ref(null);
const errorMessage = ref('');
const canManageTeam = () => adminRoles.includes(authState.user?.role);

const formatCurrency = (value) => {
    const amount = Number(value || 0);
    return `PHP ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

const metricCards = computed(() => {
    if (!stats.value) return [];

    return [
        { label: 'Total Jobs', value: stats.value.totalJobs, hint: 'All repair records', accent: 'text-slate-950', tone: 'bg-slate-950' },
        { label: 'Active Jobs', value: stats.value.activeJobs, hint: 'Currently in progress', accent: 'text-teal-700', tone: 'bg-teal-500' },
        { label: 'Waiting for Parts', value: stats.value.waitingParts, hint: 'Needs inventory action', accent: 'text-amber-700', tone: 'bg-amber-400' },
        { label: 'Ready for Pickup', value: stats.value.readyForPickup, hint: 'Customer pickup queue', accent: 'text-emerald-700', tone: 'bg-emerald-500' },
        { label: 'Overdue Repairs', value: stats.value.overdueJobs, hint: 'Needs attention', accent: 'text-rose-700', tone: 'bg-rose-500' },
        { label: "Today's Intake", value: stats.value.todayIntake, hint: 'New jobs today', accent: 'text-slate-950', tone: 'bg-slate-400' },
        { label: 'Unpaid Jobs', value: stats.value.unpaidJobs, hint: 'With remaining balance', accent: 'text-slate-950', tone: 'bg-indigo-500' },
        { label: 'Pending Payments', value: formatCurrency(stats.value.pendingPayments), hint: 'Total receivables', accent: 'text-slate-950', tone: 'bg-cyan-500', wide: true },
    ];
});

const healthCards = computed(() => {
    if (!stats.value) return [];

    return [
        { label: 'Average Turnaround', value: stats.value.averageTurnaround, suffix: 'days', detail: 'Average completion time' },
        { label: 'Completion Rate', value: `${stats.value.completionRate}%`, detail: 'Completed against all jobs' },
        { label: 'Profit Margin', value: `${stats.value.profitMargin || 0}%`, detail: 'Estimated from repair cost data' },
    ];
});

const statusClass = (status, overdue = false) => {
    if (overdue) return 'bg-rose-50 text-rose-700 ring-rose-200';
    if (status === 'READY_FOR_PICKUP' || status === 'PICKED_UP') return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    if (status === 'WAITING_PARTS') return 'bg-amber-50 text-amber-700 ring-amber-200';
    if (status === 'UNREPAIRABLE') return 'bg-slate-100 text-slate-700 ring-slate-200';
    return 'bg-teal-50 text-teal-700 ring-teal-200';
};

const loadDashboard = async () => {
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
        const { data } = await window.axios.get('/api/admin/dashboard');
        stats.value = data;
    } catch (error) {
        if (error?.response?.status === 403) {
            forbidden.value = true;
        } else {
            errorMessage.value = error?.response?.data?.message || 'Unable to load dashboard right now.';
        }
    } finally {
        loading.value = false;
    }
};

onMounted(loadDashboard);
</script>

<template>
  <AdminLayout title="Dashboard">
    <div v-if="loading" class="rounded-xl border border-slate-200 bg-white p-6 text-slate-600 shadow-sm">Loading dashboard...</div>
    <div v-else-if="forbidden" class="rounded-xl border border-rose-200 bg-rose-50 p-6 text-rose-700">You do not have access to this page.</div>
    <div v-else-if="errorMessage" class="rounded-xl border border-rose-200 bg-rose-50 p-6 text-rose-700">{{ errorMessage }}</div>

    <div v-else class="space-y-6">
      <section class="overflow-hidden rounded-2xl border border-slate-200 bg-slate-950 shadow-xl shadow-slate-900/10">
        <div class="grid gap-6 p-6 text-white lg:grid-cols-[1.1fr_0.9fr] lg:p-8">
          <div>
            <p class="text-xs font-bold uppercase text-teal-300">Operations overview</p>
            <h3 class="mt-3 font-display text-4xl leading-tight">Repair queue and shop performance</h3>
            <p class="mt-4 max-w-2xl text-sm leading-6 text-slate-300">
              Monitor active repairs, pending payments, parts requests, technician workload, and recent customer jobs from one dashboard.
            </p>
            <div class="mt-6 flex flex-wrap gap-3">
              <AppLink to="/admin/job-orders/create" class="rounded-lg bg-amber-400 px-4 py-2 text-sm font-bold text-slate-950 hover:bg-amber-300">New Job Order</AppLink>
              <AppLink to="/admin/part-requests" class="rounded-lg bg-white/10 px-4 py-2 text-sm font-bold text-white hover:bg-white/15">Review Parts</AppLink>
              <AppLink to="/admin/reports" class="rounded-lg bg-white/10 px-4 py-2 text-sm font-bold text-white hover:bg-white/15">Open Reports</AppLink>
            </div>
          </div>

          <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-1 xl:grid-cols-3">
            <div v-for="item in healthCards" :key="item.label" class="rounded-xl border border-white/10 bg-white/5 p-4">
              <p class="text-xs font-bold uppercase text-slate-400">{{ item.label }}</p>
              <p class="mt-2 text-2xl font-bold text-white">
                {{ item.value }} <span v-if="item.suffix" class="text-sm font-semibold text-slate-300">{{ item.suffix }}</span>
              </p>
              <p class="mt-1 text-xs leading-5 text-slate-400">{{ item.detail }}</p>
            </div>
          </div>
        </div>
      </section>

      <section class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div v-for="metric in metricCards" :key="metric.label" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg hover:shadow-slate-900/5">
          <div class="flex items-start justify-between gap-4">
            <div>
              <p class="text-sm font-semibold text-slate-500">{{ metric.label }}</p>
              <p class="mt-2 text-3xl font-bold leading-tight" :class="metric.accent">{{ metric.value }}</p>
              <p class="mt-2 text-xs text-slate-500">{{ metric.hint }}</p>
            </div>
            <span class="mt-1 h-3 w-3 rounded-full" :class="metric.tone"></span>
          </div>
        </div>
      </section>

      <section class="grid grid-cols-1 gap-6 xl:grid-cols-[1fr_1fr]">
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <div class="mb-5 flex items-center justify-between gap-4">
            <div>
              <h3 class="text-lg font-bold text-slate-950">Jobs Needing Attention</h3>
              <p class="mt-1 text-sm text-slate-500">Overdue, waiting parts, or pickup-ready jobs.</p>
            </div>
            <AppLink to="/admin/job-orders?overdue_only=1" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold text-slate-700 hover:border-teal-200 hover:text-teal-700">View Queue</AppLink>
          </div>

          <div class="space-y-3">
            <div v-for="job in stats.attentionJobs" :key="job.id" class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
              <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                  <AppLink :to="`/admin/job-orders/${job.id}`" class="font-bold text-slate-950 hover:text-teal-700">{{ job.job_order_number }}</AppLink>
                  <p class="mt-1 text-sm text-slate-600">{{ job.customer_name }}</p>
                  <p v-if="job.technician_name" class="mt-1 text-xs text-slate-500">Tech: {{ job.technician_name }}</p>
                </div>
                <div class="shrink-0 text-right">
                  <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold ring-1" :class="statusClass(job.current_status, job.is_overdue)">
                    {{ job.current_status.replaceAll('_', ' ') }}
                  </span>
                  <p v-if="job.estimated_completion_formatted" class="mt-2 text-xs text-slate-500">{{ job.estimated_completion_formatted }}</p>
                </div>
              </div>
            </div>
            <p v-if="!stats.attentionJobs.length" class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">No urgent jobs right now.</p>
          </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <div class="mb-5 flex items-center justify-between gap-4">
            <div>
              <h3 class="text-lg font-bold text-slate-950">Open Part Requests</h3>
              <p class="mt-1 text-sm text-slate-500">Requested parts that may affect repair completion.</p>
            </div>
            <AppLink to="/admin/part-requests" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold text-slate-700 hover:border-teal-200 hover:text-teal-700">Open Queue</AppLink>
          </div>

          <div class="space-y-3">
            <div v-for="request in stats.partRequests" :key="request.job_id" class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
              <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                  <AppLink :to="`/admin/job-orders/${request.job_id}`" class="font-bold text-slate-950 hover:text-teal-700">{{ request.job_order_number }}</AppLink>
                  <p class="mt-1 text-sm text-slate-600">{{ request.customer_name }}</p>
                  <p class="mt-1 text-xs text-slate-500">Tech: {{ request.technician_name || 'Unassigned' }}</p>
                </div>
                <div class="shrink-0 text-right">
                  <p class="text-sm font-bold text-amber-700">{{ request.requested_part }}</p>
                  <p class="mt-1 text-xs text-slate-500">Qty {{ request.requested_quantity }}</p>
                  <p class="text-xs text-slate-500">{{ request.requested_at_formatted }}</p>
                </div>
              </div>
            </div>
            <p v-if="!stats.partRequests.length" class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">No active parts requests right now.</p>
          </div>
        </div>
      </section>

      <section class="grid grid-cols-1 gap-6 xl:grid-cols-[0.82fr_1.18fr]">
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <div class="mb-5 flex items-center justify-between gap-4">
            <div>
              <h3 class="text-lg font-bold text-slate-950">Technician Workload</h3>
              <p class="mt-1 text-sm text-slate-500">Current queue by technician.</p>
            </div>
            <AppLink v-if="canManageTeam()" to="/admin/technicians" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold text-slate-700 hover:border-teal-200 hover:text-teal-700">Manage</AppLink>
          </div>

          <div class="space-y-3">
            <div v-for="tech in stats.technicians" :key="tech.id" class="rounded-xl border border-slate-200 p-4">
              <div class="flex items-center justify-between gap-4">
                <div>
                  <AppLink v-if="canManageTeam()" :to="`/admin/technicians/${tech.id}`" class="font-bold text-slate-950 hover:text-teal-700">{{ tech.name }}</AppLink>
                  <p v-else class="font-bold text-slate-950">{{ tech.name }}</p>
                  <p class="mt-1 text-sm text-slate-500">Current jobs: {{ tech.currentJobs }}</p>
                </div>
                <div class="text-right">
                  <p class="text-sm font-semibold text-slate-700">Completed: {{ tech.completedJobs }}</p>
                  <p class="text-xs text-slate-500">Rating: {{ tech.rating || 'N/A' }}</p>
                </div>
              </div>
            </div>
            <p v-if="!stats.technicians.length" class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">No active technicians found.</p>
          </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <div class="mb-5 flex items-center justify-between gap-4">
            <div>
              <h3 class="text-lg font-bold text-slate-950">Recent Job Orders</h3>
              <p class="mt-1 text-sm text-slate-500">Latest repair transactions and payment status.</p>
            </div>
            <AppLink to="/admin/job-orders" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold text-slate-700 hover:border-teal-200 hover:text-teal-700">View All</AppLink>
          </div>

          <div class="overflow-x-auto">
            <table class="w-full min-w-[760px] text-sm">
              <thead>
                <tr class="border-y border-slate-200 bg-slate-50 text-xs uppercase text-slate-500">
                  <th class="px-4 py-3 text-left font-bold">Job Order</th>
                  <th class="px-4 py-3 text-left font-bold">Customer</th>
                  <th class="px-4 py-3 text-left font-bold">Status</th>
                  <th class="px-4 py-3 text-left font-bold">Payment</th>
                  <th class="px-4 py-3 text-left font-bold">Action</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="job in stats.recentJobs" :key="job.id" class="hover:bg-slate-50/80">
                  <td class="px-4 py-4">
                    <p class="font-bold text-slate-950">{{ job.job_order_number }}</p>
                    <p class="text-xs text-slate-500">{{ job.created_at_formatted }}</p>
                  </td>
                  <td class="px-4 py-4 text-slate-700">{{ job.customer_name }}</td>
                  <td class="px-4 py-4">
                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold ring-1" :class="statusClass(job.current_status, job.is_overdue)">
                      {{ job.is_overdue ? 'OVERDUE' : job.current_status.replaceAll('_', ' ') }}
                    </span>
                  </td>
                  <td class="px-4 py-4">
                    <p class="font-semibold text-slate-800">{{ job.payment_status }}</p>
                    <p class="text-xs text-slate-500">Balance: {{ formatCurrency(job.balance) }}</p>
                  </td>
                  <td class="px-4 py-4">
                    <AppLink :to="`/admin/job-orders/${job.id}`" class="font-bold text-teal-700 hover:text-teal-800">View</AppLink>
                  </td>
                </tr>
                <tr v-if="!stats.recentJobs.length">
                  <td colspan="5" class="px-4 py-8 text-center text-slate-500">No recent jobs found.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </div>
  </AdminLayout>
</template>
