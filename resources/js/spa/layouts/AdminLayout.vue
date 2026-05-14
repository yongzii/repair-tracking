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
    <aside class="sidebar flex min-h-screen w-72 flex-col overflow-hidden">
      <div class="p-6 border-b border-white/10">
        <h1 class="text-2xl font-semibold sidebar-title text-white">{{ shopName }}</h1>
        <p class="text-blue-100 text-sm mt-1">{{ appConfig.tagline }}</p>
        <p v-if="shopSlug" class="text-blue-200 text-xs mt-2 uppercase tracking-[0.15em]">Shop Code: {{ shopSlug }}</p>
      </div>

      <nav class="mt-6 flex-1 space-y-1 overflow-y-auto pb-6">
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

      <div class="mt-auto border-t border-white/10 bg-black/20 p-6">
        <div class="mb-4" v-if="user">
          <p class="text-blue-100 text-xs">Logged in as</p>
          <p class="text-white font-semibold text-sm">{{ user.name }}</p>
          <p class="text-blue-200 text-xs">{{ user.email }}</p>
          <p class="text-blue-200 text-xs uppercase mt-1">{{ user.role }}</p>
          <p v-if="user.shop?.name" class="text-blue-200 text-xs mt-1">{{ user.shop.name }}</p>
        </div>
        <button type="button" class="w-full bg-white/10 hover:bg-white/20 text-white font-semibold py-2 rounded-lg transition flex items-center justify-center" @click="onLogout">
          <i class="fas fa-sign-out-alt mr-2"></i>
          Logout
        </button>
      </div>
    </aside>

    <div class="flex-1 flex flex-col overflow-hidden">
      <div class="content-header px-6 py-4">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Operations</p>
            <h2 class="text-2xl font-semibold text-slate-900">{{ title }}</h2>
          </div>
        </div>
      </div>

      <div class="flex-1 overflow-auto">
        <div class="p-6 space-y-4">
          <slot />
        </div>
      </div>
    </div>
  </div>
</template>
