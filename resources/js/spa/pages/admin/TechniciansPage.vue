<script setup>
import { onMounted, reactive, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AppLink from '../../components/AppLink.vue';
import { ensureAdmin } from '../../composables/auth';
import { navigate } from '../../navigation';

const loading = ref(true);
const forbidden = ref(false);
const technicians = ref(null);
const errorMessage = ref('');

const form = reactive({
    name: '',
    email: '',
    phone: '',
    specialties: '',
});

const loadTechnicians = async () => {
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
        const { data } = await window.axios.get('/api/admin/technicians');
        technicians.value = data.technicians;
    } catch {
        forbidden.value = true;
    } finally {
        loading.value = false;
    }
};

const createTechnician = async () => {
    errorMessage.value = '';
    try {
        await window.axios.post('/api/admin/technicians', {
            name: form.name,
            email: form.email,
            phone: form.phone || null,
            specialties: form.specialties ? form.specialties.split(',').map((item) => item.trim()).filter(Boolean) : [],
        });

        form.name = '';
        form.email = '';
        form.phone = '';
        form.specialties = '';

        await loadTechnicians();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to create technician.';
    }
};

const deleteTechnician = async (id) => {
    if (!window.confirm('Delete this technician?')) return;
    await window.axios.delete(`/api/admin/technicians/${id}`);
    await loadTechnicians();
};

onMounted(loadTechnicians);
</script>

<template>
  <AdminLayout title="Technicians">
    <div v-if="loading" class="card p-6 text-slate-600">Loading technicians...</div>
    <div v-else-if="forbidden" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">You do not have access to this page.</div>

    <div v-else class="space-y-6">
      <div class="card p-6">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-semibold text-slate-900">Add Technician</h3>
          <AppLink to="/admin/technicians/create" class="text-slate-700 hover:text-slate-900 font-semibold">Open Full Form</AppLink>
        </div>

        <div v-if="errorMessage" class="mb-3 p-3 border border-rose-200 bg-rose-50 rounded text-rose-700">{{ errorMessage }}</div>

        <form class="grid grid-cols-1 md:grid-cols-4 gap-3" @submit.prevent="createTechnician">
          <input v-model="form.name" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Name" required />
          <input v-model="form.email" type="email" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Email" required />
          <input v-model="form.phone" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Phone" />
          <input v-model="form.specialties" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Specialties (comma separated)" />
          <button type="submit" class="md:col-span-4 bg-slate-900 hover:bg-slate-800 text-white font-semibold py-2 rounded-lg">Add Technician</button>
        </form>
      </div>

      <div class="card p-6">
        <table class="w-full text-sm">
          <thead class="bg-slate-100">
            <tr>
              <th class="px-4 py-3 text-left text-slate-600">Name</th>
              <th class="px-4 py-3 text-left text-slate-600">Email</th>
              <th class="px-4 py-3 text-left text-slate-600">Active</th>
              <th class="px-4 py-3 text-left text-slate-600">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="tech in technicians.data" :key="tech.id">
              <td class="px-4 py-3 text-slate-900 font-semibold">{{ tech.name }}</td>
              <td class="px-4 py-3 text-slate-700">{{ tech.email }}</td>
              <td class="px-4 py-3 text-slate-700">{{ tech.is_active ? 'Yes' : 'No' }}</td>
              <td class="px-4 py-3 flex gap-3">
                <AppLink :to="`/admin/technicians/${tech.id}`" class="text-slate-900 font-semibold">View</AppLink>
                <AppLink :to="`/admin/technicians/${tech.id}/edit`" class="text-slate-700 font-semibold">Edit</AppLink>
                <button type="button" class="text-rose-600 font-semibold" @click="deleteTechnician(tech.id)">Delete</button>
              </td>
            </tr>
            <tr v-if="!technicians.data.length">
              <td colspan="4" class="px-4 py-8 text-center text-slate-500">No technicians found.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AdminLayout>
</template>
