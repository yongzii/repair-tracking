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
    <aside class="sidebar app-scrollbar flex min-h-screen w-72 shrink-0 flex-col overflow-hidden">
      <div class="border-b border-white/10 p-5">
        <AppLink to="/technician" class="flex items-start gap-3">
          <span class="mt-1 flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white text-sm font-bold text-slate-950">RS</span>
          <span class="min-w-0">
            <span class="block truncate text-xl font-semibold sidebar-title text-white">{{ appConfig.name }}</span>
            <span class="mt-1 block text-sm text-slate-300">Technician Workspace</span>
          </span>
        </AppLink>
      </div>

      <nav class="app-scrollbar mt-5 flex-1 space-y-1 overflow-y-auto pb-6">
        <AppLink to="/technician" class="nav-link" :class="currentPath === '/technician' ? 'nav-link-active' : ''">
          <i class="fas fa-screwdriver-wrench w-5"></i>
          <span>My Jobs</span>
        </AppLink>

        <AppLink to="/track" class="nav-link" :class="currentPath.startsWith('/track') ? 'nav-link-active' : ''">
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
        </div>
        <button type="button" class="flex w-full items-center justify-center rounded-lg bg-white/10 py-2 font-bold text-white hover:bg-white/20" @click="onLogout">
          <i class="fas fa-sign-out-alt mr-2"></i>
          Logout
        </button>
      </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col overflow-hidden">
      <div class="content-header px-6 py-4">
        <div>
          <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-400">Technician</p>
          <h2 class="text-2xl font-bold text-slate-950">{{ title }}</h2>
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
