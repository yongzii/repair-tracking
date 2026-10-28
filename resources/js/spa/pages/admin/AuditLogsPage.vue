<script setup>
import { onMounted, reactive, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import { ensureAdmin } from '../../composables/auth';
import { navigate } from '../../navigation';

const loading = ref(true);
const forbidden = ref(false);
const logs = ref(null);
const options = ref({
    roles: [],
    modules: [],
    actions: [],
});

const filters = reactive({
    search: '',
    module: '',
    action: '',
    role: '',
    start_date: '',
    end_date: '',
});

const loadLogs = async (page = 1) => {
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

    loading.value = true;
    try {
        const { data } = await window.axios.get('/api/admin/audit-logs', {
            params: {
                page,
                search: filters.search || undefined,
                module: filters.module || undefined,
                action: filters.action || undefined,
                role: filters.role || undefined,
                start_date: filters.start_date || undefined,
                end_date: filters.end_date || undefined,
            },
        });
        logs.value = data.logs;
        options.value = data.filters;
    } catch {
        forbidden.value = true;
    } finally {
        loading.value = false;
    }
};

const gotoPage = (url) => {
    if (!url) return;
    const parsed = new URL(url, window.location.origin);
    const page = Number(new URLSearchParams(parsed.search).get('page') || 1);
    loadLogs(page);
};

const clearFilters = () => {
    filters.search = '';
    filters.module = '';
    filters.action = '';
    filters.role = '';
    filters.start_date = '';
    filters.end_date = '';
    loadLogs(1);
};

onMounted(() => loadLogs());
</script>

<template>
  <AdminLayout title="Audit Logs">
    <div v-if="loading" class="card p-6 text-slate-600">Loading audit logs...</div>
    <div v-else-if="forbidden" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">You do not have access to this page.</div>

    <div v-else class="space-y-6">
      <section class="admin-hero p-6">
        <p class="text-xs font-bold uppercase text-teal-700">System accountability</p>
        <h3 class="mt-2 font-display text-3xl">Audit Logs</h3>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Review important user and system activities across the repair workspace.</p>
      </section>

      <div class="admin-panel p-6">
        <form class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-3" @submit.prevent="loadLogs(1)">
          <input v-model="filters.search" class="lg:col-span-2 border border-slate-300 px-4 py-3" placeholder="Search user, module, action..." />
          <select v-model="filters.module" class="border border-slate-300 px-4 py-3">
            <option value="">All modules</option>
            <option v-for="module in options.modules" :key="module" :value="module">{{ module }}</option>
          </select>
          <select v-model="filters.action" class="border border-slate-300 px-4 py-3">
            <option value="">All actions</option>
            <option v-for="action in options.actions" :key="action" :value="action">{{ action }}</option>
          </select>
          <select v-model="filters.role" class="border border-slate-300 px-4 py-3">
            <option value="">All roles</option>
            <option v-for="role in options.roles" :key="role" :value="role">{{ role }}</option>
          </select>
          <input v-model="filters.start_date" type="date" class="border border-slate-300 px-4 py-3" />
          <input v-model="filters.end_date" type="date" class="border border-slate-300 px-4 py-3" />
          <div class="md:col-span-3 lg:col-span-6 flex justify-end gap-3">
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
              <th class="px-4 py-3 text-left text-slate-600">When</th>
              <th class="px-4 py-3 text-left text-slate-600">User</th>
              <th class="px-4 py-3 text-left text-slate-600">Module</th>
              <th class="px-4 py-3 text-left text-slate-600">Action</th>
              <th class="px-4 py-3 text-left text-slate-600">Description</th>
              <th class="px-4 py-3 text-left text-slate-600">IP</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="log in logs.data" :key="log.id">
              <td class="px-4 py-3 text-slate-700">{{ log.created_at_formatted }}</td>
              <td class="px-4 py-3">
                <p class="font-semibold text-slate-900">{{ log.user_name || 'System' }}</p>
                <p class="text-xs uppercase text-slate-500">{{ log.user_role || 'n/a' }}</p>
              </td>
              <td class="px-4 py-3 text-slate-700">{{ log.module }}</td>
              <td class="px-4 py-3 text-slate-700">{{ log.action }}</td>
              <td class="px-4 py-3 text-slate-700">{{ log.description || 'No description' }}</td>
              <td class="px-4 py-3 text-slate-500">{{ log.ip_address || 'n/a' }}</td>
            </tr>
            <tr v-if="!logs.data.length">
              <td colspan="6" class="px-4 py-8 text-center text-slate-500">No audit log entries found.</td>
            </tr>
          </tbody>
        </table>
        </div>
      </div>

      <div class="flex flex-wrap gap-2">
        <button
          v-for="link in logs.links"
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
