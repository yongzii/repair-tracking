<script setup>
import { computed, ref } from 'vue';
import { adminRoles, authState, platformRoles } from '../composables/auth';
import AppLink from '../components/AppLink.vue';
import PublicShopDirectory from '../components/PublicShopDirectory.vue';
import { appConfig } from '../config';

const mobileMenuOpen = ref(false);
const canOpenPortal = computed(() => [...platformRoles, ...adminRoles, 'staff'].includes(authState.user?.role));
const technicianPortal = computed(() => authState.user?.role === 'technician');
const portalPath = computed(() => authState.user?.role === 'platform_admin' ? '/platform' : '/admin');

const serviceSteps = ['Received', 'Diagnosing', 'In progress', 'Ready for pickup'];
const workflowCards = [
    {
        number: '01',
        title: 'Manage repairs',
        text: 'Create job orders, follow each repair, and keep the details your team needs together.',
        icon: 'clipboard',
    },
    {
        number: '02',
        title: 'Keep technicians moving',
        text: 'Assign work, record progress, handle parts requests, and attach repair photos.',
        icon: 'tools',
    },
    {
        number: '03',
        title: 'Know your numbers',
        text: 'Keep payment records, inventory movements, and repair reports in view.',
        icon: 'chart',
    },
    {
        number: '04',
        title: 'Keep customers informed',
        text: 'Offer customers a simple way to check repair progress and request a consultation.',
        icon: 'people',
    },
];

const platformFeatures = [
    'Job orders and repair workflow',
    'Technician assignments and requests',
    'Inventory and stock movements',
    'Payment records and invoices',
    'Customer tracking and appointments',
    'Reports and repair history',
];

const closeMobileMenu = () => {
    mobileMenuOpen.value = false;
};
</script>

<template>
  <main class="landing-page overflow-hidden bg-white text-slate-900">
    <section class="landing-hero relative isolate overflow-hidden text-white">
      <div class="landing-glow landing-glow-one" aria-hidden="true"></div>
      <div class="landing-glow landing-glow-two" aria-hidden="true"></div>

      <header class="relative z-20 mx-auto flex max-w-7xl items-center justify-between px-5 py-4 sm:px-8 lg:px-10">
        <AppLink to="/" class="landing-brand group flex items-center rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-300">
          <img src="/images/repairsync-cloud-logo.png" alt="RepairSync Cloud" class="landing-brand-logo transition group-hover:scale-[1.02]" />
        </AppLink>

        <nav aria-label="Main navigation" class="hidden items-center gap-8 text-sm font-medium text-slate-300 md:flex">
          <a href="#available-shops" class="transition hover:text-white">Find a Shop</a>
          <a href="#features" class="transition hover:text-white">Features</a>
          <AppLink to="/track" class="transition hover:text-white">Track Repair</AppLink>
          <AppLink to="/consultation" class="transition hover:text-white">Free Consultation</AppLink>
        </nav>

        <div class="hidden items-center gap-3 md:flex">
          <AppLink v-if="canOpenPortal" :to="portalPath" class="rounded-full border border-white/20 px-5 py-2 text-sm font-semibold text-white transition hover:border-teal-300/60 hover:bg-white/5">Dashboard</AppLink>
          <AppLink v-else-if="technicianPortal" to="/technician" class="rounded-full border border-white/20 px-5 py-2 text-sm font-semibold text-white transition hover:border-teal-300/60 hover:bg-white/5">My Jobs</AppLink>
          <AppLink v-else to="/login" class="rounded-full border border-white/20 px-5 py-2 text-sm font-semibold text-white transition hover:border-teal-300/60 hover:bg-white/5">Login</AppLink>
        </div>

        <button
          type="button"
          class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-white/15 text-white hover:bg-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-300 md:hidden"
          :aria-expanded="mobileMenuOpen"
          aria-controls="landing-mobile-menu"
          :aria-label="mobileMenuOpen ? 'Close navigation menu' : 'Open navigation menu'"
          @click="mobileMenuOpen = !mobileMenuOpen"
        >
          <svg v-if="!mobileMenuOpen" aria-hidden="true" viewBox="0 0 24 24" fill="none" class="h-5 w-5" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16" />
          </svg>
          <svg v-else aria-hidden="true" viewBox="0 0 24 24" fill="none" class="h-5 w-5" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" d="m6 6 12 12M18 6 6 18" />
          </svg>
        </button>

        <nav
          v-if="mobileMenuOpen"
          id="landing-mobile-menu"
          aria-label="Mobile navigation"
          class="absolute inset-x-4 top-[4.5rem] z-30 grid gap-1 rounded-2xl border border-white/10 bg-slate-900 p-3 shadow-2xl md:hidden"
        >
          <a href="#available-shops" class="rounded-lg px-4 py-3 text-sm font-semibold text-slate-200 hover:bg-white/5" @click="closeMobileMenu">Find a Shop</a>
          <a href="#features" class="rounded-lg px-4 py-3 text-sm font-semibold text-slate-200 hover:bg-white/5" @click="closeMobileMenu">Features</a>
          <AppLink to="/track" class="rounded-lg px-4 py-3 text-sm font-semibold text-slate-200 hover:bg-white/5" @click="closeMobileMenu">Track Repair</AppLink>
          <AppLink to="/consultation" class="rounded-lg px-4 py-3 text-sm font-semibold text-slate-200 hover:bg-white/5" @click="closeMobileMenu">Free Consultation</AppLink>
          <AppLink v-if="canOpenPortal" :to="portalPath" class="mt-1 rounded-lg bg-teal-400 px-4 py-3 text-sm font-bold text-slate-950" @click="closeMobileMenu">Dashboard</AppLink>
          <AppLink v-else-if="technicianPortal" to="/technician" class="mt-1 rounded-lg bg-teal-400 px-4 py-3 text-sm font-bold text-slate-950" @click="closeMobileMenu">My Jobs</AppLink>
          <AppLink v-else to="/login" class="mt-1 rounded-lg bg-teal-400 px-4 py-3 text-sm font-bold text-slate-950" @click="closeMobileMenu">Login</AppLink>
        </nav>
      </header>

      <div class="relative z-10 mx-auto grid max-w-7xl items-center gap-12 px-5 pb-16 pt-10 sm:px-8 md:pt-14 lg:grid-cols-[0.88fr_1.12fr] lg:gap-10 lg:px-10 lg:pb-24 lg:pt-16">
        <div class="landing-reveal max-w-2xl">
          <p class="inline-flex items-center gap-2 rounded-full border border-teal-300/25 bg-teal-300/[0.08] px-3.5 py-2 text-[10px] font-bold uppercase tracking-[0.17em] text-teal-200 sm:text-xs">
            <span class="h-1.5 w-1.5 rounded-full bg-teal-300"></span>
            The operating system for repair shops
          </p>
          <h1 class="mt-6 max-w-xl font-display text-[2.8rem] font-bold leading-[1.04] tracking-[-0.04em] text-white sm:text-6xl lg:text-[4.35rem]">
            Run your repair shop <span class="landing-gradient-text">in sync.</span>
          </h1>
          <p class="mt-6 max-w-xl text-base leading-7 text-slate-300 sm:text-lg sm:leading-8">
            {{ appConfig.name }} brings repairs, technicians, inventory, payments, and customers together in one simple workspace.
          </p>
          <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
            <AppLink to="/get-started" class="inline-flex min-h-12 items-center justify-center gap-2 rounded-full bg-orange-500 px-6 text-sm font-bold text-white shadow-lg shadow-orange-500/20 transition hover:-translate-y-0.5 hover:bg-orange-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-300 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-950">
              Get Started <span aria-hidden="true">→</span>
            </AppLink>
            <AppLink to="/track" class="inline-flex min-h-12 items-center justify-center gap-2 rounded-full border border-white/20 px-6 text-sm font-bold text-white transition hover:border-teal-200/60 hover:bg-white/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-300">
              <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="h-4 w-4" stroke="currentColor" stroke-width="1.8">
                <circle cx="10.8" cy="10.8" r="6.8" />
                <path stroke-linecap="round" d="m16 16 4.5 4.5M8.5 10.8h4.6" />
              </svg>
              Track a Repair
            </AppLink>
          </div>
          <div class="mt-8 flex flex-wrap gap-x-6 gap-y-3 text-xs font-medium text-slate-300">
            <span class="inline-flex items-center gap-2"><span class="landing-check">✓</span> Built for repair workflows</span>
            <span class="inline-flex items-center gap-2"><span class="landing-check">✓</span> Customer status tracking</span>
            <span class="inline-flex items-center gap-2"><span class="landing-check">✓</span> One connected workspace</span>
          </div>
          <p v-if="appConfig.tagline" class="mt-7 text-xs text-slate-500">{{ appConfig.tagline }}</p>
        </div>

        <div class="landing-reveal landing-reveal-delay relative min-w-0">
          <div class="landing-orbit landing-orbit-one" aria-hidden="true"></div>
          <div class="landing-browser relative z-10">
            <div class="flex items-center justify-between border-b border-slate-200/80 bg-white px-3 py-3 sm:px-5">
              <div class="flex items-center gap-2">
                <span class="h-2.5 w-2.5 rounded-full bg-rose-300"></span>
                <span class="h-2.5 w-2.5 rounded-full bg-amber-300"></span>
                <span class="h-2.5 w-2.5 rounded-full bg-emerald-300"></span>
                <span class="ml-2 hidden text-[10px] font-medium text-slate-400 sm:inline">repairsync.app / workspace</span>
              </div>
              <span class="rounded-full bg-teal-50 px-2.5 py-1 text-[9px] font-bold uppercase tracking-wide text-teal-700 sm:text-[10px]">Product preview</span>
            </div>

            <div class="grid min-h-[320px] grid-cols-[48px_1fr] sm:min-h-[386px] sm:grid-cols-[148px_1fr]">
              <aside class="landing-preview-sidebar flex flex-col gap-4 px-2 py-4 sm:gap-5 sm:px-3 sm:py-5">
                <div class="mb-1 flex items-center justify-center sm:justify-start">
                  <img src="/images/repairsync-cloud-logo.png" alt="RepairSync Cloud" class="landing-preview-logo" />
                </div>
                <div class="landing-preview-nav landing-preview-nav-active"><span>▦</span><span class="hidden sm:inline">Dashboard</span></div>
                <div class="landing-preview-nav"><span>▤</span><span class="hidden sm:inline">Job Orders</span></div>
                <div class="landing-preview-nav"><span>◈</span><span class="hidden sm:inline">Inventory</span></div>
                <div class="landing-preview-nav"><span>♙</span><span class="hidden sm:inline">Technicians</span></div>
                <div class="landing-preview-nav"><span>◎</span><span class="hidden sm:inline">Customers</span></div>
                <div class="landing-preview-nav"><span>▥</span><span class="hidden sm:inline">Reports</span></div>
              </aside>

              <div class="min-w-0 bg-slate-50 p-3 sm:p-5">
                <div class="flex items-center justify-between gap-2">
                  <div>
                    <p class="text-[9px] font-semibold uppercase tracking-[0.13em] text-slate-400">Workspace overview</p>
                    <h2 class="mt-1 text-sm font-bold text-slate-900 sm:text-lg">Repair operations</h2>
                  </div>
                  <div class="flex h-7 w-7 items-center justify-center rounded-full bg-slate-200 text-[9px] font-bold text-slate-600">RS</div>
                </div>

                <div class="mt-4 grid grid-cols-3 gap-2 sm:mt-5 sm:gap-3">
                  <div class="rounded-lg border border-slate-200/80 bg-white p-2.5 sm:rounded-xl sm:p-3">
                    <p class="text-[8px] font-semibold uppercase tracking-wide text-slate-400 sm:text-[9px]">Job workflow</p>
                    <p class="mt-1.5 text-[10px] font-bold text-slate-800 sm:text-xs">At a glance</p>
                    <div class="mt-2 flex gap-1"><span class="h-1 flex-1 rounded-full bg-teal-400"></span><span class="h-1 flex-1 rounded-full bg-teal-400"></span><span class="h-1 flex-1 rounded-full bg-slate-200"></span></div>
                  </div>
                  <div class="rounded-lg border border-slate-200/80 bg-white p-2.5 sm:rounded-xl sm:p-3">
                    <p class="text-[8px] font-semibold uppercase tracking-wide text-slate-400 sm:text-[9px]">Parts</p>
                    <p class="mt-1.5 text-[10px] font-bold text-slate-800 sm:text-xs">Stock records</p>
                    <p class="mt-1 text-[8px] text-slate-400 sm:text-[9px]">Inventory activity</p>
                  </div>
                  <div class="rounded-lg border border-slate-200/80 bg-white p-2.5 sm:rounded-xl sm:p-3">
                    <p class="text-[8px] font-semibold uppercase tracking-wide text-slate-400 sm:text-[9px]">Payments</p>
                    <p class="mt-1.5 text-[10px] font-bold text-slate-800 sm:text-xs">Recorded</p>
                    <p class="mt-1 text-[8px] text-slate-400 sm:text-[9px]">Linked to job orders</p>
                  </div>
                </div>

                <div class="mt-4 overflow-hidden rounded-xl border border-slate-200/80 bg-white sm:mt-5">
                  <div class="flex items-center justify-between border-b border-slate-100 px-3 py-2.5 sm:px-4">
                    <div>
                      <p class="text-[10px] font-bold text-slate-800 sm:text-xs">Job order workflow</p>
                      <p class="mt-0.5 text-[8px] text-slate-400 sm:text-[9px]">Illustrative sample · not live shop data</p>
                    </div>
                    <span class="rounded-md bg-teal-50 px-2 py-1 text-[8px] font-bold text-teal-700 sm:text-[9px]">Workflow view</span>
                  </div>
                  <div class="grid grid-cols-[1.1fr_1fr_0.85fr] gap-2 border-b border-slate-100 bg-slate-50/80 px-3 py-2 text-[7px] font-bold uppercase tracking-wide text-slate-400 sm:px-4 sm:text-[8px]">
                    <span>Job order</span><span>Device / service</span><span>Status</span>
                  </div>
                  <div class="space-y-0.5 px-3 sm:px-4">
                    <div class="grid grid-cols-[1.1fr_1fr_0.85fr] items-center gap-2 border-b border-slate-100 py-3 text-[8px] sm:text-[9px]">
                      <span class="font-bold text-slate-700">Sample job</span><span class="text-slate-500">Phone · Screen</span><span class="w-fit rounded-full bg-teal-50 px-2 py-1 font-semibold text-teal-700">In progress</span>
                    </div>
                    <div class="grid grid-cols-[1.1fr_1fr_0.85fr] items-center gap-2 border-b border-slate-100 py-3 text-[8px] sm:text-[9px]">
                      <span class="font-bold text-slate-700">Sample job</span><span class="text-slate-500">Laptop · Diagnosis</span><span class="w-fit rounded-full bg-amber-50 px-2 py-1 font-semibold text-amber-700">Diagnosing</span>
                    </div>
                    <div class="grid grid-cols-[1.1fr_1fr_0.85fr] items-center gap-2 py-3 text-[8px] sm:text-[9px]">
                      <span class="font-bold text-slate-700">Sample job</span><span class="text-slate-500">Tablet · Parts</span><span class="w-fit rounded-full bg-violet-50 px-2 py-1 font-semibold text-violet-700">Waiting parts</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="landing-float-card relative z-20 mx-auto -mt-5 flex max-w-[19rem] items-center gap-3 rounded-2xl border border-white/70 bg-white p-3.5 shadow-xl shadow-slate-950/15 sm:absolute sm:-bottom-6 sm:-left-7 sm:mx-0">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-teal-50 text-teal-700">
              <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="h-5 w-5" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v11a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 17.5zM8 9h8M8 12h5" />
                <path stroke-linecap="round" stroke-linejoin="round" d="m8 16 1.5 1.5L13 14" />
              </svg>
            </span>
            <span>
              <span class="block text-xs font-bold text-slate-900">A clearer repair workflow</span>
              <span class="mt-0.5 block text-[10px] text-slate-500">From intake through customer pickup</span>
            </span>
          </div>
        </div>
      </div>
    </section>

    <PublicShopDirectory />

    <section id="features" class="scroll-mt-8 bg-white px-5 py-16 sm:px-8 sm:py-20 lg:px-10 lg:py-24">
      <div class="mx-auto max-w-7xl">
        <div class="mx-auto max-w-2xl text-center">
          <p class="landing-eyebrow">How it works</p>
          <h2 class="mt-3 font-display text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">Run your shop, step by step.</h2>
          <p class="mt-4 text-sm leading-7 text-slate-600 sm:text-base">
            From the moment a device arrives to the moment it is picked up, keep your team and customers on the same page.
          </p>
        </div>

        <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:mt-14 lg:grid-cols-4">
          <article v-for="(item, index) in workflowCards" :key="item.number" class="landing-feature-card group">
            <div class="flex items-center justify-between">
              <span class="landing-icon">
                <svg v-if="item.icon === 'clipboard'" aria-hidden="true" viewBox="0 0 24 24" fill="none" class="h-5 w-5" stroke="currentColor" stroke-width="1.8">
                  <rect x="5" y="4" width="14" height="17" rx="2" /><path stroke-linecap="round" d="M9 4.5h6V7H9zM9 11h6M9 15h6" />
                </svg>
                <svg v-else-if="item.icon === 'tools'" aria-hidden="true" viewBox="0 0 24 24" fill="none" class="h-5 w-5" stroke="currentColor" stroke-width="1.8">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m14.7 6.3 3-3a5 5 0 0 1-6.4 6.4L5 16a2.1 2.1 0 0 0 3 3l6.3-6.3a5 5 0 0 0 6.4-6.4l-3 3z" />
                </svg>
                <svg v-else-if="item.icon === 'chart'" aria-hidden="true" viewBox="0 0 24 24" fill="none" class="h-5 w-5" stroke="currentColor" stroke-width="1.8">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4 19V5m0 14h16M7 15l4-4 3 2 5-6M15 7h4v4" />
                </svg>
                <svg v-else aria-hidden="true" viewBox="0 0 24 24" fill="none" class="h-5 w-5" stroke="currentColor" stroke-width="1.8">
                  <circle cx="9" cy="8" r="3" /><path stroke-linecap="round" stroke-linejoin="round" d="M3.5 19a5.5 5.5 0 0 1 11 0M16 5a3 3 0 0 1 0 6m1 3a5 5 0 0 1 3.5 5" />
                </svg>
              </span>
              <span class="text-xs font-bold tracking-[0.14em] text-teal-700">{{ item.number }}</span>
            </div>
            <h3 class="mt-5 text-base font-bold text-slate-900">{{ item.title }}</h3>
            <p class="mt-2 text-sm leading-6 text-slate-600">{{ item.text }}</p>
            <div class="mt-5 h-px origin-left scale-x-0 bg-teal-400 transition-transform duration-300 group-hover:scale-x-100"></div>
          </article>
        </div>
      </div>
    </section>

    <section class="landing-platform-section px-5 py-16 sm:px-8 sm:py-20 lg:px-10 lg:py-24">
      <div class="mx-auto grid max-w-7xl items-center gap-12 lg:grid-cols-[1.05fr_0.95fr] lg:gap-16">
        <div class="landing-workspace-preview order-2 lg:order-1">
          <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3 sm:px-5">
            <div class="flex items-center gap-2">
              <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-teal-100 text-[9px] font-black text-teal-800">RS</span>
              <span class="text-xs font-bold text-slate-800">Workspace</span>
            </div>
            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[9px] font-semibold text-slate-500">Product preview</span>
          </div>
          <div class="grid gap-4 p-4 sm:grid-cols-[0.78fr_1.22fr] sm:p-5">
            <div class="rounded-xl bg-slate-950 p-4 text-white">
              <p class="text-[9px] font-bold uppercase tracking-[0.16em] text-teal-300">Repair workflow</p>
              <p class="mt-2 text-sm font-bold">A connected view of the work</p>
              <div class="mt-5 space-y-3">
                <div v-for="(step, index) in serviceSteps" :key="step" class="flex items-center gap-2.5">
                  <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[9px] font-bold" :class="index < 2 ? 'bg-teal-300 text-slate-950' : 'border border-white/25 text-slate-300'">{{ index < 2 ? '✓' : String(index + 1).padStart(2, '0') }}</span>
                  <span class="text-[10px]" :class="index < 2 ? 'font-semibold text-white' : 'text-slate-400'">{{ step }}</span>
                </div>
              </div>
            </div>
            <div class="space-y-3">
              <div class="rounded-xl border border-slate-200 bg-white p-4">
                <div class="flex items-center justify-between gap-3">
                  <div>
                    <p class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Job details</p>
                    <p class="mt-1 text-xs font-bold text-slate-800">Device, service, and repair notes</p>
                  </div>
                  <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-teal-50 text-teal-700">⌕</span>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-2">
                  <div class="rounded-lg bg-slate-50 p-2.5"><p class="text-[8px] text-slate-400">Customer</p><p class="mt-1 text-[9px] font-semibold text-slate-700">Contact on file</p></div>
                  <div class="rounded-lg bg-slate-50 p-2.5"><p class="text-[8px] text-slate-400">Assigned work</p><p class="mt-1 text-[9px] font-semibold text-slate-700">Technician view</p></div>
                </div>
              </div>
              <div class="rounded-xl border border-slate-200 bg-white p-4">
                <div class="flex items-center justify-between">
                  <p class="text-[10px] font-bold text-slate-800">Shop operations</p>
                  <span class="text-[8px] font-semibold uppercase tracking-wide text-slate-400">Connected modules</span>
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                  <span v-for="feature in ['Jobs', 'Parts', 'Payments', 'Tracking']" :key="feature" class="rounded-full border border-slate-200 px-2.5 py-1 text-[9px] font-medium text-slate-600">{{ feature }}</span>
                </div>
              </div>
            </div>
          </div>
          <p class="px-4 pb-4 text-center text-[9px] text-slate-400">Illustrative interface preview · sample content only</p>
        </div>

        <div class="order-1 lg:order-2">
          <p class="landing-eyebrow">All-in-one platform</p>
          <h2 class="mt-3 max-w-xl font-display text-3xl font-bold leading-tight tracking-tight text-slate-950 sm:text-4xl">Everything your repair shop needs. One workspace.</h2>
          <p class="mt-4 max-w-xl text-sm leading-7 text-slate-600 sm:text-base">
            Keep job orders, inventory, technicians, payments, and customer updates connected in a workspace built around electronics repair.
          </p>
          <ul class="mt-7 grid gap-x-5 gap-y-4 sm:grid-cols-2">
            <li v-for="feature in platformFeatures" :key="feature" class="flex items-start gap-3 text-sm font-semibold text-slate-700">
              <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-teal-100 text-[11px] font-black text-teal-800">✓</span>
              {{ feature }}
            </li>
          </ul>
          <AppLink to="/get-started" class="mt-8 inline-flex min-h-11 items-center justify-center gap-2 rounded-full bg-slate-950 px-5 text-sm font-bold text-white transition hover:-translate-y-0.5 hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 focus-visible:ring-offset-2">
            Explore your workspace <span aria-hidden="true">→</span>
          </AppLink>
        </div>
      </div>
    </section>

    <section class="landing-tracking-section relative isolate overflow-hidden px-5 py-16 sm:px-8 sm:py-20 lg:px-10 lg:py-24">
      <div class="landing-tracking-glow" aria-hidden="true"></div>
      <div class="relative z-10 mx-auto grid max-w-7xl items-center gap-12 lg:grid-cols-[0.9fr_1.1fr] lg:gap-16">
        <div>
          <p class="inline-flex rounded-full border border-teal-300/25 bg-teal-300/[0.08] px-3 py-1.5 text-[10px] font-bold uppercase tracking-[0.16em] text-teal-200">Customer tracking</p>
          <h2 class="mt-4 max-w-xl font-display text-3xl font-bold leading-tight tracking-tight text-white sm:text-4xl">Give customers clear updates without the follow-up calls.</h2>
          <p class="mt-4 max-w-lg text-sm leading-7 text-slate-300 sm:text-base">
            Customers can look up a repair using its job order number and see the latest available status and progress.
          </p>
          <AppLink to="/track" class="mt-7 inline-flex min-h-11 items-center justify-center gap-2 rounded-full bg-white px-5 text-sm font-bold text-slate-950 transition hover:-translate-y-0.5 hover:bg-teal-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-300 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-950">
            Track a Repair <span aria-hidden="true">→</span>
          </AppLink>
        </div>

        <div class="mx-auto w-full max-w-xl">
          <div class="landing-tracker-card">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
              <div>
                <p class="text-[9px] font-bold uppercase tracking-[0.14em] text-teal-700">Customer tracking</p>
                <h3 class="mt-1 text-sm font-bold text-slate-900">Track your repair</h3>
              </div>
              <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-teal-50 text-teal-700">
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="h-5 w-5" stroke="currentColor" stroke-width="1.7">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v11a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 17.5zM8 9h8M8 12h5" />
                </svg>
              </span>
            </div>
            <div class="p-5">
              <p class="text-xs font-semibold text-slate-700">Enter your job order number</p>
              <div class="mt-2 flex gap-2">
                <div class="flex min-h-10 min-w-0 flex-1 items-center rounded-lg border border-slate-200 bg-slate-50 px-3 text-xs text-slate-400">e.g. RSMS-000000</div>
                <span class="inline-flex min-h-10 items-center rounded-lg bg-slate-900 px-4 text-xs font-bold text-white">Track</span>
              </div>
              <p class="mt-2 text-[9px] text-slate-400">Illustrative preview — no customer or shop data shown</p>

              <div class="mt-5 rounded-xl border border-slate-200 p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                  <div>
                    <p class="text-[9px] font-semibold uppercase tracking-wide text-slate-400">Sample repair</p>
                    <p class="mt-1 text-xs font-bold text-slate-800">Device repair progress</p>
                  </div>
                  <span class="rounded-full bg-teal-50 px-3 py-1.5 text-[9px] font-bold text-teal-800">In progress</span>
                </div>
                <div class="mt-5 grid grid-cols-4 gap-1">
                  <div v-for="(step, index) in serviceSteps" :key="step" class="relative">
                    <div class="flex items-center">
                      <span class="relative z-10 flex h-6 w-6 items-center justify-center rounded-full text-[9px] font-bold" :class="index < 3 ? 'bg-teal-500 text-white' : 'border-2 border-slate-200 bg-white text-slate-400'">{{ index < 2 ? '✓' : index + 1 }}</span>
                      <span v-if="index < serviceSteps.length - 1" class="h-1 flex-1" :class="index < 2 ? 'bg-teal-400' : 'bg-slate-200'"></span>
                    </div>
                    <p class="mt-2 pr-1 text-[8px] leading-3 text-slate-500 sm:text-[9px]">{{ step }}</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="bg-white px-5 py-14 sm:px-8 sm:py-16 lg:px-10">
      <div class="mx-auto max-w-7xl">
        <div class="flex flex-col gap-3 border-b border-slate-200 pb-7 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <p class="landing-eyebrow">Built for real repair workflows</p>
            <h2 class="mt-2 font-display text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl">More than software. It’s peace of mind.</h2>
          </div>
          <p class="max-w-md text-sm leading-6 text-slate-600">A calmer way to coordinate the work, the records, and the customer experience.</p>
        </div>
        <div class="grid gap-7 pt-8 sm:grid-cols-2 lg:grid-cols-4 lg:gap-8">
          <article>
            <p class="text-sm font-bold text-slate-900">Save time</p>
            <p class="mt-1.5 text-sm leading-6 text-slate-600">Keep repair details and status history together.</p>
          </article>
          <article>
            <p class="text-sm font-bold text-slate-900">Reduce missed details</p>
            <p class="mt-1.5 text-sm leading-6 text-slate-600">Link parts, notes, photos, and payments to the job.</p>
          </article>
          <article>
            <p class="text-sm font-bold text-slate-900">Keep customers in the loop</p>
            <p class="mt-1.5 text-sm leading-6 text-slate-600">Give customers a self-service view of repair progress.</p>
          </article>
          <article>
            <p class="text-sm font-bold text-slate-900">See the bigger picture</p>
            <p class="mt-1.5 text-sm leading-6 text-slate-600">Use shop reports and organized records to review operations.</p>
          </article>
        </div>
      </div>
    </section>

    <section class="landing-final-cta relative isolate overflow-hidden px-5 py-16 text-center text-white sm:px-8 sm:py-20 lg:px-10 lg:py-24">
      <div class="landing-cta-orb landing-cta-orb-left" aria-hidden="true"></div>
      <div class="landing-cta-orb landing-cta-orb-right" aria-hidden="true"></div>
      <div class="relative z-10 mx-auto max-w-2xl">
        <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-teal-300">Ready to get started?</p>
        <h2 class="mt-4 font-display text-3xl font-bold tracking-tight sm:text-4xl">Bring your repair shop into sync.</h2>
        <p class="mx-auto mt-4 max-w-xl text-sm leading-7 text-slate-300 sm:text-base">Organize your repair workflow, keep your team aligned, and give customers a clearer repair experience.</p>
        <div class="mt-7 flex flex-col justify-center gap-3 sm:flex-row">
          <AppLink to="/get-started" class="inline-flex min-h-12 items-center justify-center gap-2 rounded-full bg-orange-500 px-6 text-sm font-bold text-white transition hover:-translate-y-0.5 hover:bg-orange-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-300">Start your shop <span aria-hidden="true">→</span></AppLink>
          <AppLink to="/track" class="inline-flex min-h-12 items-center justify-center rounded-full border border-white/25 px-6 text-sm font-bold text-white transition hover:bg-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-300">Track a Repair</AppLink>
        </div>
      </div>
    </section>

    <footer class="bg-slate-950 px-5 py-7 text-slate-400 sm:px-8 lg:px-10">
      <div class="mx-auto flex max-w-7xl flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
        <AppLink to="/" class="flex items-center gap-2.5">
          <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-teal-300 text-[10px] font-black text-slate-950">RS</span>
          <span class="text-xs font-bold text-white">RepairSync</span>
          <span class="text-xs text-slate-500">· {{ appConfig.tagline || 'Service Management' }}</span>
        </AppLink>
        <nav aria-label="Footer navigation" class="flex flex-wrap gap-x-5 gap-y-2 text-xs font-medium">
          <AppLink to="/get-started" class="hover:text-white">Get started</AppLink>
          <AppLink to="/track" class="hover:text-white">Track Repair</AppLink>
          <AppLink to="/consultation" class="hover:text-white">Consultation</AppLink>
          <AppLink to="/login" class="hover:text-white">Login</AppLink>
        </nav>
      </div>
    </footer>
  </main>
</template>

<style scoped>
.landing-page {
    --landing-navy: #061a2b;
    --landing-teal: #35dfc2;
}

.landing-brand-logo {
    display: block;
    width: 10.5rem;
    height: 2.75rem;
    object-fit: cover;
    object-position: center;
    filter: brightness(0) invert(1);
}

.landing-preview-logo {
    display: block;
    width: 7.25rem;
    height: 1.75rem;
    object-fit: cover;
    object-position: center;
    filter: brightness(0) invert(1);
}

.landing-hero {
    background:
        radial-gradient(ellipse at 74% 52%, rgba(30, 102, 126, 0.5), transparent 38%),
        linear-gradient(125deg, #061a2b 0%, #09263a 58%, #0c3043 100%);
}

.landing-glow,
.landing-orbit,
.landing-tracking-glow,
.landing-cta-orb {
    pointer-events: none;
    position: absolute;
    border-radius: 9999px;
}

.landing-glow-one {
    top: -17rem;
    right: 12%;
    height: 30rem;
    width: 30rem;
    background: rgba(17, 170, 164, 0.11);
    filter: blur(90px);
}

.landing-glow-two {
    bottom: -22rem;
    left: 25%;
    height: 32rem;
    width: 32rem;
    background: rgba(14, 116, 144, 0.2);
    filter: blur(100px);
}

.landing-gradient-text {
    background: linear-gradient(95deg, #45e0c3, #9af4e3);
    background-clip: text;
    -webkit-text-fill-color: transparent;
}

.landing-check {
    display: inline-flex;
    height: 17px;
    width: 17px;
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
    background: rgba(53, 223, 194, 0.16);
    color: #61ecd1;
    font-size: 10px;
    font-weight: 800;
}

.landing-browser {
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.5);
    border-radius: 1.2rem;
    background: white;
    box-shadow: 0 32px 90px rgba(0, 0, 0, 0.38), 0 0 0 6px rgba(255, 255, 255, 0.05);
    transform: perspective(1400px) rotateY(-3deg) rotateX(1deg);
}

.landing-preview-sidebar {
    background: linear-gradient(180deg, #092338, #061827);
}

.landing-preview-nav {
    display: flex;
    min-height: 26px;
    align-items: center;
    justify-content: center;
    gap: 9px;
    border-radius: 6px;
    padding: 5px;
    color: #aebdc8;
    font-size: 9px;
    font-weight: 600;
}

.landing-preview-nav span:first-child {
    flex: none;
    font-size: 12px;
}

.landing-preview-nav-active {
    background: rgba(45, 212, 191, 0.16);
    color: #e9fffa;
}

.landing-orbit-one {
    top: 12%;
    right: -1.5rem;
    height: 7rem;
    width: 7rem;
    border: 1px solid rgba(94, 234, 212, 0.25);
    box-shadow: 0 0 0 18px rgba(94, 234, 212, 0.035), 0 0 0 38px rgba(94, 234, 212, 0.025);
}

.landing-float-card {
    box-shadow: 0 16px 40px rgba(2, 14, 25, 0.22);
}

.landing-eyebrow {
    color: #078d82;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.17em;
    text-transform: uppercase;
}

.landing-feature-card {
    min-height: 190px;
    border: 1px solid #e5edf0;
    border-radius: 1rem;
    background: #fff;
    padding: 1.25rem;
    transition: transform 180ms ease, border-color 180ms ease, box-shadow 180ms ease;
}

.landing-feature-card:hover {
    transform: translateY(-3px);
    border-color: #a8e9df;
    box-shadow: 0 16px 34px rgba(15, 43, 58, 0.08);
}

.landing-icon {
    display: inline-flex;
    height: 2.5rem;
    width: 2.5rem;
    align-items: center;
    justify-content: center;
    border-radius: 0.875rem;
    background: #e6fbf6;
    color: #087d75;
}

.landing-platform-section {
    background:
        radial-gradient(ellipse at 5% 100%, rgba(139, 238, 221, 0.27), transparent 38%),
        linear-gradient(135deg, #f4fbfa 0%, #f9fcfc 58%, #eff9f7 100%);
}

.landing-workspace-preview {
    overflow: hidden;
    border: 1px solid #dce8e9;
    border-radius: 1.25rem;
    background: #f8fafc;
    box-shadow: 0 24px 55px rgba(22, 55, 69, 0.12);
}

.landing-tracking-section {
    background: linear-gradient(112deg, #071b2c 0%, #0a2a3c 56%, #12374a 100%);
}

.landing-tracking-glow {
    top: -14rem;
    right: -9rem;
    height: 35rem;
    width: 35rem;
    background: rgba(20, 184, 166, 0.14);
    filter: blur(100px);
}

.landing-tracker-card {
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.55);
    border-radius: 1.25rem;
    background: #fff;
    box-shadow: 0 24px 70px rgba(0, 0, 0, 0.26);
}

.landing-final-cta {
    background:
        radial-gradient(ellipse at 50% 120%, rgba(12, 116, 117, 0.25), transparent 48%),
        linear-gradient(120deg, #061a2a 0%, #071c2d 60%, #0a2334 100%);
}

.landing-cta-orb-left {
    bottom: -9rem;
    left: -7rem;
    height: 19rem;
    width: 19rem;
    border: 1px solid rgba(45, 212, 191, 0.22);
    box-shadow: 0 0 0 20px rgba(45, 212, 191, 0.035), 0 0 0 42px rgba(45, 212, 191, 0.025);
}

.landing-cta-orb-right {
    top: -13rem;
    right: -6rem;
    height: 24rem;
    width: 24rem;
    border: 1px solid rgba(45, 212, 191, 0.15);
    box-shadow: 0 0 0 24px rgba(45, 212, 191, 0.025), 0 0 0 50px rgba(45, 212, 191, 0.018);
}

.landing-reveal {
    animation: landing-enter 600ms cubic-bezier(0.2, 0.75, 0.25, 1) both;
}

.landing-reveal-delay {
    animation-delay: 100ms;
}

.landing-page :deep(a:focus-visible),
.landing-page button:focus-visible {
    outline: 2px solid #5eead4;
    outline-offset: 3px;
}

@keyframes landing-enter {
    from {
        opacity: 0;
        transform: translateY(12px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@media (max-width: 640px) {
    .landing-browser {
        transform: none;
    }

    .landing-orbit-one {
        right: -0.75rem;
        height: 4rem;
        width: 4rem;
    }
}

@media (prefers-reduced-motion: reduce) {
    .landing-page *,
    .landing-page *::before,
    .landing-page *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        scroll-behavior: auto !important;
        transition-duration: 0.01ms !important;
    }
}
</style>
