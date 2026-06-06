<script setup>
import AppLink from '../components/AppLink.vue';
</script>

<template>
  <div class="public-shell flex min-h-screen items-center justify-center px-4">
    <div class="public-card w-full max-w-lg p-10 text-center">
      <p class="text-xs font-bold uppercase tracking-[0.2em] text-teal-700">404</p>
      <h1 class="mt-2 font-display text-4xl text-slate-950">Page Not Found</h1>
      <p class="mt-3 text-slate-600">The page you requested does not exist in the system router.</p>
      <div class="mt-6">
        <AppLink to="/" class="btn-primary inline-flex px-6 py-3">
          Go Home
        </AppLink>
      </div>
    </div>
  </div>
</template>
