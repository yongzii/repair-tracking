<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AppLink from '../../components/AppLink.vue';
import { ensureRoles } from '../../composables/auth';
import { navigate } from '../../navigation';

const props = defineProps({
    params: {
        type: Object,
        default: () => ({}),
    },
});

const loading = ref(true);
const forbidden = ref(false);
const errorMessage = ref('');
const summaryData = ref(null);
const qrContainer = ref(null);

const isEligibleWarrantyService = computed(() => {
    return summaryData.value?.document?.type === 'service_summary'
        && summaryData.value?.document?.is_warranty_service === true;
});

const renderQr = async () => {
    await nextTick();
    if (!qrContainer.value || !summaryData.value?.invoice?.qr_value) return;

    qrContainer.value.innerHTML = '';
    if (window.QRCode) {
        new window.QRCode(qrContainer.value, {
            text: summaryData.value.invoice.qr_value,
            width: 140,
            height: 140,
            colorDark: '#0f172a',
            colorLight: '#ffffff',
            correctLevel: window.QRCode.CorrectLevel.M,
        });
    } else {
        qrContainer.value.textContent = summaryData.value.invoice.qr_value;
    }
};

const loadSummary = async () => {
    const auth = await ensureRoles(['admin', 'staff']);
    if (!auth.ok) {
        if (auth.reason === 'unauthenticated') {
            navigate('/login', { replace: true });
            return;
        }
        forbidden.value = true;
        loading.value = false;
        return;
    }

    try {
        const { data } = await window.axios.get(`/api/admin/job-orders/${props.params.id}/invoice`);
        if (data.document?.type !== 'service_summary' || data.document?.is_warranty_service !== true) {
            forbidden.value = true;
            errorMessage.value = 'Warranty Service Summary is only available for warranty service jobs.';
            return;
        }
        summaryData.value = data;
    } catch (error) {
        forbidden.value = true;
        errorMessage.value = error?.response?.data?.message || 'Unable to load Warranty Service Summary.';
    } finally {
        loading.value = false;
    }

    if (summaryData.value) {
        await renderQr();
    }
};

const printSummary = () => window.print();

onMounted(loadSummary);
</script>

<template>
  <AdminLayout title="Warranty Service Summary">
    <div v-if="loading" class="card p-6 text-slate-600">Loading document...</div>
    <div v-else-if="forbidden" class="card border border-rose-200 bg-rose-50 p-6 text-rose-700">{{ errorMessage || 'You do not have access to this page.' }}</div>

    <div v-else-if="summaryData" class="space-y-6 print-space-y-3">
      <div class="no-print flex flex-wrap items-center justify-between gap-3">
        <div class="flex gap-3">
          <AppLink :to="`/admin/job-orders/${props.params.id}`" class="rounded-lg bg-slate-100 px-4 py-2 font-semibold text-slate-700 hover:bg-slate-200">Back to Job Order</AppLink>
          <AppLink to="/admin/job-orders" class="rounded-lg bg-slate-100 px-4 py-2 font-semibold text-slate-700 hover:bg-slate-200">Back to List</AppLink>
        </div>
        <button type="button" class="rounded-lg bg-slate-900 px-5 py-2 font-semibold text-white hover:bg-slate-800" @click="printSummary">Print Service Summary</button>
      </div>

      <div class="card warranty-summary-paper p-8">
        <div class="flex items-start justify-between gap-4 border-b border-slate-200 pb-6">
          <div class="flex items-start gap-4">
            <div v-if="summaryData.shop.logo_url" class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-white">
              <img :src="summaryData.shop.logo_url" alt="Shop logo" class="h-full w-full object-contain p-2" />
            </div>
            <div>
              <h1 class="text-3xl font-display text-slate-900">{{ summaryData.shop.name }}</h1>
              <p class="text-slate-500">{{ summaryData.shop.tagline }}</p>
            </div>
          </div>
          <div class="text-right">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Under Warranty Service</p>
            <p class="text-2xl font-semibold text-slate-900">{{ summaryData.invoice.invoice_number }}</p>
            <p class="mt-1 text-sm text-slate-600">Issued: {{ summaryData.invoice.issued_at_formatted }}</p>
          </div>
        </div>

        <div class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-emerald-900">
          <p class="font-bold">Under Warranty Service - No Charge</p>
          <p class="mt-1 text-sm">This document summarizes the warranty service job. It is not a charge invoice unless the shop records an approved exception.</p>
        </div>

        <div class="mt-6 grid grid-cols-1 gap-6 md:grid-cols-3">
          <div class="grid grid-cols-1 gap-4 text-sm md:col-span-2 md:grid-cols-2">
            <div>
              <p class="mb-1 text-xs font-semibold uppercase text-slate-500">Customer</p>
              <p class="font-semibold text-slate-900">{{ summaryData.customer.name }}</p>
            </div>
            <div>
              <p class="mb-1 text-xs font-semibold uppercase text-slate-500">Job Order</p>
              <p class="font-semibold text-slate-900">{{ summaryData.invoice.track_number }}</p>
            </div>
            <div>
              <p class="mb-1 text-xs font-semibold uppercase text-slate-500">Device</p>
              <p class="font-semibold text-slate-900">{{ summaryData.device.type }}</p>
              <p class="text-slate-700">{{ [summaryData.device.brand, summaryData.device.model].filter(Boolean).join(' ') || 'N/A' }}</p>
              <p class="text-slate-700">SN: {{ summaryData.device.serial_number || 'N/A' }}</p>
            </div>
            <div>
              <p class="mb-1 text-xs font-semibold uppercase text-slate-500">Issue</p>
              <p class="text-slate-700">{{ summaryData.device.problem_description }}</p>
            </div>
          </div>

          <div class="flex flex-col items-center justify-center rounded-lg border border-slate-200 p-4 text-center">
            <div ref="qrContainer" class="h-[140px] w-[140px] bg-white"></div>
            <p class="mt-3 text-xs text-slate-500">Scan to track repair progress</p>
            <p class="mt-1 text-xs font-semibold text-slate-700">{{ summaryData.invoice.track_number }}</p>
          </div>
        </div>

        <div v-if="isEligibleWarrantyService" class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 p-5 text-emerald-900">
          <div class="flex flex-wrap items-center justify-between gap-4">
            <p class="font-bold">Warranty Service: No Additional Charge</p>
            <p class="text-lg font-bold">Amount Due: ₱0.00</p>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

<style scoped>
@media print {
  .no-print {
    display: none !important;
  }

  .warranty-summary-paper {
    border: 1px solid #e2e8f0;
    box-shadow: none !important;
  }

  :deep(.content-header),
  :deep(.sidebar) {
    display: none !important;
  }

  :deep(.app-shell),
  :deep(.p-6) {
    padding: 0 !important;
    margin: 0 !important;
    background: #fff !important;
  }
}
</style>
