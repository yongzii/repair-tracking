<script setup>
import { computed, ref } from 'vue';
import AppLink from '../components/AppLink.vue';
import { navigate } from '../navigation';

const props = defineProps({
    params: {
        type: Object,
        default: () => ({}),
    },
});

const jobOrderNumber = ref(props.params.jobOrderNumber || '');
const shopSlug = ref(props.params.shopSlug || '');
const loading = ref(false);
const errorMessage = ref('');
const jobOrder = ref(null);
const timeline = ref([]);
const shop = ref(null);
const shopOptions = ref([]);
const bookingPath = computed(() => {
    const normalizedShop = shopSlug.value.trim().toLowerCase();
    return normalizedShop ? `/shops/${encodeURIComponent(normalizedShop)}/book` : '/book';
});
const consultationPath = computed(() => {
    const normalizedShop = shopSlug.value.trim().toLowerCase();
    return normalizedShop ? `/shops/${encodeURIComponent(normalizedShop)}/consultation` : '/consultation';
});

const formatStatus = (value) => (value ? value.replaceAll('_', ' ') : '');

const fetchTracking = async (number) => {
    loading.value = true;
    errorMessage.value = '';
    shopOptions.value = [];

    try {
        const request = shopSlug.value
            ? window.axios.get(`/api/shops/${encodeURIComponent(shopSlug.value)}/track/${encodeURIComponent(number)}`)
            : window.axios.get('/api/track', {
                params: {
                    job_order_number: number,
                },
            });
        const { data } = await request;
        shop.value = data.shop || null;
        jobOrder.value = data.jobOrder;
        timeline.value = data.timeline || [];
    } catch (error) {
        shop.value = null;
        jobOrder.value = null;
        timeline.value = [];
        shopOptions.value = error?.response?.data?.shops || [];
        errorMessage.value = error?.response?.data?.message || 'Job order not found.';
    } finally {
        loading.value = false;
    }
};

if (jobOrderNumber.value) {
    fetchTracking(jobOrderNumber.value);
}

const onSearch = () => {
    const normalized = jobOrderNumber.value.trim().toUpperCase();
    if (!normalized) return;
    const normalizedShop = shopSlug.value.trim().toLowerCase();
    if (normalizedShop) {
        navigate(`/shops/${encodeURIComponent(normalizedShop)}/track/${encodeURIComponent(normalized)}`);
        return;
    }
    navigate(`/track/${encodeURIComponent(normalized)}`);
};
</script>

<template>
  <div class="public-shell px-4 py-8">
    <div class="mx-auto max-w-5xl space-y-6">
      <div class="public-card overflow-hidden">
        <div class="border-b border-slate-200 bg-slate-950 p-6 text-white sm:p-8">
          <div class="flex flex-col gap-5 md:flex-row md:items-start md:justify-between">
            <div>
              <p class="text-xs font-bold uppercase text-teal-300">Customer repair tracking</p>
              <h1 class="mt-3 font-display text-4xl leading-tight">Track Your Repair</h1>
              <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-300">
                Enter your shop code and job order number to view the latest repair status and service history.
              </p>
            </div>
            <div class="flex flex-wrap gap-2">
              <AppLink :to="consultationPath" class="rounded-lg bg-white/10 px-3 py-2 text-sm font-bold text-white hover:bg-white/15">Consultation</AppLink>
              <AppLink :to="bookingPath" class="rounded-lg bg-white/10 px-3 py-2 text-sm font-bold text-white hover:bg-white/15">Book Drop-off</AppLink>
              <AppLink to="/" class="rounded-lg bg-white px-3 py-2 text-sm font-bold text-slate-950 hover:bg-slate-100">Home</AppLink>
            </div>
          </div>
        </div>

        <form class="grid gap-3 p-6 sm:grid-cols-[0.8fr_1.2fr_auto]" @submit.prevent="onSearch">
          <input
            v-model="shopSlug"
            type="text"
            placeholder="Shop code (recommended)"
            class="w-full border border-slate-300 px-4 py-3"
          />
          <input
            v-model="jobOrderNumber"
            type="text"
            placeholder="Enter Job Order Number"
            class="w-full border border-slate-300 px-4 py-3"
            required
          />
          <button type="submit" class="btn-primary px-6 py-3">
            Search
          </button>
        </form>
      </div>

      <div v-if="loading" class="public-card p-6 text-slate-600">Loading tracking details...</div>
      <div v-if="errorMessage" class="rounded-xl border border-rose-200 bg-rose-50 p-6 text-rose-700">{{ errorMessage }}</div>
      <div v-if="shopOptions.length" class="public-card p-6">
        <h3 class="text-lg font-bold text-slate-950 mb-3">Choose Your Shop</h3>
        <div class="space-y-3">
          <AppLink
            v-for="option in shopOptions"
            :key="option.slug"
            :to="`/shops/${option.slug}/track/${encodeURIComponent(jobOrderNumber.trim().toUpperCase())}`"
            class="block rounded-xl border border-slate-200 px-4 py-3 hover:border-teal-200 hover:bg-teal-50"
          >
            <p class="font-bold text-slate-950">{{ option.name }}</p>
            <p class="text-sm text-slate-500">Shop code: {{ option.slug }}</p>
          </AppLink>
        </div>
      </div>

      <div v-if="jobOrder" class="space-y-6">
        <div v-if="shop" class="public-card p-6">
          <h2 class="text-xl font-bold text-slate-950 mb-2">{{ shop.name }}</h2>
          <p v-if="shop.phone" class="text-sm text-slate-600">{{ shop.phone }}</p>
          <p v-if="shop.address" class="text-sm text-slate-500">{{ shop.address }}</p>
        </div>

        <div class="public-card p-6">
          <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
              <p class="text-xs font-bold uppercase text-slate-400">Job Order</p>
              <h2 class="mt-1 text-2xl font-bold text-slate-950">{{ jobOrder.job_order_number }}</h2>
            </div>
            <span class="rounded-full bg-teal-50 px-3 py-1 text-xs font-bold text-teal-700 ring-1 ring-teal-200">{{ formatStatus(jobOrder.current_status) }}</span>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div>
              <p class="text-slate-500">Customer</p>
              <p class="font-semibold text-slate-900">{{ jobOrder.customer_name }}</p>
            </div>
            <div>
              <p class="text-slate-500">Device</p>
              <p class="font-semibold text-slate-900">{{ [jobOrder.brand, jobOrder.model].filter(Boolean).join(' ') || jobOrder.device_type }}</p>
            </div>
            <div>
              <p class="text-slate-500">Current Status</p>
              <p class="font-semibold text-slate-900">{{ formatStatus(jobOrder.current_status) }}</p>
            </div>
            <div>
              <p class="text-slate-500">Received</p>
              <p class="font-semibold text-slate-900">{{ jobOrder.created_at_formatted }}</p>
            </div>
          </div>
        </div>

        <div class="public-card p-6">
          <h3 class="text-lg font-bold text-slate-950 mb-4">Timeline</h3>
          <div class="space-y-4">
            <div v-for="entry in timeline" :key="entry.id || entry.created_at" class="relative border-l-2 border-teal-200 pb-5 pl-5 last:pb-0">
              <span class="absolute -left-[7px] top-1 h-3 w-3 rounded-full bg-teal-500 ring-4 ring-teal-50"></span>
              <p class="font-bold text-slate-950">{{ formatStatus(entry.status) }}</p>
              <p class="text-sm text-slate-500">{{ entry.created_at_formatted }}</p>
              <p v-if="entry.note" class="text-sm text-slate-700 mt-1">{{ entry.note }}</p>
            </div>
            <p v-if="!timeline.length" class="text-sm text-slate-500">No status history available.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
