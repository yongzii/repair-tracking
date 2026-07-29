<script setup>
import { onMounted, reactive, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AppLink from '../../components/AppLink.vue';
import { ensureRoles } from '../../composables/auth';
import { navigate } from '../../navigation';

const props = defineProps({
    params: { type: Object, default: () => ({}) },
});

const loading = ref(true);
const forbidden = ref(false);
const technicians = ref([]);
const errorMessage = ref('');
const jobOrder = ref(null);

const form = reactive({
    customer_name: '',
    customer_email: '',
    customer_phone: '',
    device_type: '',
    brand: '',
    model: '',
    serial_number: '',
    problem_description: '',
    included_accessories: '',
    priority: 'STANDARD',
    technician_id: '',
});

const loadData = async () => {
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
        const [meta, detail] = await Promise.all([
            window.axios.get('/api/admin/job-orders/meta'),
            window.axios.get(`/api/admin/job-orders/${props.params.id}`),
        ]);

        technicians.value = meta.data.technicians || [];
        jobOrder.value = detail.data.jobOrder;

        Object.assign(form, {
            customer_name: jobOrder.value.customer_name || '',
            customer_email: jobOrder.value.customer_email || '',
            customer_phone: jobOrder.value.customer_phone || '',
            device_type: jobOrder.value.device_type || '',
            brand: jobOrder.value.brand || '',
            model: jobOrder.value.model || '',
            serial_number: jobOrder.value.serial_number || '',
            problem_description: jobOrder.value.problem_description || '',
            included_accessories: jobOrder.value.included_accessories || '',
            priority: jobOrder.value.priority || 'STANDARD',
            technician_id: jobOrder.value.technician_id || '',
        });
    } catch {
        forbidden.value = true;
    } finally {
        loading.value = false;
    }
};

const submit = async () => {
    errorMessage.value = '';
    try {
        await window.axios.put(`/api/admin/job-orders/${props.params.id}`, {
            ...form,
            technician_id: form.technician_id || null,
        });
        navigate(`/admin/job-orders/${props.params.id}`, { replace: true });
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to update job order.';
    }
};

onMounted(loadData);
</script>

<template>
  <AdminLayout title="Edit Job Order">
    <div v-if="loading" class="card p-6 text-slate-600">Loading form...</div>
    <div v-else-if="forbidden" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">You do not have access to this page.</div>

    <div v-else class="card p-6">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold text-slate-900">Edit {{ jobOrder?.job_order_number }}</h3>
        <AppLink :to="`/admin/job-orders/${props.params.id}`" class="text-slate-700 hover:text-slate-900 font-semibold">Back</AppLink>
      </div>

      <div v-if="errorMessage" class="mb-4 p-3 rounded border border-rose-200 bg-rose-50 text-rose-700">{{ errorMessage }}</div>

      <form class="grid grid-cols-1 md:grid-cols-2 gap-4" @submit.prevent="submit">
        <input v-model="form.customer_name" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Customer name" required />
        <input v-model="form.customer_email" type="email" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Customer email (optional)" />
        <input v-model="form.customer_phone" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Customer cellphone number" required />
        <input v-model="form.device_type" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Device type" required />
        <input v-model="form.brand" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Brand" />
        <input v-model="form.model" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Model" />
        <input v-model="form.serial_number" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Serial number" />
        <select v-model="form.priority" class="px-4 py-2 border border-slate-300 rounded-lg">
          <option value="STANDARD">STANDARD</option>
          <option value="EXPRESS">EXPRESS</option>
          <option value="EMERGENCY">EMERGENCY</option>
        </select>
        <select v-model="form.technician_id" class="px-4 py-2 border border-slate-300 rounded-lg">
          <option value="">No technician assigned</option>
          <option v-for="tech in technicians" :key="tech.id" :value="tech.id">{{ tech.name }}</option>
        </select>
        <div></div>
        <textarea v-model="form.problem_description" class="md:col-span-2 px-4 py-2 border border-slate-300 rounded-lg" rows="4" placeholder="Problem description" required></textarea>
        <textarea v-model="form.included_accessories" class="md:col-span-2 px-4 py-2 border border-slate-300 rounded-lg" rows="3" placeholder="Included accessories (optional)"></textarea>
        <button type="submit" class="md:col-span-2 bg-slate-900 hover:bg-slate-800 text-white font-semibold py-2 rounded-lg">Save Changes</button>
      </form>
    </div>
  </AdminLayout>
</template>
