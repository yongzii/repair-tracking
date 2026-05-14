<script setup>
import { onMounted, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AppLink from '../../components/AppLink.vue';
import { ensureAdmin } from '../../composables/auth';
import { navigate } from '../../navigation';

const props = defineProps({
    params: { type: Object, default: () => ({}) },
});

const loading = ref(true);
const forbidden = ref(false);
const technician = ref(null);
const stats = ref({
    currentJobsCount: 0,
    completedJobsCount: 0,
    averageRating: 0,
});
const jobOrders = ref(null);

const loadTechnician = async () => {
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
        const { data } = await window.axios.get(`/api/admin/technicians/${props.params.id}`);
        technician.value = data.technician;
        jobOrders.value = data.jobOrders;
        stats.value = {
            currentJobsCount: data.currentJobsCount,
            completedJobsCount: data.completedJobsCount,
            averageRating: data.averageRating,
        };
    } catch {
        forbidden.value = true;
    } finally {
        loading.value = false;
    }
};

onMounted(loadTechnician);
</script>

<template>
  <AdminLayout title="Technician Details">
    <div v-if="loading" class="card p-6 text-slate-600">Loading technician...</div>
    <div v-else-if="forbidden" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">You do not have access to this page.</div>

    <div v-else class="space-y-6">
      <div class="card p-6">
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-xl font-semibold text-slate-900">{{ technician.name }}</h3>
          <div class="flex gap-3">
            <AppLink :to="`/admin/technicians/${technician.id}/edit`" class="text-slate-900 font-semibold">Edit</AppLink>
            <AppLink to="/admin/technicians" class="text-slate-700 font-semibold">Back</AppLink>
          </div>
        </div>
        <p class="text-slate-700">{{ technician.email }}</p>
        <p class="text-slate-600 text-sm mt-1">{{ technician.phone || 'No phone' }}</p>
        <p class="text-slate-600 text-sm mt-1">Specialties: {{ (technician.specialties || []).join(', ') || 'None' }}</p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="metric-card">
          <p class="text-slate-500 text-sm">Current Jobs</p>
          <p class="text-3xl font-bold text-slate-900">{{ stats.currentJobsCount }}</p>
        </div>
        <div class="metric-card">
          <p class="text-slate-500 text-sm">Completed Jobs</p>
          <p class="text-3xl font-bold text-slate-900">{{ stats.completedJobsCount }}</p>
        </div>
        <div class="metric-card">
          <p class="text-slate-500 text-sm">Average Rating</p>
          <p class="text-3xl font-bold text-slate-900">{{ stats.averageRating }}</p>
        </div>
      </div>

      <div class="card p-6">
        <h4 class="text-lg font-semibold text-slate-900 mb-3">Assigned Job Orders</h4>
        <table class="w-full text-sm">
          <thead class="bg-slate-100">
            <tr>
              <th class="px-4 py-3 text-left text-slate-600">Job Order</th>
              <th class="px-4 py-3 text-left text-slate-600">Customer</th>
              <th class="px-4 py-3 text-left text-slate-600">Status</th>
              <th class="px-4 py-3 text-left text-slate-600">Action</th>
            </tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="job in jobOrders.data" :key="job.id">
              <td class="px-4 py-3 font-semibold text-slate-900">{{ job.job_order_number }}</td>
              <td class="px-4 py-3 text-slate-700">{{ job.customer_name }}</td>
              <td class="px-4 py-3 text-slate-700">{{ job.current_status }}</td>
              <td class="px-4 py-3"><AppLink :to="`/admin/job-orders/${job.id}`" class="text-slate-900 font-semibold">View</AppLink></td>
            </tr>
            <tr v-if="!jobOrders.data.length">
              <td colspan="4" class="px-4 py-8 text-center text-slate-500">No jobs assigned.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AdminLayout>
</template>
