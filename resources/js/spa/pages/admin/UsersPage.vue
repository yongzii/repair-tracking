<script setup>
import { onMounted, reactive, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AppLink from '../../components/AppLink.vue';
import { ensureAdmin } from '../../composables/auth';
import { navigate } from '../../navigation';

const loading = ref(true);
const forbidden = ref(false);
const users = ref(null);
const roles = ref([]);
const errorMessage = ref('');
const successMessage = ref('');
const generatedPassword = ref('');

const filters = reactive({
    search: '',
    role: '',
    is_active: '',
});

const form = reactive({
    name: '',
    email: '',
    role: 'staff',
    password: '',
    is_active: true,
});

const loadUsers = async (page = 1) => {
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
        const { data } = await window.axios.get('/api/admin/users', {
            params: {
                page,
                search: filters.search || undefined,
                role: filters.role || undefined,
                is_active: filters.is_active === '' ? undefined : filters.is_active,
            },
        });
        users.value = data.users;
        roles.value = data.roles || [];
    } catch (error) {
        forbidden.value = true;
        errorMessage.value = error?.response?.data?.message || 'Unable to load users.';
    } finally {
        loading.value = false;
    }
};

const createUser = async () => {
    errorMessage.value = '';
    successMessage.value = '';
    generatedPassword.value = '';

    try {
        const { data } = await window.axios.post('/api/admin/users', {
            name: form.name,
            email: form.email,
            role: form.role,
            password: form.password || null,
            is_active: form.is_active,
        });

        form.name = '';
        form.email = '';
        form.role = 'staff';
        form.password = '';
        form.is_active = true;

        successMessage.value = data.message || 'User account created successfully.';
        generatedPassword.value = data.generated_password || '';
        await loadUsers();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to create user account.';
    }
};

const toggleUser = async (user) => {
    errorMessage.value = '';
    successMessage.value = '';

    try {
        const endpoint = user.is_active ? 'deactivate' : 'activate';
        const { data } = await window.axios.post(`/api/admin/users/${user.id}/${endpoint}`);
        successMessage.value = data.message || 'User account updated.';
        await loadUsers();
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Unable to update account status.';
    }
};

const resetPassword = async (user) => {
    if (!window.confirm(`Reset password for ${user.email}?`)) return;

    errorMessage.value = '';
    successMessage.value = '';
    generatedPassword.value = '';

    try {
        const { data } = await window.axios.post(`/api/admin/users/${user.id}/reset-password`);
        successMessage.value = data.message || 'Password reset successfully.';
        generatedPassword.value = data.temporary_password || '';
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Unable to reset password.';
    }
};

const clearFilters = () => {
    filters.search = '';
    filters.role = '';
    filters.is_active = '';
    loadUsers(1);
};

const gotoPage = (url) => {
    if (!url) return;
    const parsed = new URL(url, window.location.origin);
    const page = Number(new URLSearchParams(parsed.search).get('page') || 1);
    loadUsers(page);
};

onMounted(() => loadUsers());
</script>

<template>
  <AdminLayout title="User Management">
    <div v-if="loading" class="card p-6 text-slate-600">Loading users...</div>
    <div v-else-if="forbidden" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">{{ errorMessage || 'You do not have access to this page.' }}</div>

    <div v-else class="space-y-6">
      <div v-if="successMessage" class="p-3 rounded border border-emerald-200 bg-emerald-50 text-emerald-700">{{ successMessage }}</div>
      <div v-if="errorMessage" class="p-3 rounded border border-rose-200 bg-rose-50 text-rose-700">{{ errorMessage }}</div>
      <div v-if="generatedPassword" class="p-3 rounded border border-amber-200 bg-amber-50 text-amber-800">
        Temporary password: <span class="font-mono font-semibold">{{ generatedPassword }}</span>
      </div>

      <div class="card p-6">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-semibold text-slate-900">Create User Account</h3>
        </div>
        <p class="text-sm text-slate-500 mb-4">
          For technician accounts, use the same email address as the technician record in the roster so the workspace can match assigned jobs correctly.
        </p>

        <form class="grid grid-cols-1 md:grid-cols-5 gap-3" @submit.prevent="createUser">
          <input v-model="form.name" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Full name" required />
          <input v-model="form.email" type="email" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Email" required />
          <select v-model="form.role" class="px-4 py-2 border border-slate-300 rounded-lg">
            <option v-for="role in roles" :key="role" :value="role">{{ role }}</option>
          </select>
          <input v-model="form.password" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Temporary password (optional)" />
          <label class="flex items-center gap-2 px-4 py-2 border border-slate-300 rounded-lg text-sm text-slate-700">
            <input v-model="form.is_active" type="checkbox" />
            Active
          </label>
          <button type="submit" class="md:col-span-5 bg-slate-900 hover:bg-slate-800 text-white font-semibold py-2 rounded-lg">Create User</button>
        </form>
      </div>

      <div class="card p-5">
        <form class="grid grid-cols-1 md:grid-cols-4 gap-3" @submit.prevent="loadUsers(1)">
          <input v-model="filters.search" class="md:col-span-2 px-4 py-2 border border-slate-300 rounded-lg" placeholder="Search name, email, role..." />
          <select v-model="filters.role" class="px-4 py-2 border border-slate-300 rounded-lg">
            <option value="">All roles</option>
            <option v-for="role in roles" :key="role" :value="role">{{ role }}</option>
          </select>
          <select v-model="filters.is_active" class="px-4 py-2 border border-slate-300 rounded-lg">
            <option value="">All statuses</option>
            <option value="1">Active</option>
            <option value="0">Inactive</option>
          </select>
          <div class="md:col-span-4 flex justify-end gap-3">
            <button type="button" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-100 font-semibold" @click="clearFilters">Clear</button>
            <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-800 font-semibold">Apply Filters</button>
          </div>
        </form>
      </div>

      <div class="card overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-slate-100 border-b">
            <tr>
              <th class="px-4 py-3 text-left text-slate-600">Name</th>
              <th class="px-4 py-3 text-left text-slate-600">Email</th>
              <th class="px-4 py-3 text-left text-slate-600">Role</th>
              <th class="px-4 py-3 text-left text-slate-600">Status</th>
              <th class="px-4 py-3 text-left text-slate-600">Last Login</th>
              <th class="px-4 py-3 text-left text-slate-600">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="user in users.data" :key="user.id">
              <td class="px-4 py-3">
                <p class="font-semibold text-slate-900">{{ user.name }}</p>
                <p class="text-xs text-slate-500">Created {{ user.created_at_formatted }}</p>
              </td>
              <td class="px-4 py-3 text-slate-700">{{ user.email }}</td>
              <td class="px-4 py-3 text-slate-700 uppercase">{{ user.role }}</td>
              <td class="px-4 py-3">
                <span class="text-xs font-semibold" :class="user.is_active ? 'text-emerald-700' : 'text-rose-700'">
                  {{ user.is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td class="px-4 py-3 text-slate-700">{{ user.last_login_at_formatted || 'Never' }}</td>
              <td class="px-4 py-3">
                <div class="flex flex-wrap gap-3">
                  <AppLink :to="`/admin/users/${user.id}/edit`" class="text-slate-900 font-semibold">Edit</AppLink>
                  <button type="button" class="text-indigo-700 font-semibold" @click="resetPassword(user)">Reset Password</button>
                  <button type="button" class="font-semibold" :class="user.is_active ? 'text-rose-600' : 'text-emerald-700'" @click="toggleUser(user)">
                    {{ user.is_active ? 'Deactivate' : 'Activate' }}
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!users.data.length">
              <td colspan="6" class="px-4 py-8 text-center text-slate-500">No user accounts found.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="flex flex-wrap gap-2">
        <button
          v-for="link in users.links"
          :key="link.label + String(link.url)"
          type="button"
          class="px-3 py-2 rounded text-sm"
          :class="link.active ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 disabled:text-slate-300'"
          :disabled="!link.url"
          @click="gotoPage(link.url)"
          v-html="link.label"
        ></button>
      </div>
    </div>
  </AdminLayout>
</template>
