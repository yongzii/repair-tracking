<script setup>
import { computed } from 'vue';
import { adminRoles, authState } from '../composables/auth';
import AppLink from '../components/AppLink.vue';
import { appConfig } from '../config';

const canOpenPortal = computed(() => [...adminRoles, 'staff'].includes(authState.user?.role));
const technicianPortal = computed(() => authState.user?.role === 'technician');
</script>

<template>
  <div class="min-h-screen bg-gradient-to-br from-slate-100 via-white to-teal-50">
    <div class="max-w-4xl mx-auto px-6 py-24">
      <div class="card p-10 text-center fade-up">
        <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Repair Tracking System</p>
        <h1 class="mt-3 text-5xl font-display text-slate-900">{{ appConfig.name }}</h1>
        <p class="mt-4 text-slate-600 max-w-2xl mx-auto">
          A web-based platform for managing repair intake, tracking service progress, monitoring inventory, and supporting customer updates.
        </p>
        <p class="mt-2 text-sm text-slate-500 max-w-2xl mx-auto">
          {{ appConfig.tagline }}
        </p>

        <div class="mt-8 flex flex-wrap justify-center gap-3">
          <AppLink to="/login" class="bg-slate-900 hover:bg-slate-800 text-white font-semibold py-2 px-6 rounded-lg">Operations Login</AppLink>
          <AppLink to="/track" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2 px-6 rounded-lg">Track Repair</AppLink>
          <AppLink to="/consultation" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2 px-6 rounded-lg">Free Consultation</AppLink>
          <AppLink to="/book" class="bg-teal-600 hover:bg-teal-700 text-white font-semibold py-2 px-6 rounded-lg">Book Drop-off</AppLink>
          <AppLink to="/get-started" class="bg-amber-500 hover:bg-amber-600 text-slate-950 font-semibold py-2 px-6 rounded-lg">Launch Your Shop</AppLink>
          <AppLink v-if="canOpenPortal" to="/admin" class="bg-teal-600 hover:bg-teal-700 text-white font-semibold py-2 px-6 rounded-lg">Dashboard</AppLink>
          <AppLink v-if="technicianPortal" to="/technician" class="bg-teal-600 hover:bg-teal-700 text-white font-semibold py-2 px-6 rounded-lg">My Jobs</AppLink>
        </div>
      </div>
    </div>
  </div>
</template>
