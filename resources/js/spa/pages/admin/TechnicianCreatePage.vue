<script setup>
import { reactive, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AppLink from '../../components/AppLink.vue';
import { ensureAdmin } from '../../composables/auth';
import { navigate } from '../../navigation';

const form = reactive({
    name: '',
    email: '',
    phone: '',
    specialties: '',
});

const errorMessage = ref('');

const submit = async () => {
    const auth = await ensureAdmin();
    if (!auth.ok) {
        navigate('/login', { replace: true });
        return;
    }

    errorMessage.value = '';
    try {
        await window.axios.post('/api/admin/technicians', {
            name: form.name,
            email: form.email,
            phone: form.phone || null,
            specialties: form.specialties ? form.specialties.split(',').map((item) => item.trim()).filter(Boolean) : [],
        });
        navigate('/admin/technicians', { replace: true });
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to create technician.';
    }
};
</script>

<template>
  <AdminLayout title="Add Technician">
    <div class="card p-6 max-w-2xl">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold text-slate-900">New Technician</h3>
        <AppLink to="/admin/technicians" class="text-slate-700 hover:text-slate-900 font-semibold">Back</AppLink>
      </div>

      <div v-if="errorMessage" class="mb-3 p-3 border border-rose-200 bg-rose-50 rounded text-rose-700">{{ errorMessage }}</div>

      <form class="space-y-3" @submit.prevent="submit">
        <input v-model="form.name" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Name" required />
        <input v-model="form.email" type="email" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Email" required />
        <input v-model="form.phone" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Phone" />
        <input v-model="form.specialties" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Specialties (comma separated)" />
        <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white font-semibold py-2 rounded-lg">Create Technician</button>
      </form>
    </div>
  </AdminLayout>
</template>
