<script setup>
import { onMounted, ref } from 'vue';
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
    <div v-if="loading" class="card p-6 text-slate-600">Loading dashboard...</div>
    <div v-else-if="forbidden" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">You do not have access to this page.</div>
    <div v-else-if="errorMessage" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">{{ errorMessage }}</div>

    <div v-else class="space-y-6">
      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
        <div class="metric-card fade-up stagger-1">
          <p class="text-slate-500 text-sm">Total Jobs</p>
          <p class="text-3xl font-bold text-slate-900">{{ stats.totalJobs }}</p>
        </div>
        <div class="metric-card fade-up stagger-2">
          <p class="text-slate-500 text-sm">Active Jobs</p>
          <p class="text-3xl font-bold text-slate-900">{{ stats.activeJobs }}</p>
        </div>
        <div class="metric-card fade-up stagger-3">
          <p class="text-slate-500 text-sm">Waiting for Parts</p>
          <p class="text-3xl font-bold text-amber-700">{{ stats.waitingParts }}</p>
        </div>
        <div class="metric-card fade-up stagger-4">
          <p class="text-slate-500 text-sm">Ready for Pickup</p>
          <p class="text-3xl font-bold text-emerald-700">{{ stats.readyForPickup }}</p>
        </div>
        <div class="metric-card fade-up stagger-1">
          <p class="text-slate-500 text-sm">Overdue Repairs</p>
          <p class="text-3xl font-bold text-rose-700">{{ stats.overdueJobs }}</p>
        </div>
        <div class="metric-card fade-up stagger-2">
          <p class="text-slate-500 text-sm">Today's Intake</p>
          <p class="text-3xl font-bold text-slate-900">{{ stats.todayIntake }}</p>
        </div>
        <div class="metric-card fade-up stagger-3">
          <p class="text-slate-500 text-sm">Unpaid Jobs</p>
          <p class="text-3xl font-bold text-slate-900">{{ stats.unpaidJobs }}</p>
        </div>
        <div class="metric-card fade-up stagger-4">
          <p class="text-slate-500 text-sm">Pending Payments</p>
          <p class="text-2xl font-bold text-slate-900">{{ formatCurrency(stats.pendingPayments) }}</p>
        </div>
        <div class="metric-card fade-up stagger-1">
          <p class="text-slate-500 text-sm">Average Turnaround</p>
          <p class="text-3xl font-bold text-slate-900">{{ stats.averageTurnaround }}</p>
          <p class="text-xs text-slate-500 mt-1">days to completion</p>
        </div>
        <div class="metric-card fade-up stagger-2">
          <p class="text-slate-500 text-sm">Completion Rate</p>
          <p class="text-3xl font-bold text-slate-900">{{ stats.completionRate }}%</p>
        </div>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <div class="card p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-slate-900">Jobs Needing Attention</h3>
            <AppLink to="/admin/job-orders?overdue_only=1" class="text-slate-700 hover:text-slate-900 font-semibold">View Queue</AppLink>
          </div>
          <div class="space-y-3">
            <div v-for="job in stats.attentionJobs" :key="job.id" class="border border-slate-200 rounded-lg p-4">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <AppLink :to="`/admin/job-orders/${job.id}`" class="font-semibold text-slate-900 hover:text-slate-700">{{ job.job_order_number }}</AppLink>
                  <p class="text-sm text-slate-600">{{ job.customer_name }}</p>
                  <p v-if="job.technician_name" class="text-xs text-slate-500">Tech: {{ job.technician_name }}</p>
                </div>
                <div class="text-right">
                  <p class="text-sm font-semibold" :class="job.is_overdue ? 'text-rose-700' : 'text-slate-700'">{{ job.current_status.replaceAll('_', ' ') }}</p>
                  <p v-if="job.estimated_completion_formatted" class="text-xs text-slate-500">{{ job.estimated_completion_formatted }}</p>
                </div>
              </div>
            </div>
            <p v-if="!stats.attentionJobs.length" class="text-sm text-slate-500">No urgent jobs right now.</p>
          </div>
        </div>

        <div class="card p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-slate-900">Open Part Requests</h3>
            <AppLink to="/admin/part-requests" class="text-slate-700 hover:text-slate-900 font-semibold">Open Queue</AppLink>
          </div>
          <div class="space-y-3">
            <div v-for="request in stats.partRequests" :key="request.job_id" class="border border-slate-200 rounded-lg p-4">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <AppLink :to="`/admin/job-orders/${request.job_id}`" class="font-semibold text-slate-900 hover:text-slate-700">{{ request.job_order_number }}</AppLink>
                  <p class="text-sm text-slate-600">{{ request.customer_name }}</p>
                  <p class="text-xs text-slate-500">Tech: {{ request.technician_name || 'Unassigned' }}</p>
                </div>
                <div class="text-right">
                  <p class="text-sm font-semibold text-amber-700">{{ request.requested_part }}</p>
                  <p class="text-xs text-slate-500">Qty {{ request.requested_quantity }}</p>
                  <p class="text-xs text-slate-500">{{ request.requested_at_formatted }}</p>
                </div>
              </div>
            </div>
            <p v-if="!stats.partRequests.length" class="text-sm text-slate-500">No active parts requests right now.</p>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <div class="card p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-slate-900">Technician Workload</h3>
            <AppLink v-if="canManageTeam()" to="/admin/technicians" class="text-slate-700 hover:text-slate-900 font-semibold">Manage Team</AppLink>
          </div>
          <div class="space-y-3">
            <div v-for="tech in stats.technicians" :key="tech.id" class="border border-slate-200 rounded-lg p-4">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <AppLink v-if="canManageTeam()" :to="`/admin/technicians/${tech.id}`" class="font-semibold text-slate-900 hover:text-slate-700">{{ tech.name }}</AppLink>
                  <p v-else class="font-semibold text-slate-900">{{ tech.name }}</p>
                  <p class="text-sm text-slate-600">Current jobs: {{ tech.currentJobs }}</p>
                </div>
                <div class="text-right">
                  <p class="text-sm text-slate-700">Completed: {{ tech.completedJobs }}</p>
                  <p class="text-xs text-slate-500">Rating: {{ tech.rating || 'N/A' }}</p>
                </div>
              </div>
            </div>
            <p v-if="!stats.technicians.length" class="text-sm text-slate-500">No active technicians found.</p>
          </div>
        </div>
      </div>

      <div class="card p-6">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-semibold text-slate-900">Recent Job Orders</h3>
          <AppLink to="/admin/job-orders" class="text-slate-700 hover:text-slate-900 font-semibold">View All</AppLink>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-slate-100">
              <tr>
                <th class="px-4 py-3 text-left text-slate-600">Job Order</th>
                <th class="px-4 py-3 text-left text-slate-600">Customer</th>
                <th class="px-4 py-3 text-left text-slate-600">Status</th>
                <th class="px-4 py-3 text-left text-slate-600">Payment</th>
                <th class="px-4 py-3 text-left text-slate-600">Action</th>
              </tr>
            </thead>
            <tbody class="divide-y">
              <tr v-for="job in stats.recentJobs" :key="job.id">
                <td class="px-4 py-3">
                  <p class="font-semibold text-slate-900">{{ job.job_order_number }}</p>
                  <p class="text-xs text-slate-500">{{ job.created_at_formatted }}</p>
                </td>
                <td class="px-4 py-3 text-slate-700">{{ job.customer_name }}</td>
                <td class="px-4 py-3">
                  <p class="text-slate-700">{{ job.current_status.replaceAll('_', ' ') }}</p>
                  <p v-if="job.is_overdue" class="text-xs text-rose-600 font-semibold">Overdue</p>
                </td>
                <td class="px-4 py-3">
                  <p class="text-slate-700">{{ job.payment_status }}</p>
                  <p class="text-xs text-slate-500">Balance: {{ formatCurrency(job.balance) }}</p>
                </td>
                <td class="px-4 py-3">
                  <AppLink :to="`/admin/job-orders/${job.id}`" class="text-slate-900 hover:text-slate-700 font-semibold">View</AppLink>
                </td>
              </tr>
              <tr v-if="!stats.recentJobs.length">
                <td colspan="5" class="px-4 py-8 text-center text-slate-500">No recent jobs found.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
