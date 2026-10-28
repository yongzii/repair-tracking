<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import { ensureAdmin } from '../../composables/auth';
import { jobOrderStatusClass } from '../../composables/jobOrderStatus';
import { navigate } from '../../navigation';

const loading = ref(true);
const forbidden = ref(false);
const report = ref(null);
const reportMode = ref('operations');

const filters = reactive({
    start_date: '',
    end_date: '',
    status: '',
    service_type: '',
    service_category_id: '',
    service_item_id: '',
    payment_method: '',
    payment_type: '',
});

const serviceCatalog = computed(() => report.value?.serviceCatalog || []);
const serviceOptions = computed(() => {
    const category = serviceCatalog.value.find((item) => String(item.id) === String(filters.service_category_id));
    return category?.services || [];
});

const formatCurrency = (value) => {
    const amount = Number(value || 0);
    return `PHP ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

const setReportMode = (mode) => {
    reportMode.value = mode;

    if (mode === 'operations') {
        filters.payment_method = '';
        filters.payment_type = '';
    } else {
        filters.status = '';
        filters.service_type = '';
        filters.service_category_id = '';
        filters.service_item_id = '';
    }
};

const loadReport = async () => {
    const auth = await ensureAdmin();
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
        const { data } = await window.axios.get('/api/admin/reports', { params: filters });
        report.value = data;
    } catch {
        forbidden.value = true;
    } finally {
        loading.value = false;
    }
};

const downloadCsv = () => {
    const params = {
        ...Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '')),
        report_type: reportMode.value,
    };
    const query = new URLSearchParams(params).toString();
    window.location.href = `/api/admin/reports/export/csv${query ? `?${query}` : ''}`;
};

const downloadPdf = () => {
    const params = {
        ...Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '')),
        report_type: reportMode.value,
    };
    const query = new URLSearchParams(params).toString();
    window.location.href = `/api/admin/reports/export/pdf${query ? `?${query}` : ''}`;
};

const paymentStateClass = (state) => {
    if (state === 'APPLIED') return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    if (state === 'NEEDS_REVIEW') return 'bg-amber-50 text-amber-700 ring-amber-200';
    return 'bg-slate-100 text-slate-700 ring-slate-200';
};

const formatStatus = (status) => {
    if (status === 'PICKED_UP') return 'RELEASED';
    if (status === 'RTO_RELEASED') return 'RTO RELEASED';
    if (status === 'RTO') return 'RTO';
    return status ? status.replaceAll('_', ' ') : '';
};

const formatServiceType = (serviceType) => {
    if (serviceType === 'WARRANTY_SERVICE') return 'Under Warranty';
    return 'Standard Repair';
};

watch(
    () => filters.service_category_id,
    () => {
        if (!filters.service_item_id) return;
        const stillAvailable = serviceOptions.value.some((service) => String(service.id) === String(filters.service_item_id));
        if (!stillAvailable) {
            filters.service_item_id = '';
        }
    }
);

onMounted(loadReport);
</script>

<template>
  <AdminLayout title="Reports">
    <div v-if="loading" class="card p-6 text-slate-600">Loading reports...</div>
    <div v-else-if="forbidden" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">You do not have access to this page.</div>

    <div v-else class="space-y-6">
      <section class="admin-hero p-6">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <p class="text-xs font-bold uppercase text-teal-700">Operational reporting</p>
            <h3 class="mt-2 font-display text-3xl">Reports and financial summaries</h3>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Switch between repair operations and payment collections, then export only the report you need.</p>
          </div>
          <div class="flex flex-wrap gap-3">
            <button type="button" class="rounded-lg bg-emerald-500 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-400" @click="downloadCsv">Export CSV</button>
            <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:border-teal-200 hover:text-teal-700" @click="downloadPdf">Export PDF</button>
          </div>
        </div>
      </section>

      <div class="admin-panel p-6">
        <h3 class="text-lg font-bold text-slate-950 mb-4">Filters</h3>
        <div class="mb-5 inline-flex rounded-lg border border-slate-200 bg-slate-50 p-1">
          <button
            type="button"
            class="rounded-md px-4 py-2 text-sm font-bold"
            :class="reportMode === 'operations' ? 'bg-slate-950 text-white shadow-sm' : 'text-slate-600 hover:text-slate-950'"
            @click="setReportMode('operations')"
          >
            Operations
          </button>
          <button
            type="button"
            class="rounded-md px-4 py-2 text-sm font-bold"
            :class="reportMode === 'payments' ? 'bg-slate-950 text-white shadow-sm' : 'text-slate-600 hover:text-slate-950'"
            @click="setReportMode('payments')"
          >
            Payments
          </button>
        </div>
        <form class="space-y-5" @submit.prevent="loadReport">
          <div>
            <p class="mb-2 text-xs font-bold uppercase text-slate-400">Date Range</p>
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
              <input v-model="filters.start_date" type="date" class="border border-slate-300 px-4 py-3" />
              <input v-model="filters.end_date" type="date" class="border border-slate-300 px-4 py-3" />
            </div>
          </div>

          <div v-if="reportMode === 'operations'">
            <p class="mb-2 text-xs font-bold uppercase text-slate-400">Job Orders</p>
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
              <select v-model="filters.status" class="border border-slate-300 px-4 py-3">
                <option value="">All Statuses</option>
                <option value="OPEN">OPEN / PENDING JOBS</option>
                <option value="RECEIVED">RECEIVED</option>
                <option value="DIAGNOSING">DIAGNOSING</option>
                <option value="WAITING_PARTS">WAITING PARTS</option>
                <option value="IN_PROGRESS">IN PROGRESS</option>
                <option value="READY_FOR_PICKUP">READY FOR PICKUP</option>
                <option value="PICKED_UP">RELEASED</option>
                <option value="RTO">RTO AWAITING RETURN</option>
                <option value="RTO_RELEASED">RTO RELEASED</option>
              </select>
              <select v-model="filters.service_type" class="border border-slate-300 px-4 py-3">
                <option value="">All Service Types</option>
                <option value="STANDARD">Standard Repair</option>
                <option value="WARRANTY_SERVICE">Under Warranty</option>
              </select>
              <select v-model="filters.service_category_id" class="border border-slate-300 px-4 py-3">
                <option value="">All Categories</option>
                <option v-for="category in serviceCatalog" :key="category.id" :value="String(category.id)">{{ category.name }}</option>
              </select>
              <select v-model="filters.service_item_id" class="border border-slate-300 px-4 py-3" :disabled="!filters.service_category_id">
                <option value="">All Services</option>
                <option v-for="service in serviceOptions" :key="service.id" :value="String(service.id)">{{ service.name }}</option>
              </select>
            </div>
          </div>

          <div v-if="reportMode === 'payments'">
            <p class="mb-2 text-xs font-bold uppercase text-slate-400">Payments</p>
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
              <select v-model="filters.payment_method" class="border border-slate-300 px-4 py-3">
                <option value="">All Payment Methods</option>
                <option value="CASH">CASH</option>
                <option value="GCASH">GCASH</option>
                <option value="BANK_TRANSFER">BANK TRANSFER</option>
                <option value="CARD">CARD</option>
                <option value="OTHER">OTHER</option>
              </select>
              <select v-model="filters.payment_type" class="border border-slate-300 px-4 py-3">
                <option value="">All Payment Types</option>
                <option value="PARTIAL_PAYMENT">PARTIAL PAYMENT</option>
                <option value="FINAL_PAYMENT">FINAL PAYMENT</option>
              </select>
            </div>
          </div>

          <div class="flex justify-end">
            <button type="submit" class="btn-primary px-5 py-3">Apply Filters</button>
          </div>
        </form>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-4" v-if="report && reportMode === 'operations'">
        <div class="metric-card">
          <p class="text-slate-500 text-sm">This Month</p>
          <p class="text-3xl font-bold text-slate-900">{{ report.thisMonth }}</p>
        </div>
        <div class="metric-card">
          <p class="text-slate-500 text-sm">Completed</p>
          <p class="text-3xl font-bold text-slate-900">{{ report.totalCompleted }}</p>
        </div>
        <div class="metric-card">
          <p class="text-slate-500 text-sm">Avg Turnaround</p>
          <p class="text-3xl font-bold text-slate-900">{{ report.avgTurnaround }} days</p>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4" v-if="report && reportMode === 'payments'">
        <div class="metric-card">
          <p class="text-slate-500 text-sm">Original Repair Costs</p>
          <p class="text-2xl font-bold text-slate-900">{{ formatCurrency(report.totalRevenue) }}</p>
          <p class="mt-1 text-xs text-slate-500">Historical estimates retained, including unsuccessful repairs.</p>
        </div>
        <div class="metric-card">
          <p class="text-slate-500 text-sm">Total Collected</p>
          <p class="text-2xl font-bold text-emerald-700">{{ formatCurrency(report.totalCollected) }}</p>
        </div>
        <div class="metric-card">
          <p class="text-slate-500 text-sm">Pending Receivables</p>
          <p class="text-2xl font-bold text-amber-700">{{ formatCurrency(report.pendingReceivables) }}</p>
        </div>
        <div class="metric-card">
          <p class="text-slate-500 text-sm">Estimated Gross Profit</p>
          <p class="text-2xl font-bold" :class="report.estimatedGrossProfit >= 0 ? 'text-slate-900' : 'text-rose-700'">
            {{ formatCurrency(report.estimatedGrossProfit) }}
          </p>
          <p class="mt-1 text-xs text-slate-500">Margin: {{ report.grossProfitMargin }}%</p>
        </div>
      </div>

      <div class="grid grid-cols-1 gap-4 md:grid-cols-3" v-if="report && reportMode === 'payments'">
        <div class="metric-card">
          <p class="text-slate-500 text-sm">Pending RTO Refund Obligations</p>
          <p class="text-2xl font-bold text-amber-700">{{ formatCurrency(report.pendingRtoRefunds) }}</p>
        </div>
        <div class="metric-card">
          <p class="text-slate-500 text-sm">RTO Refunds Issued</p>
          <p class="text-2xl font-bold text-rose-700">{{ formatCurrency(report.issuedRtoRefunds) }}</p>
        </div>
        <div class="metric-card">
          <p class="text-slate-500 text-sm">Net Retained</p>
          <p class="text-2xl font-bold text-emerald-700">{{ formatCurrency(report.netRetained) }}</p>
          <p class="mt-1 text-xs text-slate-500">Historical receipts less issued refunds for the selected job orders.</p>
        </div>
      </div>

      <section v-if="report && reportMode === 'operations'" class="admin-panel overflow-hidden">
        <div class="border-b border-slate-200 p-5">
          <h3 class="text-xl font-bold text-slate-950">Filtered Job Orders</h3>
          <p class="mt-1 text-sm text-slate-500">
            Detailed list based on the selected date and status filters. Use Export PDF to print this list.
          </p>
        </div>
        <div class="overflow-x-auto">
          <table class="data-table">
            <thead>
              <tr>
                <th class="px-4 py-3 text-left text-slate-600">Date</th>
                <th class="px-4 py-3 text-left text-slate-600">Job Order</th>
                <th class="px-4 py-3 text-left text-slate-600">Customer</th>
                <th class="px-4 py-3 text-left text-slate-600">Device</th>
                <th class="px-4 py-3 text-left text-slate-600">Service</th>
                <th class="px-4 py-3 text-left text-slate-600">Status</th>
                <th class="px-4 py-3 text-left text-slate-600">Service Type</th>
                <th class="px-4 py-3 text-left text-slate-600">Technician</th>
                <th class="px-4 py-3 text-left text-slate-600">Due</th>
                <th class="px-4 py-3 text-left text-slate-600">Balance</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="job in report.filteredJobOrders" :key="job.id">
                <td class="px-4 py-3 text-slate-700">{{ job.created_at_formatted }}</td>
                <td class="px-4 py-3 font-semibold text-slate-900">{{ job.job_order_number }}</td>
                <td class="px-4 py-3 text-slate-700">
                  <p class="font-semibold text-slate-900">{{ job.customer_name }}</p>
                  <p class="text-xs text-slate-500">{{ job.customer_phone || 'No phone' }}</p>
                </td>
                <td class="px-4 py-3 text-slate-700">{{ job.device_label }}</td>
                <td class="px-4 py-3 text-slate-700">
                  <p class="font-semibold text-slate-900">{{ job.service_category_name || job.device_type }}</p>
                  <p class="text-xs text-slate-500">{{ job.service_item_name || 'Unspecified service' }}</p>
                </td>
                <td class="px-4 py-3 text-slate-700">
                  <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold ring-1" :class="jobOrderStatusClass(job.current_status)">{{ formatStatus(job.current_status) }}</span>
                </td>
                <td class="px-4 py-3">
                  <span
                    class="inline-flex rounded-full px-2 py-1 text-xs font-bold ring-1"
                    :class="job.service_type === 'WARRANTY_SERVICE' ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-100 text-slate-700 ring-slate-200'"
                  >
                    {{ job.service_type_label || formatServiceType(job.service_type) }}
                  </span>
                </td>
                <td class="px-4 py-3 text-slate-700">{{ job.technician_name || 'Unassigned' }}</td>
                <td class="px-4 py-3 text-slate-700">{{ job.estimated_completion_formatted || 'Not set' }}</td>
                <td class="px-4 py-3">
                  <p class="font-semibold text-slate-900">{{ formatCurrency(job.balance) }}</p>
                  <p class="text-xs text-slate-500">Paid {{ formatCurrency(job.amount_paid) }} / {{ formatCurrency(job.total_cost) }}</p>
                </td>
              </tr>
              <tr v-if="!report.filteredJobOrders.length">
                <td colspan="10" class="px-4 py-8 text-center text-slate-500">No job orders match the selected filters.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="report && reportMode === 'operations'" class="grid grid-cols-1 gap-5 xl:grid-cols-2">
        <div class="admin-panel overflow-hidden">
          <div class="border-b border-slate-200 p-5">
            <h3 class="text-lg font-bold text-slate-950">Top Services</h3>
            <p class="mt-1 text-sm text-slate-500">Most requested services in the selected report range.</p>
          </div>
          <div class="overflow-x-auto">
            <table class="data-table">
              <thead>
                <tr>
                  <th class="px-4 py-3 text-left text-slate-600">Category</th>
                  <th class="px-4 py-3 text-left text-slate-600">Service</th>
                  <th class="px-4 py-3 text-left text-slate-600">Jobs</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="service in report.topServices" :key="`${service.service_category_id}-${service.service_item_id}`">
                  <td class="px-4 py-3 font-semibold text-slate-900">{{ service.service_category_name }}</td>
                  <td class="px-4 py-3 text-slate-700">{{ service.service_item_name }}</td>
                  <td class="px-4 py-3 font-bold text-slate-900">{{ service.total }}</td>
                </tr>
                <tr v-if="!report.topServices.length">
                  <td colspan="3" class="px-4 py-8 text-center text-slate-500">No service data found.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="admin-panel overflow-hidden">
          <div class="border-b border-slate-200 p-5">
            <h3 class="text-lg font-bold text-slate-950">Service Category Breakdown</h3>
            <p class="mt-1 text-sm text-slate-500">Job count grouped by device/service category.</p>
          </div>
          <div class="overflow-x-auto">
            <table class="data-table">
              <thead>
                <tr>
                  <th class="px-4 py-3 text-left text-slate-600">Category</th>
                  <th class="px-4 py-3 text-left text-slate-600">Jobs</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="category in report.serviceCategoryBreakdown" :key="category.service_category_id || category.service_category_name">
                  <td class="px-4 py-3 font-semibold text-slate-900">{{ category.service_category_name }}</td>
                  <td class="px-4 py-3 font-bold text-slate-900">{{ category.total }}</td>
                </tr>
                <tr v-if="!report.serviceCategoryBreakdown.length">
                  <td colspan="2" class="px-4 py-8 text-center text-slate-500">No category data found.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <section v-if="report && reportMode === 'payments'" class="space-y-4">
        <div>
          <h3 class="text-xl font-bold text-slate-950">Payment Reports</h3>
          <p class="mt-1 text-sm text-slate-500">Track collections by method, type, and partial-payment status for bookkeeping checks.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
          <div class="metric-card">
            <p class="text-slate-500 text-sm">Payments Recorded</p>
            <p class="text-2xl font-bold text-slate-900">{{ formatCurrency(report.paymentSummary.total_amount) }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ report.paymentSummary.total_records }} record{{ report.paymentSummary.total_records === 1 ? '' : 's' }}</p>
          </div>
          <div class="metric-card">
            <p class="text-slate-500 text-sm">Partial Payments</p>
            <p class="text-2xl font-bold text-slate-900">{{ formatCurrency(report.paymentSummary.partial_payment_total) }}</p>
          </div>
          <div class="metric-card">
            <p class="text-slate-500 text-sm">Held Partial Payments</p>
            <p class="text-2xl font-bold text-amber-700">{{ formatCurrency(report.paymentSummary.held_partial_payments) }}</p>
            <p class="mt-1 text-xs text-slate-500">Not released yet</p>
          </div>
          <div class="metric-card">
            <p class="text-slate-500 text-sm">Applied / Review</p>
            <p class="text-2xl font-bold text-emerald-700">{{ formatCurrency(report.paymentSummary.applied_partial_payments) }}</p>
            <p class="mt-1 text-xs text-amber-700">Review: {{ formatCurrency(report.paymentSummary.review_partial_payments) }}</p>
          </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">
          <div class="admin-panel overflow-hidden">
            <div class="border-b border-slate-200 p-5">
              <h4 class="font-bold text-slate-950">Collections by Method</h4>
            </div>
            <div class="overflow-x-auto">
              <table class="data-table">
                <thead>
                  <tr>
                    <th class="px-4 py-3 text-left text-slate-600">Method</th>
                    <th class="px-4 py-3 text-left text-slate-600">Records</th>
                    <th class="px-4 py-3 text-left text-slate-600">Amount</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="method in report.paymentMethodBreakdown" :key="method.payment_method">
                    <td class="px-4 py-3 font-semibold text-slate-900">{{ method.payment_method.replaceAll('_', ' ') }}</td>
                    <td class="px-4 py-3 text-slate-700">{{ method.records }}</td>
                    <td class="px-4 py-3 font-semibold text-slate-900">{{ formatCurrency(method.amount) }}</td>
                  </tr>
                  <tr v-if="!report.paymentMethodBreakdown.length">
                    <td colspan="3" class="px-4 py-8 text-center text-slate-500">No payments found.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div class="admin-panel overflow-hidden">
            <div class="border-b border-slate-200 p-5">
              <h4 class="font-bold text-slate-950">Collections by Type</h4>
            </div>
            <div class="overflow-x-auto">
              <table class="data-table">
                <thead>
                  <tr>
                    <th class="px-4 py-3 text-left text-slate-600">Type</th>
                    <th class="px-4 py-3 text-left text-slate-600">Records</th>
                    <th class="px-4 py-3 text-left text-slate-600">Amount</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="type in report.paymentTypeBreakdown" :key="type.payment_type">
                    <td class="px-4 py-3 font-semibold text-slate-900">{{ type.payment_type.replaceAll('_', ' ') }}</td>
                    <td class="px-4 py-3 text-slate-700">{{ type.records }}</td>
                    <td class="px-4 py-3 font-semibold text-slate-900">{{ formatCurrency(type.amount) }}</td>
                  </tr>
                  <tr v-if="!report.paymentTypeBreakdown.length">
                    <td colspan="3" class="px-4 py-8 text-center text-slate-500">No payments found.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="admin-panel overflow-hidden">
          <div class="border-b border-slate-200 p-5">
            <h4 class="font-bold text-slate-950">Partial Payment Watchlist</h4>
            <p class="mt-1 text-sm text-slate-500">Held means the unit is not yet released. Needs review means the job was marked RTO.</p>
          </div>
          <div class="overflow-x-auto">
            <table class="data-table">
              <thead>
                <tr>
                  <th class="px-4 py-3 text-left text-slate-600">Date</th>
                  <th class="px-4 py-3 text-left text-slate-600">Job Order</th>
                  <th class="px-4 py-3 text-left text-slate-600">Customer</th>
                  <th class="px-4 py-3 text-left text-slate-600">Method</th>
                  <th class="px-4 py-3 text-left text-slate-600">Amount</th>
                  <th class="px-4 py-3 text-left text-slate-600">State</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="payment in report.partialPaymentRecords" :key="payment.id">
                  <td class="px-4 py-3 text-slate-700">{{ payment.received_at_formatted }}</td>
                  <td class="px-4 py-3 font-semibold text-slate-900">{{ payment.job_order_number }}</td>
                  <td class="px-4 py-3 text-slate-700">{{ payment.customer_name || 'N/A' }}</td>
                  <td class="px-4 py-3 text-slate-700">{{ payment.payment_method.replaceAll('_', ' ') }}</td>
                  <td class="px-4 py-3 font-semibold text-slate-900">{{ formatCurrency(payment.amount) }}</td>
                  <td class="px-4 py-3">
                    <span class="rounded-full px-3 py-1 text-xs font-bold ring-1" :class="paymentStateClass(payment.partial_payment_state)">
                      {{ payment.partial_payment_state?.replaceAll('_', ' ') }}
                    </span>
                  </td>
                </tr>
                <tr v-if="!report.partialPaymentRecords.length">
                  <td colspan="6" class="px-4 py-8 text-center text-slate-500">No partial payments found.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="admin-panel overflow-hidden">
          <div class="border-b border-slate-200 p-5">
            <h4 class="font-bold text-slate-950">Recent Payment Records</h4>
          </div>
          <div class="overflow-x-auto">
            <table class="data-table">
              <thead>
                <tr>
                  <th class="px-4 py-3 text-left text-slate-600">Date</th>
                  <th class="px-4 py-3 text-left text-slate-600">Job Order</th>
                  <th class="px-4 py-3 text-left text-slate-600">Type</th>
                  <th class="px-4 py-3 text-left text-slate-600">Method</th>
                  <th class="px-4 py-3 text-left text-slate-600">Amount</th>
                  <th class="px-4 py-3 text-left text-slate-600">Reference / Notes</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="payment in report.paymentRecords" :key="payment.id">
                  <td class="px-4 py-3 text-slate-700">{{ payment.received_at_formatted }}</td>
                  <td class="px-4 py-3">
                    <p class="font-semibold text-slate-900">{{ payment.job_order_number }}</p>
                    <p class="text-xs text-slate-500">{{ payment.customer_name || 'N/A' }}</p>
                  </td>
                  <td class="px-4 py-3 text-slate-700">{{ payment.payment_type.replaceAll('_', ' ') }}</td>
                  <td class="px-4 py-3 text-slate-700">{{ payment.payment_method.replaceAll('_', ' ') }}</td>
                  <td class="px-4 py-3 font-semibold text-slate-900">{{ formatCurrency(payment.amount) }}</td>
                  <td class="px-4 py-3 text-slate-700">
                    <p v-if="payment.reference_number">Ref: {{ payment.reference_number }}</p>
                    <p v-if="payment.notes" class="text-xs text-slate-500">{{ payment.notes }}</p>
                    <p v-if="payment.received_by" class="text-xs text-slate-500">Received by {{ payment.received_by }}</p>
                  </td>
                </tr>
                <tr v-if="!report.paymentRecords.length">
                  <td colspan="6" class="px-4 py-8 text-center text-slate-500">No payment records found.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="admin-panel overflow-hidden">
          <div class="border-b border-slate-200 p-5">
            <h4 class="font-bold text-slate-950">RTO Refund Ledger</h4>
            <p class="mt-1 text-sm text-slate-500">Pending obligations are not counted as issued cash refunds.</p>
          </div>
          <div class="overflow-x-auto">
            <table class="data-table">
              <thead>
                <tr>
                  <th class="px-4 py-3 text-left text-slate-600">Job Order</th>
                  <th class="px-4 py-3 text-left text-slate-600">Customer</th>
                  <th class="px-4 py-3 text-left text-slate-600">Reason</th>
                  <th class="px-4 py-3 text-left text-slate-600">Status</th>
                  <th class="px-4 py-3 text-right text-slate-600">Amount</th>
                  <th class="px-4 py-3 text-left text-slate-600">Issued</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="refund in report.refundRecords" :key="refund.id">
                  <td class="px-4 py-3 font-semibold text-slate-900">{{ refund.job_order_number }}</td>
                  <td class="px-4 py-3 text-slate-700">{{ refund.customer_name }}</td>
                  <td class="px-4 py-3 text-slate-700">{{ refund.reason }}</td>
                  <td class="px-4 py-3 text-slate-700">{{ refund.status }}</td>
                  <td class="px-4 py-3 text-right font-semibold text-slate-900">{{ formatCurrency(refund.amount) }}</td>
                  <td class="px-4 py-3 text-slate-700">{{ refund.issued_at || 'Not issued' }}</td>
                </tr>
                <tr v-if="!report.refundRecords.length">
                  <td colspan="6" class="px-4 py-8 text-center text-slate-500">No RTO refunds found for this report range.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </div>
  </AdminLayout>
</template>
