<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import AppLink from './AppLink.vue';

const pageSize = 12;
const searchInput = ref('');
const search = ref('');
const page = ref(1);
const shops = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(false);
const errorMessage = ref('');
const failedLogos = reactive(new Set());

let debounceTimer = null;
let requestController = null;
let requestId = 0;

const searchPending = computed(() => searchInput.value.trim() !== search.value);
const noSearchResults = computed(() => search.value !== '');

const loadShops = async () => {
    requestController?.abort();
    requestController = new AbortController();
    const currentRequestId = ++requestId;
    loading.value = true;
    errorMessage.value = '';

    try {
        const { data } = await window.axios.get('/api/public/shops', {
            params: {
                search: search.value || undefined,
                page: page.value,
                per_page: pageSize,
            },
            signal: requestController.signal,
        });

        if (currentRequestId !== requestId) return;
        shops.value = Array.isArray(data.shops) ? data.shops : [];
        pagination.value = data.pagination || { current_page: page.value, last_page: 1, total: shops.value.length };
    } catch (error) {
        if (error?.code === 'ERR_CANCELED' || currentRequestId !== requestId) return;
        errorMessage.value = 'We could not load repair shops right now. Please try again.';
    } finally {
        if (currentRequestId === requestId) loading.value = false;
    }
};

const scheduleSearch = () => {
    window.clearTimeout(debounceTimer);
    page.value = 1;
    debounceTimer = window.setTimeout(() => {
        const nextSearch = searchInput.value.trim();
        if (nextSearch === search.value) return;
        search.value = nextSearch;
        loadShops();
    }, 300);
};

const changePage = (nextPage) => {
    if (loading.value || searchPending.value || nextPage < 1 || nextPage > pagination.value.last_page) return;
    page.value = nextPage;
    loadShops();
};

const showLogo = (shop) => Boolean(shop.logo_url && !failedLogos.has(shop.id));
const shopInitials = (name) => (name || 'Shop')
    .split(/\s+/)
    .slice(0, 2)
    .map((word) => word.charAt(0))
    .join('')
    .toUpperCase();

onMounted(loadShops);
onBeforeUnmount(() => {
    window.clearTimeout(debounceTimer);
    requestId += 1;
    requestController?.abort();
});
</script>

<template>
  <section id="available-shops" class="scroll-mt-6 bg-[#f3f7fb] px-5 py-16 sm:px-8 sm:py-20 lg:px-10 lg:py-24" aria-labelledby="shop-directory-title">
    <div class="mx-auto max-w-7xl">
      <div class="mx-auto max-w-2xl text-center">
        <p class="text-xs font-bold uppercase tracking-[0.17em] text-teal-700">Repair shops</p>
        <h2 id="shop-directory-title" class="mt-3 font-display text-3xl font-bold tracking-tight text-[#0f1b2d] sm:text-4xl">Find a Repair Shop</h2>
        <p class="mt-4 text-sm leading-7 text-slate-600 sm:text-base">Discover electronics repair shops and explore the services they offer.</p>
      </div>

      <div class="mx-auto mt-8 max-w-2xl">
        <label for="shop-directory-search" class="sr-only">Search shops, locations, or services</label>
        <div class="relative">
          <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" stroke="currentColor" stroke-width="1.8">
            <circle cx="10.8" cy="10.8" r="6.8" />
            <path stroke-linecap="round" d="m16 16 4 4" />
          </svg>
          <input
            id="shop-directory-search"
            v-model="searchInput"
            type="search"
            maxlength="100"
            placeholder="Search shops, locations, or services..."
            class="min-h-12 w-full rounded-xl border border-slate-200 bg-white py-3 pl-12 pr-4 text-sm text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-teal-500 focus:ring-4 focus:ring-teal-500/10"
            @input="scheduleSearch"
          />
        </div>
      </div>

      <div v-if="loading && !shops.length" class="mt-12 flex min-h-40 items-center justify-center gap-3 text-sm font-medium text-slate-600" role="status" aria-live="polite">
        <span class="h-5 w-5 animate-spin rounded-full border-2 border-teal-600 border-t-transparent" aria-hidden="true"></span>
        Loading repair shops...
      </div>

      <div v-else-if="errorMessage" class="mx-auto mt-12 max-w-xl rounded-2xl border border-rose-200 bg-white p-6 text-center shadow-sm" role="alert">
        <p class="font-semibold text-rose-800">{{ errorMessage }}</p>
        <button type="button" class="mt-4 rounded-lg bg-[#0f1b2d] px-5 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:opacity-60" :disabled="loading" @click="loadShops">
          {{ loading ? 'Retrying...' : 'Try again' }}
        </button>
      </div>

      <div v-else-if="!shops.length" class="mx-auto mt-12 max-w-xl rounded-2xl border border-slate-200 bg-white px-6 py-10 text-center shadow-sm">
        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-teal-50 text-teal-700" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" class="h-6 w-6" stroke="currentColor" stroke-width="1.7">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 10.5 12 4l9 6.5M5 9.5V20h14V9.5M9 20v-6h6v6" />
          </svg>
        </div>
        <p class="mt-4 font-bold text-slate-900">{{ noSearchResults ? 'No shops match your search.' : 'No repair shops are currently available.' }}</p>
        <p v-if="!noSearchResults" class="mt-2 text-sm leading-6 text-slate-600">Please check again later for shops accepting consultation requests.</p>
        <p v-else class="mt-2 text-sm leading-6 text-slate-600">Try another shop name, location, or service.</p>
      </div>

      <div v-else>
        <p v-if="loading" class="mt-5 text-center text-xs text-slate-500" role="status" aria-live="polite">Updating shop results...</p>
        <div class="mt-8 grid items-stretch gap-5 sm:grid-cols-2 xl:grid-cols-3">
          <article v-for="shop in shops" :key="shop.id" class="flex h-full flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-teal-200 hover:shadow-lg">
            <div class="flex items-start gap-4">
              <div v-if="showLogo(shop)" class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-slate-100 bg-slate-50">
                <img :src="shop.logo_url" :alt="`${shop.name} logo`" class="h-full w-full object-contain p-2" @error="failedLogos.add(shop.id)" />
              </div>
              <div v-else class="flex h-16 w-16 shrink-0 items-center justify-center rounded-xl bg-teal-50 text-lg font-black text-teal-800" aria-hidden="true">{{ shopInitials(shop.name) }}</div>
              <div class="min-w-0 pt-1">
                <h3 class="break-words text-base font-bold text-[#0f1b2d]">{{ shop.name }}</h3>
                <p v-if="shop.address" class="mt-1 break-words text-sm leading-5 text-slate-600">{{ shop.address }}</p>
                <p v-else class="mt-1 text-sm leading-5 text-slate-500">Address not yet provided.</p>
              </div>
            </div>

            <div class="mt-5 flex-1">
              <p class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Available services</p>
              <div v-if="shop.services?.length" class="mt-2 flex flex-wrap gap-2">
                <span v-for="service in shop.services" :key="service" class="rounded-full bg-teal-50 px-3 py-1 text-xs font-semibold text-teal-800">{{ service }}</span>
                <span v-if="shop.services_more_count > 0" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">+{{ shop.services_more_count }} more</span>
              </div>
              <p v-else class="mt-2 text-sm text-slate-500">No services configured yet.</p>
            </div>

            <AppLink
              :to="`/shops/${encodeURIComponent(shop.slug)}`"
              class="mt-6 inline-flex min-h-11 items-center justify-center rounded-xl bg-[#0f1b2d] px-4 text-sm font-bold text-white transition hover:bg-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 focus-visible:ring-offset-2"
            >
              View Shop
            </AppLink>
          </article>
        </div>

        <nav v-if="pagination.last_page > 1" class="mt-8 flex items-center justify-center gap-4" aria-label="Shop directory pages">
          <button type="button" class="min-h-10 rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:border-teal-500 hover:text-teal-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="loading || searchPending || page <= 1" @click="changePage(page - 1)">
            Previous
          </button>
          <span class="text-sm font-medium text-slate-600" aria-live="polite">Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
          <button type="button" class="min-h-10 rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:border-teal-500 hover:text-teal-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="loading || searchPending || page >= pagination.last_page" @click="changePage(page + 1)">
            Next
          </button>
        </nav>
      </div>
    </div>
  </section>
</template>
