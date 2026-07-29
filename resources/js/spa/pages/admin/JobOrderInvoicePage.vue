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
const invoiceData = ref(null);
const qrContainer = ref(null);

const formatCurrency = (value) => {
    const amount = Number(value || 0);
    return `PHP ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

const customerContact = computed(() => {
    if (!invoiceData.value) return 'N/A';
    return invoiceData.value.customer.phone || invoiceData.value.customer.email || invoiceData.value.customer.contact || 'N/A';
});

const documentTitle = computed(() => invoiceData.value?.document?.title || 'Invoice');
const documentEyebrow = computed(() => invoiceData.value?.document?.eyebrow || 'Invoice');
const printLabel = computed(() => invoiceData.value?.document?.print_label || 'Print Invoice');
const isWarrantyService = computed(() => Boolean(invoiceData.value?.document?.is_warranty_service));

const renderQr = async () => {
    await nextTick();
    if (!qrContainer.value || !invoiceData.value?.invoice?.qr_value) return;

    qrContainer.value.innerHTML = '';

    if (window.QRCode) {
        // qrcodejs loaded from app.blade.php
        new window.QRCode(qrContainer.value, {
            text: invoiceData.value.invoice.qr_value,
            width: 140,
            height: 140,
            colorDark: '#0f172a',
            colorLight: '#ffffff',
            correctLevel: window.QRCode.CorrectLevel.M,
        });
    } else {
        qrContainer.value.innerHTML = `<p class="text-xs text-slate-500 break-all">${invoiceData.value.invoice.qr_value}</p>`;
    }
};

const loadInvoice = async () => {
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
        invoiceData.value = data;
    } catch (error) {
        forbidden.value = true;
        errorMessage.value = error?.response?.data?.message || 'Unable to load document.';
    } finally {
        loading.value = false;
    }

    if (invoiceData.value) {
        await renderQr();
    }
};

const printInvoice = () => {
    window.print();
};

onMounted(loadInvoice);
</script>

<template>
  <AdminLayout :title="invoiceData?.invoice?.invoice_number || documentTitle">
    <div v-if="loading" class="card p-6 text-slate-600">Loading document...</div>
    <div v-else-if="forbidden" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">{{ errorMessage || 'You do not have access to this page.' }}</div>

    <div v-else-if="invoiceData" class="space-y-6 print-space-y-3">
      <div class="flex flex-wrap items-center justify-between gap-3 no-print">
        <div class="flex gap-3">
          <AppLink :to="`/admin/job-orders/${props.params.id}`" class="px-4 py-2 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold">Back to Job Order</AppLink>
          <AppLink to="/admin/job-orders" class="px-4 py-2 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold">Back to List</AppLink>
        </div>
        <button type="button" class="px-5 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white font-semibold" @click="printInvoice">
          {{ printLabel }}
        </button>
      </div>

      <div class="card p-8 invoice-paper">
        <div class="flex items-start justify-between gap-4 border-b border-slate-200 pb-6">
          <div class="flex items-start gap-4">
            <div v-if="invoiceData.shop.logo_url" class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-white">
              <img :src="invoiceData.shop.logo_url" alt="Shop logo" class="h-full w-full object-contain p-2" />
            </div>
            <div>
              <h1 class="text-3xl font-display text-slate-900">{{ invoiceData.shop.name }}</h1>
              <p class="text-slate-500">{{ invoiceData.shop.tagline }}</p>
              <p class="text-xs text-slate-400 mt-2">Track URL: {{ invoiceData.invoice.track_url }}</p>
            </div>
          </div>
          <div class="text-right">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">{{ documentEyebrow }}</p>
            <p class="text-2xl font-semibold text-slate-900">{{ invoiceData.invoice.invoice_number }}</p>
            <p class="text-sm text-slate-600 mt-1">Issued: {{ invoiceData.invoice.issued_at_formatted }}</p>
          </div>
        </div>

        <div v-if="isWarrantyService" class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-emerald-900">
          <p class="font-bold">Under Warranty Service - No Charge</p>
          <p class="mt-1 text-sm">
            This document summarizes the warranty service job. It is not a charge invoice unless the shop records an approved exception.
          </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-6">
          <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div>
              <p class="text-slate-500 uppercase text-xs font-semibold mb-1">Customer</p>
              <p class="font-semibold text-slate-900">{{ invoiceData.customer.name }}</p>
              <p class="text-slate-700">{{ customerContact }}</p>
            </div>
            <div>
              <p class="text-slate-500 uppercase text-xs font-semibold mb-1">Track Number</p>
              <p class="font-semibold text-slate-900">{{ invoiceData.invoice.track_number }}</p>
            </div>
            <div>
              <p class="text-slate-500 uppercase text-xs font-semibold mb-1">Device</p>
              <p class="font-semibold text-slate-900">{{ invoiceData.device.type }}</p>
              <p class="text-slate-700">{{ [invoiceData.device.brand, invoiceData.device.model].filter(Boolean).join(' ') || 'N/A' }}</p>
              <p class="text-slate-700">SN: {{ invoiceData.device.serial_number || 'N/A' }}</p>
            </div>
            <div>
              <p class="text-slate-500 uppercase text-xs font-semibold mb-1">Issue</p>
              <p class="text-slate-700">{{ invoiceData.device.problem_description }}</p>
            </div>
          </div>

          <div class="border border-slate-200 rounded-lg p-4 flex flex-col items-center justify-center text-center">
            <div ref="qrContainer" class="w-[140px] h-[140px] bg-white"></div>
            <p class="text-xs text-slate-500 mt-3">Scan to track repair progress</p>
            <p class="text-xs font-semibold text-slate-700 mt-1">{{ invoiceData.invoice.track_number }}</p>
          </div>
        </div>

        <div class="mt-6 overflow-x-auto">
          <table class="w-full text-sm border border-slate-200 rounded-lg overflow-hidden">
            <thead class="bg-slate-100">
              <tr>
                <th class="px-4 py-3 text-left text-slate-600">Description</th>
                <th class="px-4 py-3 text-right text-slate-600">Amount</th>
              </tr>
            </thead>
            <tbody class="divide-y">
              <tr>
                <td class="px-4 py-3 text-slate-700">{{ isWarrantyService ? 'Warranty Service Charge' : 'Estimated Cost' }}</td>
                <td class="px-4 py-3 text-right text-slate-800">{{ formatCurrency(invoiceData.costs.estimated_cost) }}</td>
              </tr>
              <tr v-if="!isWarrantyService">
                <td class="px-4 py-3 text-slate-700">Partial Payment</td>
                <td class="px-4 py-3 text-right text-slate-800">{{ formatCurrency(invoiceData.costs.partial_payment) }}</td>
              </tr>
              <tr class="bg-slate-50">
                <td class="px-4 py-3 font-semibold text-slate-900">{{ isWarrantyService ? 'Customer Balance' : 'Remaining (Estimate)' }}</td>
                <td class="px-4 py-3 text-right font-semibold" :class="isWarrantyService ? 'text-emerald-700' : 'text-rose-700'">{{ formatCurrency(invoiceData.costs.estimated_balance) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="mt-8 text-xs text-slate-500 border-t border-slate-200 pt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
          <p>
            Customer Copy: Keep this {{ isWarrantyService ? 'service summary' : 'invoice' }} and track number for status updates.
            You can scan the QR code or visit the tracking page.
          </p>
          <p class="md:text-right">
            Thank you for trusting {{ invoiceData.shop.name }}.
          </p>
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

  .invoice-paper {
    box-shadow: none !important;
    border: 1px solid #e2e8f0;
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
