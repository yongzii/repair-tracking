<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AppLink from '../../components/AppLink.vue';
import { adminRoles, ensureAdmin } from '../../composables/auth';
import { navigate } from '../../navigation';

const loading = ref(true);
const forbidden = ref(false);
const users = ref(null);
const roles = ref([]);
const technicians = ref([]);
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
    username: '',
    role: 'staff',
    technician_id: '',
    password: '',
    is_active: true,
});

watch(() => form.role, (role) => {
    if (role !== 'technician') {
        form.technician_id = '';
    }
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
        technicians.value = data.technicians || [];
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
            email: form.email || null,
            username: form.username || null,
            role: form.role,
            technician_id: form.technician_id || null,
            password: form.password || null,
            is_active: form.is_active,
        });

        form.name = '';
        form.email = '';
        form.username = '';
        form.role = 'staff';
        form.technician_id = '';
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
    if (!window.confirm(`Reset password for ${user.username || user.email}?`)) return;

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

const removeUser = async (user) => {
    if (!window.confirm(`Permanently remove ${user.name} (${user.username || user.email})? This cannot be undone.`)) return;

    errorMessage.value = '';
    successMessage.value = '';

    try {
        const { data } = await window.axios.delete(`/api/admin/users/${user.id}`);
        successMessage.value = data.message || 'User account removed successfully.';
        await loadUsers(users.value?.current_page || 1);
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Unable to remove user account.';
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
      <section class="admin-hero p-6">
        <p class="text-xs font-bold uppercase text-teal-300">Access control</p>
        <h3 class="mt-2 font-display text-3xl">User Management</h3>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">Create accounts, manage access, reset passwords, and remove user accounts.</p>
      </section>

      <div v-if="successMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700">{{ successMessage }}</div>
      <div v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">{{ errorMessage }}</div>
      <div v-if="generatedPassword" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-800">
        Temporary password: <span class="font-mono font-semibold">{{ generatedPassword }}</span>
      </div>

      <div class="admin-panel p-6">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-bold text-slate-950">Create User Account</h3>
        </div>
        <p class="text-sm text-slate-500 mb-4">Staff and Technician accounts sign in with a unique username. Link technician accounts to an existing technician profile.</p>

        <form class="grid grid-cols-1 md:grid-cols-3 gap-3" @submit.prevent="createUser">
          <input v-model="form.name" class="border border-slate-300 px-4 py-3" placeholder="Full name" required />
          <input v-model="form.username" class="border border-slate-300 px-4 py-3" placeholder="Username" :required="!adminRoles.includes(form.role)" />
          <input v-model="form.email" type="email" class="border border-slate-300 px-4 py-3" :placeholder="adminRoles.includes(form.role) ? 'Email (required)' : 'Email (optional)'" :required="adminRoles.includes(form.role)" />
          <select v-model="form.role" class="border border-slate-300 px-4 py-3">
            <option v-for="role in roles" :key="role" :value="role">{{ role }}</option>
          </select>
          <select v-if="form.role === 'technician'" v-model="form.technician_id" class="border border-slate-300 px-4 py-3" required>
            <option value="" disabled>Select technician profile</option>
            <option v-for="technician in technicians" :key="technician.id" :value="technician.id">{{ technician.name }} ({{ technician.email }})</option>
          </select>
          <input v-model="form.password" type="password" autocomplete="new-password" class="border border-slate-300 px-4 py-3" placeholder="Temporary password (optional)" />
          <label class="flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-3 text-sm font-semibold text-slate-700">
            <input v-model="form.is_active" type="checkbox" />
            Active
          </label>
          <button type="submit" class="btn-primary md:col-span-3 py-3">Create User</button>
        </form>
      </div>

      <div class="admin-panel p-5">
        <form class="grid grid-cols-1 md:grid-cols-4 gap-3" @submit.prevent="loadUsers(1)">
          <input v-model="filters.search" class="md:col-span-2 border border-slate-300 px-4 py-3" placeholder="Search name, email, username, role..." />
          <select v-model="filters.role" class="border border-slate-300 px-4 py-3">
            <option value="">All roles</option>
            <option v-for="role in roles" :key="role" :value="role">{{ role }}</option>
          </select>
          <select v-model="filters.is_active" class="border border-slate-300 px-4 py-3">
            <option value="">All statuses</option>
            <option value="1">Active</option>
            <option value="0">Inactive</option>
          </select>
          <div class="md:col-span-4 flex justify-end gap-3">
            <button type="button" class="btn-soft px-4 py-2" @click="clearFilters">Clear</button>
            <button type="submit" class="btn-primary px-4 py-2">Apply Filters</button>
          </div>
        </form>
      </div>

      <div class="admin-panel overflow-hidden">
        <div class="overflow-x-auto">
        <table class="data-table">
          <thead>
            <tr>
              <th class="px-4 py-3 text-left text-slate-600">Name</th>
              <th class="px-4 py-3 text-left text-slate-600">Username</th>
              <th class="px-4 py-3 text-left text-slate-600">Email</th>
              <th class="px-4 py-3 text-left text-slate-600">Role</th>
              <th class="px-4 py-3 text-left text-slate-600">Status</th>
              <th class="px-4 py-3 text-left text-slate-600">Last Login</th>
              <th class="px-4 py-3 text-left text-slate-600">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="user in users.data" :key="user.id">
              <td class="px-4 py-3">
                <p class="font-semibold text-slate-900">{{ user.name }}</p>
                <p class="text-xs text-slate-500">Created {{ user.created_at_formatted }}</p>
              </td>
              <td class="px-4 py-3 text-slate-700">{{ user.username || '—' }}</td>
              <td class="px-4 py-3 text-slate-700">{{ user.email || '—' }}</td>
              <td class="px-4 py-3 text-slate-700 uppercase">{{ user.role }}</td>
              <td class="px-4 py-3">
                <span class="rounded-full px-3 py-1 text-xs font-bold ring-1" :class="user.is_active ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-rose-50 text-rose-700 ring-rose-200'">
                  {{ user.is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td class="px-4 py-3 text-slate-700">{{ user.last_login_at_formatted || 'Never' }}</td>
              <td class="px-4 py-3">
                <div class="flex flex-wrap gap-2">
                  <AppLink :to="`/admin/users/${user.id}/edit`" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700 hover:border-teal-200 hover:text-teal-700">Edit</AppLink>
                  <button type="button" class="rounded-lg border border-indigo-200 px-3 py-1.5 text-xs font-bold text-indigo-700 hover:bg-indigo-50" @click="resetPassword(user)">Reset Password</button>
                  <button type="button" class="rounded-lg border px-3 py-1.5 text-xs font-bold" :class="user.is_active ? 'border-rose-200 text-rose-600 hover:bg-rose-50' : 'border-emerald-200 text-emerald-700 hover:bg-emerald-50'" @click="toggleUser(user)">
                    {{ user.is_active ? 'Deactivate' : 'Activate' }}
                  </button>
                  <button type="button" class="rounded-lg border border-rose-300 px-3 py-1.5 text-xs font-bold text-rose-700 hover:bg-rose-50" @click="removeUser(user)">Remove</button>
                </div>
              </td>
            </tr>
            <tr v-if="!users.data.length">
              <td colspan="7" class="px-4 py-8 text-center text-slate-500">No user accounts found.</td>
            </tr>
          </tbody>
        </table>
        </div>
      </div>

      <div class="flex flex-wrap gap-2">
        <button
          v-for="link in users.links"
          :key="link.label + String(link.url)"
          type="button"
          class="rounded-lg px-3 py-2 text-sm font-bold"
          :class="link.active ? 'bg-slate-950 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 disabled:text-slate-300'"
          :disabled="!link.url"
          @click="gotoPage(link.url)"
          v-html="link.label"
        ></button>
      </div>
    </div>
  </AdminLayout>
</template>
