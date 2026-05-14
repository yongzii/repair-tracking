<script setup>
import { computed } from 'vue';
import { authState, logout } from '../composables/auth';
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
const currentPath = computed(() => location.path);
const user = computed(() => authState.user);

const onLogout = async () => {
    await logout();
    navigate('/login', { replace: true });
};
</script>

<template>
  <div class="app-shell flex min-h-screen">
    <aside class="sidebar flex min-h-screen w-72 flex-col overflow-hidden">
      <div class="p-6 border-b border-white/10">
        <h1 class="text-2xl font-semibold sidebar-title text-white">{{ appConfig.name }}</h1>
        <p class="text-blue-100 text-sm mt-1">Technician Workspace</p>
      </div>

      <nav class="mt-6 flex-1 space-y-1 overflow-y-auto pb-6">
        <AppLink to="/technician" class="nav-link" :class="currentPath === '/technician' ? 'nav-link-active' : ''">
          <i class="fas fa-screwdriver-wrench w-5"></i>
          <span>My Jobs</span>
        </AppLink>

        <AppLink to="/track" class="nav-link" :class="currentPath.startsWith('/track') ? 'nav-link-active' : ''">
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
        </div>
        <button type="button" class="w-full bg-white/10 hover:bg-white/20 text-white font-semibold py-2 rounded-lg transition flex items-center justify-center" @click="onLogout">
          <i class="fas fa-sign-out-alt mr-2"></i>
          Logout
        </button>
      </div>
    </aside>

    <div class="flex-1 flex flex-col overflow-hidden">
      <div class="content-header px-6 py-4">
        <div>
          <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Technician</p>
          <h2 class="text-2xl font-semibold text-slate-900">{{ title }}</h2>
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
