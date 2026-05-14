<script setup>
import { onMounted, reactive, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AppLink from '../../components/AppLink.vue';
import { ensureAdmin } from '../../composables/auth';
import { navigate } from '../../navigation';

const props = defineProps({
    params: { type: Object, default: () => ({}) },
});

const loading = ref(true);
const forbidden = ref(false);
const errorMessage = ref('');

const form = reactive({
    name: '',
    email: '',
    phone: '',
    specialties: '',
    is_active: true,
});

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
        const tech = data.technician;
        form.name = tech.name || '';
        form.email = tech.email || '';
        form.phone = tech.phone || '';
        form.specialties = Array.isArray(tech.specialties) ? tech.specialties.join(', ') : '';
        form.is_active = Boolean(tech.is_active);
    } catch {
        forbidden.value = true;
    } finally {
        loading.value = false;
    }
};

const submit = async () => {
    errorMessage.value = '';
    try {
        await window.axios.put(`/api/admin/technicians/${props.params.id}`, {
            name: form.name,
            email: form.email,
            phone: form.phone || null,
            specialties: form.specialties ? form.specialties.split(',').map((item) => item.trim()).filter(Boolean) : [],
            is_active: form.is_active,
        });
        navigate(`/admin/technicians/${props.params.id}`, { replace: true });
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to update technician.';
    }
};

onMounted(loadTechnician);
</script>

<template>
  <AdminLayout title="Edit Technician">
    <div v-if="loading" class="card p-6 text-slate-600">Loading technician...</div>
    <div v-else-if="forbidden" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">You do not have access to this page.</div>

    <div v-else class="card p-6 max-w-2xl">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold text-slate-900">Edit Technician</h3>
        <AppLink :to="`/admin/technicians/${props.params.id}`" class="text-slate-700 hover:text-slate-900 font-semibold">Back</AppLink>
      </div>

      <div v-if="errorMessage" class="mb-3 p-3 border border-rose-200 bg-rose-50 rounded text-rose-700">{{ errorMessage }}</div>

      <form class="space-y-3" @submit.prevent="submit">
        <input v-model="form.name" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Name" required />
        <input v-model="form.email" type="email" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Email" required />
        <input v-model="form.phone" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Phone" />
        <input v-model="form.specialties" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Specialties (comma separated)" />
        <label class="flex items-center gap-2 text-sm text-slate-700">
          <input v-model="form.is_active" type="checkbox" />
          Active
        </label>
        <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white font-semibold py-2 rounded-lg">Save Technician</button>
      </form>
    </div>
  </AdminLayout>
</template>
