<script setup>
import { computed } from 'vue';
import { adminRoles, authState, logout } from '../composables/auth';
import { useNavigation, navigate } from '../navigation';
import AppLink from '../components/AppLink.vue';
import { appConfig } from '../config';

const props = defineProps({
    title: {
        type: String,
        default: '',
    },
});

const { location } = useNavigation();

const normalizePath = (path) => {
    if (!path) return '/';
    if (path.length > 1 && path.endsWith('/')) return path.slice(0, -1);
    return path;
};

const currentPath = computed(() => normalizePath(location.path));

const isExactActive = (path) => currentPath.value === normalizePath(path);
const isSectionActive = (path) => {
    const normalized = normalizePath(path);
    return currentPath.value === normalized || currentPath.value.startsWith(`${normalized}/`);
};

const user = computed(() => authState.user);
const isAdmin = computed(() => adminRoles.includes(user.value?.role));
const canViewInventory = computed(() => [...adminRoles, 'staff'].includes(user.value?.role));
const shopName = computed(() => user.value?.shop?.name || appConfig.name);
const shopSlug = computed(() => user.value?.shop?.slug || '');
const trackingPath = computed(() => shopSlug.value ? `/shops/${shopSlug.value}/track` : '/track');

const onLogout = async () => {
    await logout();
    navigate('/login', { replace: true });
};
</script>

<template>
  <div class="app-shell flex min-h-screen">
    <aside class="sidebar app-scrollbar flex min-h-screen w-72 shrink-0 flex-col overflow-hidden">
      <div class="border-b border-white/10 p-5">
        <AppLink to="/admin" class="flex items-start gap-3">
          <span class="mt-1 flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white text-sm font-bold text-slate-950">RS</span>
          <span class="min-w-0">
            <span class="block truncate text-xl font-semibold sidebar-title text-white">{{ shopName }}</span>
            <span class="mt-1 block text-sm text-slate-300">{{ appConfig.tagline }}</span>
          </span>
        </AppLink>
        <p v-if="shopSlug" class="mt-4 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs font-bold uppercase tracking-[0.15em] text-teal-200">Shop Code: {{ shopSlug }}</p>
      </div>

      <nav class="app-scrollbar mt-5 flex-1 space-y-1 overflow-y-auto pb-6">
        <AppLink to="/admin" class="nav-link" :class="isExactActive('/admin') ? 'nav-link-active' : ''">
          <i class="fas fa-chart-line w-5"></i>
          <span>Dashboard</span>
        </AppLink>

        <AppLink to="/admin/job-orders" class="nav-link" :class="isSectionActive('/admin/job-orders') ? 'nav-link-active' : ''">
          <i class="fas fa-briefcase w-5"></i>
          <span>Job Orders</span>
        </AppLink>

        <AppLink to="/admin/part-requests" class="nav-link" :class="isSectionActive('/admin/part-requests') ? 'nav-link-active' : ''">
          <i class="fas fa-screwdriver-wrench w-5"></i>
          <span>Part Requests</span>
        </AppLink>

        <AppLink to="/admin/appointments" class="nav-link" :class="isSectionActive('/admin/appointments') ? 'nav-link-active' : ''">
          <i class="fas fa-calendar-check w-5"></i>
          <span>Customer Requests</span>
        </AppLink>

        <AppLink v-if="isAdmin" to="/admin/reports" class="nav-link" :class="isSectionActive('/admin/reports') ? 'nav-link-active' : ''">
          <i class="fas fa-file-chart-bar w-5"></i>
          <span>Reports</span>
        </AppLink>

        <AppLink v-if="isAdmin" to="/admin/failed-jobs" class="nav-link" :class="isSectionActive('/admin/failed-jobs') ? 'nav-link-active' : ''">
          <i class="fas fa-triangle-exclamation w-5"></i>
          <span>Failed Jobs</span>
        </AppLink>

        <AppLink v-if="canViewInventory" to="/admin/inventory" class="nav-link" :class="isSectionActive('/admin/inventory') ? 'nav-link-active' : ''">
          <i class="fas fa-boxes-stacked w-5"></i>
          <span>Inventory</span>
        </AppLink>

        <AppLink v-if="isAdmin" to="/admin/technicians" class="nav-link" :class="isSectionActive('/admin/technicians') ? 'nav-link-active' : ''">
          <i class="fas fa-users w-5"></i>
          <span>Technicians</span>
        </AppLink>

        <AppLink v-if="isAdmin" to="/admin/users" class="nav-link" :class="isSectionActive('/admin/users') ? 'nav-link-active' : ''">
          <i class="fas fa-user-shield w-5"></i>
          <span>User Management</span>
        </AppLink>

        <AppLink v-if="isAdmin" to="/admin/audit-logs" class="nav-link" :class="isSectionActive('/admin/audit-logs') ? 'nav-link-active' : ''">
          <i class="fas fa-clipboard-list w-5"></i>
          <span>Audit Logs</span>
        </AppLink>

        <AppLink v-if="isAdmin" to="/admin/settings/shop" class="nav-link" :class="isSectionActive('/admin/settings/shop') ? 'nav-link-active' : ''">
          <i class="fas fa-store w-5"></i>
          <span>Shop Settings</span>
        </AppLink>

        <AppLink :to="trackingPath" class="nav-link" :class="isSectionActive('/track') || isSectionActive('/shops') ? 'nav-link-active' : ''">
          <i class="fas fa-eye w-5"></i>
          <span>Customer Tracking</span>
        </AppLink>
      </nav>

      <div class="mt-auto border-t border-white/10 bg-black/20 p-5">
        <div class="mb-4 rounded-xl border border-white/10 bg-white/5 p-3" v-if="user">
          <p class="text-xs text-slate-300">Logged in as</p>
          <p class="mt-1 truncate text-sm font-bold text-white">{{ user.name }}</p>
          <p class="truncate text-xs text-slate-300">{{ user.email }}</p>
          <p class="mt-2 inline-flex rounded-full bg-teal-400/15 px-2 py-1 text-xs font-bold uppercase text-teal-200">{{ user.role }}</p>
          <p v-if="user.shop?.name" class="mt-2 truncate text-xs text-slate-300">{{ user.shop.name }}</p>
        </div>
        <button type="button" class="flex w-full items-center justify-center rounded-lg bg-white/10 py-2 font-bold text-white hover:bg-white/20" @click="onLogout">
          <i class="fas fa-sign-out-alt mr-2"></i>
          Logout
        </button>
      </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col overflow-hidden">
      <div class="content-header px-6 py-4">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-400">Operations</p>
            <h2 class="text-2xl font-bold text-slate-950">{{ title }}</h2>
          </div>
        </div>
      </div>

      <div class="app-scrollbar flex-1 overflow-auto">
        <div class="space-y-4 p-6">
          <slot />
        </div>
      </div>
    </div>
  </div>
</template>
