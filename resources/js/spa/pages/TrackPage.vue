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
  <div class="min-h-screen bg-slate-50 py-10 px-4">
    <div class="max-w-4xl mx-auto space-y-6">
      <div class="card p-6">
        <div class="flex items-center justify-between gap-4 mb-5">
          <h1 class="text-2xl font-bold text-slate-900">Track Your Repair</h1>
          <div class="flex flex-wrap gap-3">
            <AppLink :to="consultationPath" class="text-slate-600 hover:text-slate-900 text-sm font-semibold">Free Consultation</AppLink>
            <AppLink :to="bookingPath" class="text-slate-600 hover:text-slate-900 text-sm font-semibold">Book Drop-off</AppLink>
            <AppLink to="/" class="text-slate-600 hover:text-slate-900 text-sm font-semibold">Back to Home</AppLink>
          </div>
        </div>

        <form class="flex flex-col sm:flex-row gap-3" @submit.prevent="onSearch">
          <input
            v-model="shopSlug"
            type="text"
            placeholder="Shop code (recommended)"
            class="sm:max-w-xs px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-slate-500 focus:border-transparent"
          />
          <input
            v-model="jobOrderNumber"
            type="text"
            placeholder="Enter Job Order Number"
            class="flex-1 px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-slate-500 focus:border-transparent"
            required
          />
          <button type="submit" class="bg-slate-900 hover:bg-slate-800 text-white font-semibold px-5 py-2 rounded-lg">
            Search
          </button>
        </form>
      </div>

      <div v-if="loading" class="card p-6 text-slate-600">Loading tracking details...</div>
      <div v-if="errorMessage" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">{{ errorMessage }}</div>
      <div v-if="shopOptions.length" class="card p-6">
        <h3 class="text-lg font-semibold text-slate-900 mb-3">Choose Your Shop</h3>
        <div class="space-y-3">
          <AppLink
            v-for="option in shopOptions"
            :key="option.slug"
            :to="`/shops/${option.slug}/track/${encodeURIComponent(jobOrderNumber.trim().toUpperCase())}`"
            class="block rounded-lg border border-slate-200 px-4 py-3 hover:bg-slate-50"
          >
            <p class="font-semibold text-slate-900">{{ option.name }}</p>
            <p class="text-sm text-slate-500">Shop code: {{ option.slug }}</p>
          </AppLink>
        </div>
      </div>

      <div v-if="jobOrder" class="space-y-6">
        <div v-if="shop" class="card p-6">
          <h2 class="text-xl font-bold text-slate-900 mb-2">{{ shop.name }}</h2>
          <p v-if="shop.phone" class="text-sm text-slate-600">{{ shop.phone }}</p>
          <p v-if="shop.address" class="text-sm text-slate-500">{{ shop.address }}</p>
        </div>

        <div class="card p-6">
          <h2 class="text-xl font-bold text-slate-900 mb-4">{{ jobOrder.job_order_number }}</h2>
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

        <div class="card p-6">
          <h3 class="text-lg font-bold text-slate-900 mb-4">Timeline</h3>
          <div class="space-y-4">
            <div v-for="entry in timeline" :key="entry.id || entry.created_at" class="border-b border-slate-100 pb-3 last:border-0 last:pb-0">
              <p class="font-semibold text-slate-900">{{ formatStatus(entry.status) }}</p>
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
