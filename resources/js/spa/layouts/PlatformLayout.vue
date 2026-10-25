<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
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
const isMobile = ref(false);
const drawer = ref(null);
const menuButton = ref(null);
let mobileMediaQuery;

const syncViewport = () => {
    isMobile.value = mobileMediaQuery.matches;
    if (!isMobile.value) {
        mobileNavOpen.value = false;
    }
};

const onDrawerKeydown = (event) => {
    if (event.key === 'Escape') {
        mobileNavOpen.value = false;
        return;
    }

    if (event.key !== 'Tab' || !isMobile.value) return;

    const focusable = drawer.value?.querySelectorAll('a[href], button:not([disabled])');
    if (!focusable?.length) return;

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
};

onMounted(() => {
    mobileMediaQuery = window.matchMedia('(max-width: 1023px)');
    syncViewport();
    mobileMediaQuery.addEventListener('change', syncViewport);
});

onBeforeUnmount(() => {
    mobileMediaQuery?.removeEventListener('change', syncViewport);
});

watch(mobileNavOpen, async (open) => {
    if (open && isMobile.value) {
        await nextTick();
        drawer.value?.querySelector('a[href], button:not([disabled])')?.focus();
    } else if (!open && isMobile.value) {
        menuButton.value?.focus();
    }
});

const onLogout = async () => {
    mobileNavOpen.value = false;
    await logout();
    navigate('/login', { replace: true });
};
</script>

<template>
  <div class="platform-shell flex min-h-screen">
    <button
      v-if="mobileNavOpen"
      type="button"
      class="fixed inset-0 z-40 bg-slate-950/55 backdrop-blur-sm lg:hidden"
      aria-label="Close navigation menu"
      @click="mobileNavOpen = false"
    ></button>
    <aside
      ref="drawer"
      id="platform-navigation"
      class="platform-sidebar app-scrollbar fixed inset-y-0 left-0 z-50 flex h-[100dvh] min-h-screen w-72 max-w-[calc(100vw-2rem)] shrink-0 flex-col overflow-hidden transition-transform duration-200 lg:sticky lg:top-0 lg:z-auto lg:h-screen lg:w-64 lg:max-w-none lg:translate-x-0"
      :class="mobileNavOpen ? 'translate-x-0' : '-translate-x-full'"
      :role="isMobile ? 'dialog' : undefined"
      :aria-label="isMobile ? 'Platform navigation' : undefined"
      :aria-modal="isMobile && mobileNavOpen ? 'true' : undefined"
      :aria-hidden="isMobile && !mobileNavOpen ? 'true' : undefined"
      :inert="isMobile && !mobileNavOpen"
      @keydown="onDrawerKeydown"
    >
      <div class="flex items-center justify-between gap-3 border-b border-white/10 p-5">
        <AppLink to="/platform" class="flex items-start gap-3">
          <img src="/images/repairsync-cloud-logo.png" alt="RepairSync Cloud" class="mt-1 h-11 w-40 shrink-0 object-cover object-center brightness-0 invert" />
          <span class="min-w-0">
            <span class="mt-1 block text-sm text-slate-300">Platform Admin</span>
          </span>
        </AppLink>
        <button
          type="button"
          class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-white/10 bg-white/5 text-white hover:bg-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-300 lg:hidden"
          aria-label="Close navigation menu"
          @click="mobileNavOpen = false"
        >
          <i class="fas fa-xmark" aria-hidden="true"></i>
        </button>
      </div>

      <nav class="app-scrollbar mt-5 flex-1 space-y-1 overflow-y-auto pb-6" @click="mobileNavOpen = false">
        <AppLink to="/platform" class="platform-nav-link" :class="currentPath === '/platform' ? 'platform-nav-link-active' : ''">
          <i class="fas fa-layer-group w-5"></i>
          <span>Dashboard</span>
        </AppLink>

        <AppLink to="/platform/shops" class="platform-nav-link" :class="currentPath.startsWith('/platform/shops') ? 'platform-nav-link-active' : ''">
          <i class="fas fa-store w-5"></i>
          <span>Shops</span>
        </AppLink>

        <AppLink to="/platform/subscription-requests" class="platform-nav-link" :class="currentPath === '/platform/subscription-requests' ? 'platform-nav-link-active' : ''">
          <i class="fas fa-file-invoice-dollar w-5"></i>
          <span>Subscription Requests</span>
        </AppLink>

        <AppLink to="/platform/failed-jobs" class="platform-nav-link" :class="currentPath === '/platform/failed-jobs' ? 'platform-nav-link-active' : ''">
          <i class="fas fa-triangle-exclamation w-5"></i>
          <span>Failed Jobs</span>
        </AppLink>

        <AppLink to="/platform/audit-logs" class="platform-nav-link" :class="currentPath === '/platform/audit-logs' ? 'platform-nav-link-active' : ''">
          <i class="fas fa-clipboard-list w-5"></i>
          <span>Audit Logs</span>
        </AppLink>

        <AppLink to="/platform/settings" class="platform-nav-link" :class="currentPath === '/platform/settings' ? 'platform-nav-link-active' : ''">
          <i class="fas fa-gear w-5"></i>
          <span>Platform Settings</span>
        </AppLink>
      </nav>

      <div class="mt-auto border-t border-white/10 bg-black/20 p-5">
        <div class="mb-4 rounded-xl border border-white/10 bg-white/5 p-3" v-if="user">
          <p class="text-xs text-slate-300">Logged in as</p>
          <p class="mt-1 truncate text-sm font-bold text-white">{{ user.name }}</p>
          <p class="truncate text-xs text-slate-300">{{ user.email }}</p>
          <p class="mt-2 inline-flex rounded-full bg-amber-400/15 px-2 py-1 text-xs font-bold uppercase text-amber-200">{{ user.role }}</p>
          <ChangePasswordButton />
        </div>
        <button type="button" class="flex min-h-11 w-full items-center justify-center rounded-lg bg-white/10 py-2 font-bold text-white hover:bg-white/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-300" @click="onLogout">
          <i class="fas fa-sign-out-alt mr-2"></i>
          Logout
        </button>
      </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col overflow-hidden" :inert="isMobile && mobileNavOpen">
      <header class="platform-header sticky top-0 z-30 px-4 py-3 sm:px-6 sm:py-4">
        <div class="flex min-w-0 items-center justify-between gap-3">
          <div class="flex min-w-0 items-center gap-3">
            <button
              ref="menuButton"
              type="button"
              class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 shadow-sm hover:border-teal-200 hover:text-teal-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 lg:hidden"
              :aria-expanded="mobileNavOpen"
              aria-controls="platform-navigation"
              :aria-label="mobileNavOpen ? 'Close navigation menu' : 'Open navigation menu'"
              @click="mobileNavOpen = !mobileNavOpen"
            >
              <i :class="mobileNavOpen ? 'fas fa-xmark' : 'fas fa-bars'" aria-hidden="true"></i>
            </button>
            <div class="min-w-0">
              <p class="hidden text-xs font-bold uppercase tracking-[0.2em] text-slate-400 sm:block">Platform Admin</p>
              <div class="flex items-center gap-2 sm:hidden">
                <img src="/images/repairsync-cloud-logo.png" alt="RepairSync Cloud" class="h-8 w-32 object-cover object-center" />
              </div>
              <h2 class="truncate text-lg font-bold text-slate-950 sm:text-2xl">{{ title }}</h2>
            </div>
          </div>
          <div v-if="user" class="flex min-w-0 items-center gap-2 rounded-xl border border-slate-200 bg-white px-2.5 py-2 shadow-sm sm:gap-3 sm:px-3">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-teal-50 text-xs font-bold uppercase text-teal-800 ring-1 ring-teal-100">
              {{ (user.name || 'A').slice(0, 1) }}
            </span>
            <span class="hidden min-w-0 sm:block">
              <span class="block max-w-40 truncate text-xs font-bold text-slate-900">{{ user.name }}</span>
              <span class="block text-[11px] font-semibold text-teal-700">Platform Admin</span>
            </span>
          </div>
        </div>
      </header>

      <main class="platform-main app-scrollbar min-w-0 flex-1 overflow-auto">
        <div class="platform-content mx-auto w-full max-w-[1600px] space-y-5 p-4 sm:p-6 lg:p-8">
          <slot />
        </div>
      </main>
    </div>
  </div>
</template>
