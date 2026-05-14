<script setup>
import AppLink from '../components/AppLink.vue';
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-slate-50 px-4">
    <div class="card p-10 text-center max-w-lg w-full">
      <p class="text-xs uppercase tracking-[0.2em] text-slate-500">404</p>
      <h1 class="mt-2 text-3xl font-display text-slate-900">Page Not Found</h1>
      <p class="mt-3 text-slate-600">The page you requested does not exist in the SPA router.</p>
      <div class="mt-6">
        <AppLink to="/" class="bg-slate-900 hover:bg-slate-800 text-white font-semibold py-2 px-6 rounded-lg">
          Go Home
        </AppLink>
      </div>
    </div>
  </div>
</template>
