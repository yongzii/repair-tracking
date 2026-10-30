<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppLink from '../components/AppLink.vue';

const props = defineProps({
    params: {
        type: Object,
        default: () => ({}),
    },
});

const shop = ref(null);
const loading = ref(false);
const errorMessage = ref('');
const notFound = ref(false);
const logoFailed = ref(false);
let requestController = null;
let requestId = 0;

const businessDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
const serviceCategories = computed(() => (shop.value?.service_catalog || [])
    .map((category) => ({
        ...category,
        services: (category.services || []).filter((service) => service.is_active),
    }))
    .filter((category) => category.is_active && category.services.length));
const hasServices = computed(() => serviceCategories.value.length > 0);
const hasShopDescription = computed(() => typeof shop.value?.description === 'string' && shop.value.description.trim().length > 0);
const consultationPath = computed(() => `/shops/${encodeURIComponent(shop.value?.slug || '')}/consultation`);

const formatTime = (time) => {
    if (!time) return '';
    const [hourValue, minute = '00'] = time.split(':');
    const hour = Number(hourValue);
    if (!Number.isInteger(hour) || hour < 0 || hour > 23) return time;
    const suffix = hour >= 12 ? 'PM' : 'AM';
    return `${hour % 12 || 12}:${minute} ${suffix}`;
};

const formatHours = (hours) => {
    if (!hours || hours.open === null) return 'Not configured';
    if (hours.open === false) return 'Closed';
    if (!hours.open_time || !hours.close_time) return 'Hours not provided';
    const opening = formatTime(hours.open_time);
    const closing = formatTime(hours.close_time);
    return opening && closing ? `${opening} - ${closing}` : 'Hours not provided';
};

const shopInitials = (name) => (name || 'Shop')
    .split(/\s+/)
    .slice(0, 2)
    .map((word) => word.charAt(0))
    .join('')
    .toUpperCase();

const loadShop = async () => {
    const slug = props.params.slug;
    if (!slug) {
        notFound.value = true;
        errorMessage.value = '';
        return;
    }

    requestController?.abort();
    requestController = new AbortController();
    const currentRequestId = ++requestId;
    loading.value = true;
    errorMessage.value = '';
    notFound.value = false;

    try {
        const { data } = await window.axios.get(`/api/shops/${encodeURIComponent(slug)}/profile`, {
            signal: requestController.signal,
        });
        if (currentRequestId !== requestId) return;
        shop.value = data.shop || null;
        logoFailed.value = false;
        if (!shop.value) notFound.value = true;
    } catch (error) {
        if (error?.code === 'ERR_CANCELED' || currentRequestId !== requestId) return;
        shop.value = null;
        if (error?.response?.status === 404) {
            notFound.value = true;
        } else {
            errorMessage.value = 'We could not load this shop right now. Please try again.';
        }
    } finally {
        if (currentRequestId === requestId) loading.value = false;
    }
};

onMounted(loadShop);
onBeforeUnmount(() => {
    requestId += 1;
    requestController?.abort();
});
</script>

<template>
  <main class="min-h-screen bg-[#f3f7fb] text-slate-900">
    <header class="bg-[#061a2b] px-5 py-5 text-white sm:px-8">
      <div class="mx-auto flex max-w-6xl items-center justify-between gap-4">
        <AppLink to="/" class="text-sm font-bold tracking-wide text-white transition hover:text-teal-200">RepairSync Cloud</AppLink>
        <AppLink to="/" class="rounded-lg border border-white/20 px-4 py-2 text-sm font-semibold text-slate-100 transition hover:border-teal-300 hover:text-white">Back to home</AppLink>
      </div>
    </header>

    <div v-if="loading && !shop" class="mx-auto flex min-h-[60vh] max-w-3xl items-center justify-center gap-3 px-5 text-sm font-medium text-slate-600" role="status" aria-live="polite">
      <span class="h-5 w-5 animate-spin rounded-full border-2 border-teal-600 border-t-transparent" aria-hidden="true"></span>
      Loading shop information...
    </div>

    <section v-else-if="notFound" class="mx-auto flex min-h-[60vh] max-w-xl flex-col items-center justify-center px-5 text-center">
      <p class="text-xs font-bold uppercase tracking-[0.17em] text-teal-700">Shop unavailable</p>
      <h1 class="mt-3 text-2xl font-bold text-[#0f1b2d]">This shop could not be found.</h1>
      <p class="mt-3 text-sm leading-6 text-slate-600">The shop may be inactive or the address may be incorrect.</p>
      <AppLink to="/" class="mt-6 rounded-xl bg-[#0f1b2d] px-5 py-3 text-sm font-bold text-white transition hover:bg-teal-800">Return to RepairSync</AppLink>
    </section>

    <section v-else-if="errorMessage" class="mx-auto flex min-h-[60vh] max-w-xl flex-col items-center justify-center px-5 text-center" role="alert">
      <h1 class="text-xl font-bold text-[#0f1b2d]">Shop information is temporarily unavailable.</h1>
      <p class="mt-3 text-sm leading-6 text-slate-600">{{ errorMessage }}</p>
      <button type="button" class="mt-6 rounded-xl bg-[#0f1b2d] px-5 py-3 text-sm font-bold text-white transition hover:bg-teal-800 disabled:opacity-60" :disabled="loading" @click="loadShop">
        {{ loading ? 'Retrying...' : 'Try again' }}
      </button>
    </section>

    <div v-else-if="shop" class="mx-auto max-w-6xl px-5 py-8 sm:px-8 sm:py-12">
      <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <div class="h-2 bg-gradient-to-r from-teal-600 to-teal-400" aria-hidden="true"></div>
        <div class="flex flex-col gap-6 p-6 sm:flex-row sm:items-center sm:p-9">
          <div v-if="shop.logo_url && !logoFailed" class="flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-slate-100 bg-slate-50 sm:h-28 sm:w-28">
            <img :src="shop.logo_url" :alt="`${shop.name} logo`" class="h-full w-full object-contain p-3" @error="logoFailed = true" />
          </div>
          <div v-else class="flex h-24 w-24 shrink-0 items-center justify-center rounded-2xl bg-teal-50 text-2xl font-black text-teal-800 sm:h-28 sm:w-28" aria-hidden="true">{{ shopInitials(shop.name) }}</div>
          <div class="min-w-0 flex-1">
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-teal-700">Repair shop</p>
            <h1 class="mt-2 break-words text-3xl font-bold tracking-tight text-[#0f1b2d] sm:text-4xl">{{ shop.name }}</h1>
            <p v-if="hasShopDescription" class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-600">{{ shop.description }}</p>
            <p v-else class="mt-3 text-sm leading-6 text-slate-600">No shop description available.</p>
            <p v-if="shop.address" class="mt-3 break-words text-sm leading-6 text-slate-600">{{ shop.address }}</p>
            <p v-else class="mt-3 text-sm leading-6 text-slate-500">Address not yet provided.</p>
            <p v-if="shop.phone" class="mt-2 text-sm font-medium text-slate-700">Phone: {{ shop.phone }}</p>
          </div>
          <AppLink
            :to="consultationPath"
            class="inline-flex min-h-12 shrink-0 items-center justify-center rounded-xl bg-teal-700 px-5 text-sm font-bold text-white transition hover:bg-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2"
          >
            Request a Consultation
          </AppLink>
        </div>
      </section>

      <div class="mt-6 grid gap-6 lg:grid-cols-[0.72fr_1.28fr]">
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="business-hours-title">
          <p class="text-xs font-bold uppercase tracking-wide text-teal-700">Visit information</p>
          <h2 id="business-hours-title" class="mt-2 text-xl font-bold text-[#0f1b2d]">Business Hours</h2>
          <div v-if="shop.business_hours" class="mt-4 divide-y divide-slate-100">
            <div v-for="day in businessDays" :key="day" class="flex items-center justify-between gap-4 py-2.5 text-sm">
              <span class="font-semibold capitalize text-slate-700">{{ shop.business_hours[day]?.label || day }}</span>
              <span class="text-right text-slate-600">{{ formatHours(shop.business_hours[day]) }}</span>
            </div>
          </div>
          <p v-else class="mt-4 text-sm leading-6 text-slate-600">Business hours not yet configured.</p>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="available-services-title">
          <p class="text-xs font-bold uppercase tracking-wide text-teal-700">Service catalog</p>
          <h2 id="available-services-title" class="mt-2 text-xl font-bold text-[#0f1b2d]">Available Services</h2>
          <div v-if="hasServices" class="mt-5 space-y-5">
            <article v-for="category in serviceCategories" :key="category.id" class="rounded-xl border border-slate-100 bg-slate-50/70 p-4">
              <h3 class="font-bold text-slate-900">{{ category.name }}</h3>
              <p v-if="category.description" class="mt-1 text-sm leading-6 text-slate-600">{{ category.description }}</p>
              <ul class="mt-3 grid gap-3 sm:grid-cols-2">
                <li v-for="service in category.services" :key="service.id" class="rounded-lg border border-slate-100 bg-white p-3">
                  <p class="text-sm font-semibold text-slate-800">{{ service.name }}</p>
                  <p v-if="service.description" class="mt-1 text-xs leading-5 text-slate-600">{{ service.description }}</p>
                </li>
              </ul>
            </article>
          </div>
          <p v-else class="mt-4 rounded-xl bg-slate-50 px-4 py-5 text-sm leading-6 text-slate-600">No services configured yet. You may still request a consultation.</p>
          <AppLink
            :to="consultationPath"
            class="mt-6 inline-flex min-h-11 items-center justify-center rounded-xl border border-teal-700 px-5 text-sm font-bold text-teal-800 transition hover:bg-teal-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2"
          >
            Request a Consultation
          </AppLink>
        </section>
      </div>
    </div>
  </main>
</template>
