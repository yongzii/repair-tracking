<script setup>
import { computed, ref } from 'vue';
import AppLink from '../components/AppLink.vue';
import { navigate } from '../navigation';
import { jobOrderStatusClass } from '../composables/jobOrderStatus';

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
const photos = ref([]);
const warrantyAssessments = ref([]);
const shop = ref(null);
const shopOptions = ref([]);
const consultationPath = computed(() => {
    const normalizedShop = shopSlug.value.trim().toLowerCase();
    return normalizedShop ? `/shops/${encodeURIComponent(normalizedShop)}/consultation` : '/consultation';
});
const businessDays = [
    { key: 'monday', label: 'Mon' },
    { key: 'tuesday', label: 'Tue' },
    { key: 'wednesday', label: 'Wed' },
    { key: 'thursday', label: 'Thu' },
    { key: 'friday', label: 'Fri' },
    { key: 'saturday', label: 'Sat' },
    { key: 'sunday', label: 'Sun' },
];
const todayKey = computed(() => businessDays[(new Date().getDay() + 6) % 7].key);

const formatTime = (value) => {
    if (!value) return '';
    const [hourValue, minute = '00'] = value.split(':');
    const hour = Number(hourValue);
    const suffix = hour >= 12 ? 'PM' : 'AM';
    const displayHour = hour % 12 || 12;
    return `${displayHour}:${minute} ${suffix}`;
};

const formatHours = (day) => {
    if (!day?.open) return 'Closed';
    return `${formatTime(day.open_time)} - ${formatTime(day.close_time)}`;
};

const todayHours = computed(() => {
    const hours = shop.value?.business_hours?.[todayKey.value];
    return hours ? formatHours(hours) : '';
});

const formatStatus = (value) => {
    if (value === 'PICKED_UP') return 'RELEASED';
    if (value === 'RTO_RELEASED') return 'RTO RELEASED';
    if (value === 'RTO') return 'RTO';
    return value ? value.replaceAll('_', ' ') : '';
};

const statusMessage = computed(() => {
    if (!jobOrder.value) return '';

    return {
        RECEIVED: 'Your unit has been received and registered by the shop.',
        DIAGNOSING: 'The shop is checking the unit and confirming the repair work needed.',
        WAITING_PARTS: 'The repair is waiting for required parts or supply confirmation.',
        IN_PROGRESS: 'Repair work is currently in progress.',
        READY_FOR_PICKUP: 'Your unit is ready. Please coordinate with the shop before pickup.',
        PICKED_UP: 'Your unit has already been released.',
        RTO: 'The repair attempt has ended and your unit is waiting to be returned.',
        RTO_RELEASED: 'Your RTO unit has been released.',
    }[jobOrder.value.current_status] || 'The shop has updated your repair status.';
});

const deviceLabel = computed(() => {
    if (!jobOrder.value) return '';

    return [jobOrder.value.brand, jobOrder.value.model].filter(Boolean).join(' ') || jobOrder.value.device_type;
});

const fetchTracking = async (number) => {
    loading.value = true;
    errorMessage.value = '';
    shopOptions.value = [];

  const normalizedShop = shopSlug.value.trim().toLowerCase();
  if (!normalizedShop) {
    shop.value = null;
    jobOrder.value = null;
    timeline.value = [];
    photos.value = [];
    warrantyAssessments.value = [];
    errorMessage.value = 'Shop code is required for tracking.';
    loading.value = false;
    return;
  }

    try {
    const request = window.axios.get(`/api/shops/${encodeURIComponent(normalizedShop)}/track/${encodeURIComponent(number)}`);
        const { data } = await request;
        shop.value = data.shop || null;
        jobOrder.value = data.jobOrder;
        timeline.value = data.timeline || [];
        photos.value = data.photos || [];
        warrantyAssessments.value = data.warrantyAssessments || [];
    } catch (error) {
        shop.value = null;
        jobOrder.value = null;
        timeline.value = [];
        photos.value = [];
        warrantyAssessments.value = [];
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
  if (!normalizedShop) {
    errorMessage.value = 'Shop code is required for tracking.';
        return;
    }
  navigate(`/shops/${encodeURIComponent(normalizedShop)}/track/${encodeURIComponent(normalized)}`);
};
</script>

<template>
  <div class="tracking-page public-shell px-4 py-8">
    <div class="mx-auto max-w-5xl space-y-6">
      <div class="tracking-brand">
        <AppLink to="/" aria-label="RepairSync Cloud home">
          <span class="tracking-brand-frame">
            <img src="/images/repairsync-cloud-logo.png" alt="RepairSync Cloud" class="tracking-brand-logo" />
          </span>
        </AppLink>
      </div>

      <div class="public-card overflow-hidden">
        <div class="tracking-hero border-b border-[var(--ui-border)] bg-[var(--ui-brand-950)] p-6 text-white sm:p-8">
          <div class="flex flex-col gap-5 md:flex-row md:items-start md:justify-between">
            <div>
              <p class="text-xs font-bold uppercase tracking-[0.14em] text-teal-300">Customer repair tracking</p>
              <h1 class="mt-3 font-display text-4xl leading-tight">Track Your Repair</h1>
              <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-300">
                View the latest repair status, warranty details, and customer-visible proof photos.
              </p>
            </div>
            <div class="flex flex-wrap gap-2">
              <AppLink :to="consultationPath" class="rounded-lg bg-white/10 px-3 py-2 text-sm font-bold text-white hover:bg-white/15">Consultation</AppLink>
              <AppLink to="/" class="rounded-lg bg-white px-3 py-2 text-sm font-bold text-[var(--ui-text)] hover:bg-[var(--ui-surface-subtle)]">Home</AppLink>
            </div>
          </div>
        </div>

        <form class="tracking-form grid gap-3 p-6 sm:grid-cols-[0.8fr_1.2fr_auto]" @submit.prevent="onSearch">
          <input
            v-model="shopSlug"
            type="text"
            placeholder="Shop code (recommended)"
            class="w-full border border-[var(--ui-border-strong)] px-4 py-3"
            required
          />
          <input
            v-model="jobOrderNumber"
            type="text"
            placeholder="Enter Job Order Number"
            class="w-full border border-[var(--ui-border-strong)] px-4 py-3"
            required
          />
          <button type="submit" class="btn-primary px-6 py-3">
            Search
          </button>
        </form>
      </div>

      <div v-if="loading" class="public-card p-6 text-[var(--ui-text-muted)]">Loading tracking details...</div>
      <div v-if="errorMessage" class="rounded-xl border border-rose-200 bg-rose-50 p-6 text-rose-700">{{ errorMessage }}</div>
      <div v-if="shopOptions.length" class="public-card p-6">
        <h3 class="mb-3 text-lg font-bold text-[var(--ui-text-strong)]">Choose Your Shop</h3>
        <div class="space-y-3">
          <AppLink
            v-for="option in shopOptions"
            :key="option.slug"
            :to="`/shops/${option.slug}/track/${encodeURIComponent(jobOrderNumber.trim().toUpperCase())}`"
            class="block rounded-xl border border-[var(--ui-border)] px-4 py-3 hover:border-[var(--ui-focus)] hover:bg-[var(--ui-surface-subtle)]"
          >
            <p class="font-bold text-[var(--ui-text-strong)]">{{ option.name }}</p>
            <p class="text-sm text-[var(--ui-text-muted)]">Shop code: {{ option.slug }}</p>
          </AppLink>
        </div>
      </div>

      <div v-if="jobOrder" class="space-y-6">
        <div v-if="shop" class="public-card p-6">
          <div class="flex items-center gap-4">
            <div v-if="shop.logo_url" class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-[var(--ui-border)] bg-[var(--ui-surface-muted)]">
              <img :src="shop.logo_url" alt="Shop logo" class="h-full w-full object-contain p-2" />
            </div>
            <div>
              <h2 class="mb-2 text-xl font-bold text-[var(--ui-text-strong)]">{{ shop.name }}</h2>
              <p v-if="shop.phone" class="text-sm text-[var(--ui-text-muted)]">{{ shop.phone }}</p>
              <p v-if="shop.address" class="text-sm text-[var(--ui-text-muted)]">{{ shop.address }}</p>
              <p v-if="todayHours" class="mt-2 text-sm font-semibold text-teal-700">Today: {{ todayHours }}</p>
            </div>
          </div>
        </div>

        <div class="public-card p-6">
          <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
              <p class="text-xs font-bold uppercase text-[var(--ui-text-subtle)]">Job Order</p>
              <div class="mt-1 flex flex-wrap items-center gap-2">
                <h2 class="text-2xl font-bold text-[var(--ui-text-strong)]">{{ jobOrder.job_order_number }}</h2>
                <span v-if="jobOrder.is_warranty_service" class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold uppercase text-emerald-700 ring-1 ring-emerald-200">
                  Under Warranty
                </span>
              </div>
            </div>
            <span class="rounded-full px-3 py-1 text-xs font-bold ring-1" :class="jobOrderStatusClass(jobOrder.current_status)">{{ formatStatus(jobOrder.current_status) }}</span>
          </div>
          <div class="mb-5 rounded-xl border border-[var(--ui-border)] bg-[var(--ui-surface-muted)] p-4">
            <p class="text-xs font-bold uppercase text-[var(--ui-text-subtle)]">Latest Update</p>
            <p class="mt-1 font-semibold text-[var(--ui-text-strong)]">{{ statusMessage }}</p>
          </div>
          <div v-if="jobOrder.is_warranty_service" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            <p class="font-bold">UNDER WARRANTY SERVICE</p>
            <p class="mt-1">
              This is a separate warranty service tracking number for the returned unit.
            </p>
            <p v-if="jobOrder.warranty_parent_job_order" class="mt-2 text-xs font-semibold text-emerald-800">
              Original repair reference: {{ jobOrder.warranty_parent_job_order.job_order_number }}
            </p>
          </div>
          <div v-if="jobOrder.current_status === 'PICKED_UP'" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            <p class="font-bold">RELEASED</p>
            <p class="mt-1">
              This unit was released<span v-if="jobOrder.picked_up_at_formatted"> on {{ jobOrder.picked_up_at_formatted }}</span>.
            </p>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div>
              <p class="text-[var(--ui-text-muted)]">Customer</p>
              <p class="font-semibold text-[var(--ui-text)]">{{ jobOrder.customer_name }}</p>
            </div>
            <div>
              <p class="text-[var(--ui-text-muted)]">Device</p>
              <p class="font-semibold text-[var(--ui-text)]">{{ deviceLabel }}</p>
              <p v-if="jobOrder.device_type && deviceLabel !== jobOrder.device_type" class="text-xs text-[var(--ui-text-muted)]">{{ jobOrder.device_type }}</p>
            </div>
            <div>
              <p class="text-[var(--ui-text-muted)]">Current Status</p>
              <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold ring-1" :class="jobOrderStatusClass(jobOrder.current_status)">{{ formatStatus(jobOrder.current_status) }}</span>
            </div>
            <div>
              <p class="text-[var(--ui-text-muted)]">Received</p>
              <p class="font-semibold text-[var(--ui-text)]">{{ jobOrder.created_at_formatted }}</p>
            </div>
          </div>
        </div>

        <div v-if="jobOrder.warranty_days > 0 || warrantyAssessments.length" class="public-card p-6">
          <h3 class="mb-4 text-lg font-bold text-[var(--ui-text-strong)]">Warranty</h3>
          <div v-if="jobOrder.warranty_days > 0" class="rounded-xl border border-[var(--ui-border)] bg-[var(--ui-surface-muted)] p-4">
            <p class="font-bold" :class="jobOrder.is_under_warranty ? 'text-emerald-700' : 'text-slate-700'">
              {{ jobOrder.is_under_warranty ? 'WARRANTY ACTIVE' : 'WARRANTY PERIOD ENDED' }}
            </p>
            <p class="mt-1 text-sm text-[var(--ui-text-muted)]">
              Coverage: {{ jobOrder.warranty_days }} days
              <span v-if="jobOrder.warranty_expires_at_formatted"> - Until {{ jobOrder.warranty_expires_at_formatted }}</span>
            </p>
            <p v-if="jobOrder.warranty_details" class="mt-2 text-sm text-[var(--ui-text)]">{{ jobOrder.warranty_details }}</p>
            <p class="mt-3 text-sm text-[var(--ui-text-muted)]">
              For warranty concerns, bring the unit and warranty slip to the shop for physical assessment.
            </p>
          </div>

          <div v-if="warrantyAssessments.length" class="mt-4 space-y-3">
            <div v-for="assessment in warrantyAssessments" :key="assessment.id" class="rounded-xl border border-[var(--ui-border)] p-4">
              <p class="font-bold text-[var(--ui-text-strong)]">{{ assessment.warranty_job_order ? 'WARRANTY SERVICE ISSUED' : assessment.result_label }}</p>
              <p class="text-xs text-[var(--ui-text-muted)]">{{ assessment.brought_at_formatted || assessment.created_at_formatted }}</p>
              <p class="mt-2 text-sm text-[var(--ui-text)]">{{ assessment.reported_issue }}</p>
              <p v-if="assessment.warranty_job_order" class="mt-2 text-sm font-semibold text-teal-700">
                Warranty service job: {{ assessment.warranty_job_order.job_order_number }}
              </p>
            </div>
          </div>
        </div>

        <div class="public-card p-6">
          <h3 class="mb-4 text-lg font-bold text-[var(--ui-text-strong)]">Timeline</h3>
          <div class="space-y-4">
            <div v-for="entry in timeline" :key="entry.id || entry.created_at" class="relative border-l-2 border-teal-200 pb-5 pl-5 last:pb-0">
              <span class="absolute -left-[7px] top-1 h-3 w-3 rounded-full bg-teal-500 ring-4 ring-teal-50"></span>
              <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold ring-1" :class="jobOrderStatusClass(entry.status)">
                {{ formatStatus(entry.status) }}
              </span>
              <p class="text-sm text-[var(--ui-text-muted)]">{{ entry.created_at_formatted }}</p>
              <p v-if="entry.note" class="mt-1 text-sm text-[var(--ui-text)]">{{ entry.note }}</p>
            </div>
            <p v-if="!timeline.length" class="text-sm text-[var(--ui-text-muted)]">No status history available.</p>
          </div>
        </div>

        <div v-if="photos.length" class="public-card p-6">
          <div class="mb-4">
            <h3 class="text-lg font-bold text-[var(--ui-text-strong)]">Repair Photos</h3>
            <p class="mt-1 text-sm text-[var(--ui-text-muted)]">Only photos approved by the shop for customer viewing are shown here.</p>
          </div>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div v-for="photo in photos" :key="photo.id" class="overflow-hidden rounded-xl border border-[var(--ui-border)] bg-[var(--ui-surface)]">
              <a :href="photo.url" target="_blank" rel="noopener" class="block aspect-[4/3] bg-[var(--ui-surface-muted)]">
                <img :src="photo.url" :alt="photo.photo_type_label" class="h-full w-full object-cover" />
              </a>
              <div class="space-y-1 p-3">
                <p class="text-sm font-bold text-[var(--ui-text-strong)]">{{ photo.photo_type_label }}</p>
                <p class="text-xs text-[var(--ui-text-muted)]">{{ photo.created_at_formatted }}</p>
                <p v-if="photo.description" class="text-sm text-[var(--ui-text)]">{{ photo.description }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.tracking-page {
  background: var(--ui-page);
}

.tracking-brand {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0 0.25rem;
}

.tracking-brand-frame {
  display: block;
  width: min(100%, 12rem);
  height: 2.8rem;
  overflow: hidden;
}

.tracking-brand-logo {
  display: block;
  width: 100%;
  height: auto;
  transform: translateY(-35%);
}

.tracking-hero h1 {
  letter-spacing: -0.025em;
}

.tracking-form input {
  min-height: var(--ui-control-height);
  border-radius: var(--ui-radius-md);
  background: var(--ui-surface);
  color: var(--ui-text);
  transition: border-color 0.18s ease, box-shadow 0.18s ease;
}

.tracking-form input:focus {
  border-color: var(--ui-focus);
  box-shadow: 0 0 0 4px rgba(13, 148, 136, 0.14);
  outline: none;
}

.tracking-form button {
  min-height: var(--ui-control-height);
  border-radius: var(--ui-radius-md);
  font-weight: 700;
}

@media (max-width: 36rem) {
  .tracking-page {
    padding: 1.25rem 0.75rem;
  }

  .tracking-brand {
    align-items: flex-start;
    flex-direction: column;
    gap: 0.25rem;
  }

  .tracking-brand-frame {
    width: 10.5rem;
    height: 2.5rem;
  }

  .tracking-hero h1 {
    font-size: 2.25rem;
  }
}
</style>
