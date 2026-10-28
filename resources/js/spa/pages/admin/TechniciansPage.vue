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
      <section class="admin-hero p-6">
        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <p class="text-xs font-bold uppercase text-teal-700">Repair workforce</p>
            <h3 class="mt-2 font-display text-3xl">Technicians</h3>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Manage technician profiles, specialties, and access to assigned repair jobs.</p>
          </div>
          <AppLink to="/admin/technicians/create" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-bold text-white hover:bg-slate-800">Open Full Form</AppLink>
        </div>
      </section>

      <div class="admin-panel p-6">
        <h3 class="mb-4 text-lg font-bold text-slate-950">Add Technician</h3>

        <div v-if="errorMessage" class="mb-3 p-3 border border-rose-200 bg-rose-50 rounded text-rose-700">{{ errorMessage }}</div>

        <form class="grid grid-cols-1 md:grid-cols-4 gap-3" @submit.prevent="createTechnician">
          <input v-model="form.name" class="border border-slate-300 px-4 py-3" placeholder="Name" required />
          <input v-model="form.email" type="email" class="border border-slate-300 px-4 py-3" placeholder="Email" required />
          <input v-model="form.phone" class="border border-slate-300 px-4 py-3" placeholder="Phone" />
          <input v-model="form.specialties" class="border border-slate-300 px-4 py-3" placeholder="Specialties (comma separated)" />
          <button type="submit" class="btn-primary md:col-span-4 py-3">Add Technician</button>
        </form>
      </div>

      <div class="admin-panel overflow-hidden">
        <div class="overflow-x-auto">
        <table class="data-table">
          <thead>
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
              <td class="px-4 py-3">
                <div class="flex gap-2">
                  <AppLink :to="`/admin/technicians/${tech.id}`" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700 hover:border-teal-200 hover:text-teal-700">View</AppLink>
                  <AppLink :to="`/admin/technicians/${tech.id}/edit`" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700 hover:border-teal-200 hover:text-teal-700">Edit</AppLink>
                  <button type="button" class="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-bold text-rose-600 hover:bg-rose-50" @click="deleteTechnician(tech.id)">Delete</button>
                </div>
              </td>
            </tr>
            <tr v-if="!technicians.data.length">
              <td colspan="4" class="px-4 py-8 text-center text-slate-500">No technicians found.</td>
            </tr>
          </tbody>
        </table>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
