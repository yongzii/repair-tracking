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
const roles = ref([]);

const form = reactive({
    name: '',
    email: '',
    role: 'staff',
    is_active: true,
});

const loadUser = async () => {
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
        const { data } = await window.axios.get(`/api/admin/users/${props.params.id}`);
        roles.value = data.roles || [];
        form.name = data.user.name || '';
        form.email = data.user.email || '';
        form.role = data.user.role || 'staff';
        form.is_active = Boolean(data.user.is_active);
    } catch (error) {
        forbidden.value = true;
        errorMessage.value = error?.response?.data?.message || 'Unable to load user account.';
    } finally {
        loading.value = false;
    }
};

const submit = async () => {
    errorMessage.value = '';

    try {
        await window.axios.put(`/api/admin/users/${props.params.id}`, {
            name: form.name,
            email: form.email,
            role: form.role,
            is_active: form.is_active,
        });
        navigate('/admin/users', { replace: true });
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to update user account.';
    }
};

onMounted(loadUser);
</script>

<template>
  <AdminLayout title="Edit User">
    <div v-if="loading" class="card p-6 text-slate-600">Loading user account...</div>
    <div v-else-if="forbidden" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">{{ errorMessage || 'You do not have access to this page.' }}</div>

    <div v-else class="card p-6 max-w-2xl">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold text-slate-900">Edit User Account</h3>
        <AppLink to="/admin/users" class="text-slate-700 hover:text-slate-900 font-semibold">Back</AppLink>
      </div>

      <div v-if="errorMessage" class="mb-3 p-3 border border-rose-200 bg-rose-50 rounded text-rose-700">{{ errorMessage }}</div>

      <form class="space-y-3" @submit.prevent="submit">
        <input v-model="form.name" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Full name" required />
        <input v-model="form.email" type="email" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Email" required />
        <select v-model="form.role" class="w-full px-4 py-2 border border-slate-300 rounded-lg">
          <option v-for="role in roles" :key="role" :value="role">{{ role }}</option>
        </select>
        <label class="flex items-center gap-2 text-sm text-slate-700">
          <input v-model="form.is_active" type="checkbox" />
          Active
        </label>
        <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white font-semibold py-2 rounded-lg">Save User</button>
      </form>
    </div>
  </AdminLayout>
</template>
