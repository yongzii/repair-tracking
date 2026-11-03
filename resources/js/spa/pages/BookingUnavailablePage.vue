<script setup>
import AppLink from '../components/AppLink.vue';

const props = defineProps({
    params: {
        type: Object,
        default: () => ({}),
    },
});

const consultationPath = props.params.shopSlug
    ? `/shops/${encodeURIComponent(props.params.shopSlug)}/consultation`
    : '/consultation';
</script>

<template>
  <div class="public-shell flex min-h-screen items-center justify-center px-4">
    <div class="public-card w-full max-w-lg p-10 text-center">
      <p class="text-xs font-bold uppercase tracking-[0.2em] text-teal-700">Service unavailable</p>
      <h1 class="mt-2 font-display text-3xl text-slate-950">Drop-off booking is no longer available</h1>
      <p class="mt-3 text-slate-600">RepairSync no longer accepts drop-off bookings. You can still request a consultation or contact the shop directly.</p>
      <div class="mt-6 flex flex-wrap justify-center gap-3">
        <AppLink :to="consultationPath" class="btn-primary inline-flex px-6 py-3">
          Request a Consultation
        </AppLink>
        <AppLink to="/" class="inline-flex rounded-lg border border-slate-300 px-6 py-3 font-semibold text-slate-700 hover:bg-slate-50">
          Go Home
        </AppLink>
      </div>
    </div>
  </div>
</template>
