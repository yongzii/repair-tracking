<script setup>
import { computed } from 'vue';
import { adminRoles, authState, platformRoles } from '../composables/auth';
import AppLink from '../components/AppLink.vue';
import { appConfig } from '../config';

const canOpenPortal = computed(() => [...platformRoles, ...adminRoles, 'staff'].includes(authState.user?.role));
const technicianPortal = computed(() => authState.user?.role === 'technician');
const portalPath = computed(() => authState.user?.role === 'platform_admin' ? '/platform' : '/admin');

const platformStats = [
    { label: 'Active workflow', value: '5 stages', detail: 'Intake to pickup in one organized queue' },
    { label: 'Shop operations', value: 'Unified', detail: 'Jobs, parts, customers, and reports together' },
    { label: 'Customer updates', value: 'Self-serve', detail: 'Repair status lookup by job order number' },
];

const serviceSteps = [
    'Intake',
    'Diagnosis',
    'Parts',
    'Repair',
    'Pickup',
];

const boardMetrics = [
    { label: 'Open jobs', value: '24' },
    { label: 'Ready today', value: '7' },
    { label: 'Low stock', value: '3' },
];

const featureGroups = [
    {
        title: 'Repair Operations',
        text: 'Create job orders, assign technicians, record diagnosis notes, and move each repair through a clear service flow.',
    },
    {
        title: 'Inventory Control',
        text: 'Monitor spare parts, stock movements, and technician part requests before they slow down active repairs.',
    },
    {
        title: 'Customer Visibility',
        text: 'Give customers simple tracking, booking, and consultation links while your team stays focused on the queue.',
    },
];
</script>

<template>
  <div class="min-h-screen overflow-hidden bg-[#f7faf9] text-slate-900">
    <section class="relative min-h-[92vh] border-b border-slate-200/80 bg-[linear-gradient(135deg,#f8fafc_0%,#ffffff_45%,#e6f7f3_100%)]">
      <div class="absolute inset-x-0 top-0 h-2 bg-[linear-gradient(90deg,#0f1b2d,#0ea5a4,#f59e0b)]"></div>

      <header class="relative z-10 mx-auto flex max-w-7xl items-center justify-between px-5 py-5 sm:px-8 lg:px-10">
        <AppLink to="/" class="flex items-center gap-3">
          <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-slate-950 text-sm font-bold text-white shadow-lg shadow-slate-900/15">RS</span>
          <span>
            <span class="block text-sm font-bold text-slate-950">RepairSync</span>
            <span class="block text-xs font-medium text-slate-500">Service Management</span>
          </span>
        </AppLink>

        <nav class="hidden items-center gap-6 text-sm font-semibold text-slate-600 md:flex">
          <AppLink to="/track" class="hover:text-slate-950">Track Repair</AppLink>
          <AppLink to="/consultation" class="hover:text-slate-950">Consultation</AppLink>
          <AppLink to="/book" class="hover:text-slate-950">Drop-off</AppLink>
        </nav>

        <div class="flex items-center gap-2">
          <AppLink v-if="canOpenPortal" :to="portalPath" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-bold text-white shadow-sm shadow-teal-700/20 hover:bg-teal-700">Dashboard</AppLink>
          <AppLink v-else-if="technicianPortal" to="/technician" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-bold text-white shadow-sm shadow-teal-700/20 hover:bg-teal-700">My Jobs</AppLink>
          <AppLink v-else to="/login" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-bold text-white shadow-sm shadow-slate-900/20 hover:bg-slate-800">Login</AppLink>
        </div>
      </header>

      <div class="relative z-10 mx-auto grid max-w-7xl items-center gap-12 px-5 pb-12 pt-8 sm:px-8 lg:grid-cols-[1.02fr_0.98fr] lg:px-10 lg:pb-20 lg:pt-16">
        <div class="fade-up">
          <p class="inline-flex items-center rounded-full border border-teal-200 bg-white/80 px-4 py-2 text-xs font-bold uppercase tracking-[0.14em] text-teal-700 shadow-sm">
            SaaS repair tracking system
          </p>
          <h1 class="mt-6 max-w-4xl font-display text-5xl leading-[1.02] text-slate-950 sm:text-6xl lg:text-7xl">
            Run every repair job from intake to pickup.
          </h1>
          <p class="mt-6 max-w-2xl text-lg leading-8 text-slate-600">
            {{ appConfig.name }} helps repair shops organize job orders, technician work, spare parts, payments, and customer updates in one focused workspace.
          </p>
          <p class="mt-3 max-w-xl text-sm font-medium text-slate-500">
            {{ appConfig.tagline }}
          </p>

          <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
            <AppLink to="/get-started" class="inline-flex min-h-12 items-center justify-center rounded-lg bg-amber-500 px-6 text-sm font-bold text-slate-950 shadow-lg shadow-amber-500/20 hover:bg-amber-400">Launch Your Shop</AppLink>
            <AppLink to="/track" class="inline-flex min-h-12 items-center justify-center rounded-lg bg-slate-950 px-6 text-sm font-bold text-white shadow-lg shadow-slate-900/15 hover:bg-slate-800">Track Repair</AppLink>
            <AppLink to="/book" class="inline-flex min-h-12 items-center justify-center rounded-lg border border-teal-200 bg-white px-6 text-sm font-bold text-teal-700 shadow-sm hover:border-teal-300 hover:bg-teal-50">Book Drop-off</AppLink>
            <AppLink to="/consultation" class="inline-flex min-h-12 items-center justify-center rounded-lg border border-slate-200 bg-white px-6 text-sm font-bold text-slate-700 shadow-sm hover:bg-slate-50">Free Consultation</AppLink>
          </div>

          <div class="mt-10 grid gap-3 sm:grid-cols-3">
            <div v-for="stat in platformStats" :key="stat.label" class="rounded-lg border border-white bg-white/85 p-4 shadow-sm ring-1 ring-slate-100/80">
              <p class="text-xl font-bold text-slate-950">{{ stat.value }}</p>
              <p class="mt-1 text-sm font-semibold text-slate-700">{{ stat.label }}</p>
              <p class="mt-1 text-xs leading-5 text-slate-500">{{ stat.detail }}</p>
            </div>
          </div>
        </div>

        <div class="fade-up stagger-2">
          <div class="rounded-[2rem] border border-slate-200 bg-slate-950 p-3 shadow-2xl shadow-slate-900/20">
            <div class="overflow-hidden rounded-[1.45rem] bg-white">
              <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-5 py-4">
                <div>
                  <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-400">Operations Board</p>
                  <p class="text-sm font-bold text-slate-900">Today&apos;s Repair Flow</p>
                </div>
                <span class="rounded-full bg-teal-100 px-3 py-1 text-xs font-bold text-teal-700">Live queue</span>
              </div>

              <div class="grid gap-5 p-5">
                <div class="grid gap-3 sm:grid-cols-3">
                  <div v-for="metric in boardMetrics" :key="metric.label" class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <p class="text-2xl font-bold text-slate-950">{{ metric.value }}</p>
                    <p class="mt-1 text-xs font-semibold uppercase text-slate-500">{{ metric.label }}</p>
                  </div>
                </div>

                <div class="rounded-xl bg-slate-950 p-5 text-white">
                  <div class="flex items-start justify-between gap-4">
                    <div>
                      <p class="text-sm text-slate-300">Job Order</p>
                      <p class="mt-1 text-2xl font-bold">JO-2026-0148</p>
                    </div>
                    <span class="rounded-lg bg-amber-400 px-3 py-1 text-xs font-bold text-slate-950">Waiting Parts</span>
                  </div>
                  <div class="mt-5 grid grid-cols-5 gap-2">
                    <div v-for="(step, index) in serviceSteps" :key="step" class="min-w-0">
                      <div class="h-2 rounded-full" :class="index < 3 ? 'bg-teal-400' : 'bg-white/20'"></div>
                      <p class="mt-2 truncate text-[11px] font-semibold text-slate-300">{{ step }}</p>
                    </div>
                  </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                  <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-xs font-bold uppercase text-slate-400">Technician</p>
                    <p class="mt-2 text-lg font-bold text-slate-950">Assigned Workspace</p>
                    <p class="mt-2 text-sm leading-6 text-slate-500">Parts requests, status notes, and repair photos stay attached to the job order.</p>
                  </div>
                  <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-xs font-bold uppercase text-slate-400">Payment</p>
                    <p class="mt-2 text-lg font-bold text-slate-950">Balance: PHP 1,250.00</p>
                    <p class="mt-2 text-sm leading-6 text-slate-500">Manual payments, invoices, and remaining balances stay visible to operations.</p>
                  </div>
                </div>

                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                  <div class="flex items-center justify-between gap-4">
                    <div>
                      <p class="text-sm font-bold text-slate-900">Customer tracking ready</p>
                      <p class="mt-1 text-xs text-slate-500">Customers can check progress using their job order number.</p>
                    </div>
                    <AppLink to="/track" class="shrink-0 rounded-lg bg-teal-600 px-4 py-2 text-xs font-bold text-white hover:bg-teal-700">Open</AppLink>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="relative z-10 mx-auto grid max-w-7xl gap-4 px-5 pb-8 sm:px-8 md:grid-cols-3 lg:px-10">
        <div v-for="feature in featureGroups" :key="feature.title" class="rounded-xl border border-slate-200 bg-white/90 p-5 shadow-sm ring-1 ring-slate-100/80">
          <p class="text-base font-bold text-slate-950">{{ feature.title }}</p>
          <p class="mt-2 text-sm leading-6 text-slate-600">{{ feature.text }}</p>
        </div>
      </div>
    </section>
  </div>
</template>
