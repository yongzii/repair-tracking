<script setup>
import { computed, ref } from 'vue';
import { authState, logout } from '../composables/auth';
import { useNavigation, navigate } from '../navigation';
import AppLink from '../components/AppLink.vue';
import ChangePasswordButton from '../components/ChangePasswordButton.vue';

const props = defineProps({
    title: {
        type: String,
        default: '',
    },
});

const { location } = useNavigation();
const currentPath = computed(() => location.path);
const user = computed(() => authState.user);
const mobileNavOpen = ref(false);

const onLogout = async () => {
    await logout();
    navigate('/login', { replace: true });
};
</script>

<template>
  <div class="app-shell flex min-h-screen">
    <button
      v-if="mobileNavOpen"
      type="button"
      class="fixed inset-0 z-40 bg-slate-950/55 backdrop-blur-sm lg:hidden"
      aria-label="Close navigation menu"
      @click="mobileNavOpen = false"
    ></button>
    <aside
      class="sidebar app-scrollbar fixed inset-y-0 left-0 z-50 flex h-screen min-h-screen w-72 shrink-0 flex-col overflow-hidden transition-transform duration-200 lg:sticky lg:z-auto lg:translate-x-0"
      :class="mobileNavOpen ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="border-b border-white/10 p-5">
        <AppLink to="/technician" class="flex items-start gap-3">
          <img src="/images/repairsync-cloud-logo.png" alt="RepairSync Cloud" class="mt-1 h-11 w-40 shrink-0 object-cover object-center brightness-0 invert" />
          <span class="min-w-0">
            <span class="mt-1 block text-sm text-slate-300">Technician Workspace</span>
          </span>
        </AppLink>
      </div>

      <nav id="technician-navigation" class="app-scrollbar mt-5 flex-1 space-y-1 overflow-y-auto pb-6" @click="mobileNavOpen = false">
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
          <ChangePasswordButton />
        </div>
        <button type="button" class="flex w-full items-center justify-center rounded-lg bg-white/10 py-2 font-bold text-white hover:bg-white/20" @click="onLogout">
          <i class="fas fa-sign-out-alt mr-2"></i>
          Logout
        </button>
      </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col overflow-hidden">
      <div class="content-header sticky top-0 z-30 px-4 py-3 sm:px-6 sm:py-4">
        <div class="flex items-center justify-between gap-3">
          <div>
          <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-400">Technician</p>
          <h2 class="text-xl font-bold text-slate-950 sm:text-2xl">{{ title }}</h2>
          </div>
          <button
            type="button"
            class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 shadow-sm hover:border-teal-200 hover:text-teal-700 lg:hidden"
            :aria-expanded="mobileNavOpen"
            aria-controls="technician-navigation"
            :aria-label="mobileNavOpen ? 'Close navigation menu' : 'Open navigation menu'"
            @click="mobileNavOpen = !mobileNavOpen"
          >
            <i :class="mobileNavOpen ? 'fas fa-xmark' : 'fas fa-bars'" aria-hidden="true"></i>
          </button>
        </div>
      </div>

      <div class="app-scrollbar flex-1 overflow-auto">
        <div class="space-y-4 p-4 sm:space-y-5 sm:p-6">
          <slot />
        </div>
      </div>
    </div>
  </div>
</template>
