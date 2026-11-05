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
const activeTab = ref('operations');
const expandedSections = reactive({});
const initialVisibleRows = 5;

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
const technicianPerformance = computed(() => report.value?.technicianPerformance || []);
const technicianWorkload = computed(() => report.value?.currentTechnicianWorkload || []);
const jobOrderSalesCapital = computed(() => report.value?.jobOrderSalesCapital || {
    summary: { total_gross: 0, total_capital: 0, gross_less_capital: 0 },
    rows: [],
});
const inventoryReport = computed(() => report.value?.inventoryReport || {
    summary: { stock_quantity_by_unit: [] },
    items: [],
    movement_summary: {
        repair_linked_out: { movement_count: 0, quantity: 0 },
        unlinked_manual_out: { movement_count: 0, quantity: 0 },
        invalid_job_reference_out: { movement_count: 0, quantity: 0 },
    },
    movements: [],
});
const hasTechnicianPerformanceJobs = computed(() => technicianPerformance.value.some((technician) => (
    technician.successful_jobs > 0 || technician.rto_jobs > 0 || technician.rto_released_jobs > 0
)));
const hasTechnicianWorkloadJobs = computed(() => technicianWorkload.value.some((technician) => technician.current_jobs > 0));
const serviceOptions = computed(() => {
    const category = serviceCatalog.value.find((item) => String(item.id) === String(filters.service_category_id));
    return category?.services || [];
});

const formatCurrency = (value) => {
    const amount = Number(value || 0);
    return `PHP ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};
const formatMovementDate = (value) => value ? new Date(value).toLocaleString() : '—';
const visibleRows = (key, rows) => expandedSections[key] ? rows : rows.slice(0, initialVisibleRows);
const hasMoreRows = (rows) => rows.length > initialVisibleRows;
const toggleSection = (key) => {
    expandedSections[key] = !expandedSections[key];
};
const resetExpandedSections = () => {
    Object.keys(expandedSections).forEach((key) => delete expandedSections[key]);
};
const disclosureText = (key, rows) => expandedSections[key]
    ? `Showing all ${rows.length} records`
    : `Showing ${Math.min(rows.length, initialVisibleRows)} of ${rows.length} records`;

const setReportMode = (mode) => {
    reportMode.value = mode;

    if (mode === 'operations' || mode === 'inventory') {
        filters.payment_method = '';
        filters.payment_type = '';
    } else {
        filters.status = '';
        filters.service_type = '';
        filters.service_category_id = '';
        filters.service_item_id = '';
    }
};

const setActiveTab = (tab) => {
    activeTab.value = tab;

    setReportMode(tab);
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
        resetExpandedSections();
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
            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Review repair performance, inventory, and payment activity across focused report sections.</p>
          </div>
          <div class="flex flex-wrap gap-3">
            <button type="button" class="rounded-lg bg-emerald-500 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-400" @click="downloadCsv">Export CSV</button>
            <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:border-teal-200 hover:text-teal-700" @click="downloadPdf">Export PDF</button>
          </div>
        </div>
      </section>

      <nav class="grid grid-cols-1 gap-2 rounded-xl border border-slate-200 bg-white p-2 sm:grid-cols-3" role="tablist" aria-label="Report sections">
        <button
          v-for="tab in [
            { id: 'operations', label: 'Operations', icon: 'fas fa-screwdriver-wrench' },
            { id: 'inventory', label: 'Inventory', icon: 'fas fa-boxes-stacked' },
            { id: 'payments', label: 'Transactions', icon: 'fas fa-money-bill-transfer' },
          ]"
          :key="tab.id"
          :id="`reports-tab-${tab.id}`"
          type="button"
          role="tab"
          :aria-selected="activeTab === tab.id"
          :aria-controls="`reports-panel-${tab.id}`"
          class="min-h-11 rounded-lg px-4 py-2 text-sm font-bold transition-colors"
          :class="activeTab === tab.id ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-teal-50 hover:text-teal-800'"
          @click="setActiveTab(tab.id)"
        >
          <i :class="[tab.icon, 'mr-2']" aria-hidden="true"></i>
          {{ tab.label }}
        </button>
      </nav>

      <div class="admin-panel p-6">
        <h3 class="text-lg font-bold text-slate-950 mb-4">Filters</h3>
        <form class="space-y-5" @submit.prevent="loadReport">
          <div>
            <p class="mb-2 text-xs font-bold uppercase text-slate-400">Date Range</p>
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
              <input v-model="filters.start_date" type="date" class="border border-slate-300 px-4 py-3" />
              <input v-model="filters.end_date" type="date" class="border border-slate-300 px-4 py-3" />
            </div>
          </div>

          <div v-if="activeTab === 'operations'">
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

          <div v-if="activeTab === 'payments'">
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

      <div v-if="report && activeTab === 'operations'" class="space-y-5" role="tabpanel" id="reports-panel-operations-summary" aria-labelledby="reports-tab-operations">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="metric-card">
          <p class="text-slate-500 text-sm">This Month</p>
          <p class="text-3xl font-bold text-slate-900">{{ report.thisMonth }}</p>
        </div>
        <div class="metric-card">
          <p class="text-slate-500 text-sm">Completed</p>
          <p class="text-3xl font-bold text-slate-900">{{ report.totalCompleted }}</p>
          <p class="mt-1 text-xs text-slate-500">Date basis: job creation date (created_at). Date scope: applied date range when provided; otherwise, all dates.</p>
        </div>
        <div class="metric-card">
          <p class="text-slate-500 text-sm">Avg Turnaround</p>
          <p class="text-3xl font-bold text-slate-900">{{ report.avgTurnaround }} days</p>
        </div>
      </div>

      <section class="space-y-4">
        <div>
          <h3 class="flex items-center gap-2 text-xl font-bold text-slate-950"><i class="fas fa-chart-pie text-sm text-teal-700" aria-hidden="true"></i>Business Overview</h3>
          <p class="mt-1 text-sm text-slate-500">Core figures use their stated transaction, job-order, or current-snapshot basis.</p>
        </div>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <div class="metric-card">
            <p class="text-slate-500 text-sm">Repair Value</p>
            <p class="mt-1 text-2xl font-bold text-slate-900">{{ formatCurrency(report.totalRevenue) }}</p>
            <p class="mt-1 text-xs text-slate-500">Job estimates in the selected job-order scope.</p>
          </div>
          <div class="metric-card">
            <p class="text-slate-500 text-sm">Payments Received</p>
            <p class="mt-1 text-2xl font-bold text-teal-700">{{ formatCurrency(report.paymentSummary.total_amount) }}</p>
            <p class="mt-1 text-xs text-slate-500">Payment transactions by received_at; not verified bank settlements.</p>
          </div>
          <div class="metric-card">
            <p class="text-slate-500 text-sm">Unpaid Balances</p>
            <p class="mt-1 text-2xl font-bold text-amber-700">{{ formatCurrency(report.paymentSummary.current_pending_balances) }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ report.paymentSummary.current_pending_balance_jobs }} jobs · current snapshot, independent of report dates.</p>
          </div>
          <div class="metric-card">
            <p class="text-slate-500 text-sm">Estimated Gross Profit</p>
            <p class="mt-1 text-2xl font-bold" :class="report.estimatedGrossProfit >= 0 ? 'text-slate-900' : 'text-rose-700'">
              {{ formatCurrency(report.estimatedGrossProfit) }}
            </p>
            <p class="mt-1 text-xs text-slate-500">Selected job estimates less recorded parts costs · margin {{ report.grossProfitMargin }}%.</p>
          </div>
        </div>
      </section>
      </div>

      <div v-if="report" class="space-y-5" role="tabpanel" id="reports-panel-details" aria-live="polite">
      <section v-if="activeTab === 'operations'" class="grid grid-cols-1 gap-5 xl:grid-cols-2">
        <div class="admin-panel overflow-hidden">
          <div class="border-b border-slate-200 p-5">
            <h3 class="flex items-center gap-2 text-lg font-bold text-slate-950"><i class="fas fa-users text-sm text-teal-700" aria-hidden="true"></i>Technician Performance (Current Assignment)</h3>
            <p class="mt-1 text-sm text-slate-500">Results reflect each job's currently stored technician assignment, not historical assignment.</p>
            <p class="mt-1 text-xs text-slate-500">Successful jobs use pickup date (picked_up_at); RTO outcomes use job creation date (created_at). Date scope: applied date range when provided; otherwise, all dates.</p>
          </div>
          <div class="overflow-x-auto">
            <table v-if="technicianPerformance.length" class="data-table">
              <thead>
                <tr>
                  <th class="px-4 py-3 text-left text-slate-600">Technician</th>
                  <th class="px-4 py-3 text-right text-slate-600">Successful Jobs</th>
                  <th class="px-4 py-3 text-right text-slate-600">Avg Turnaround</th>
                  <th class="px-4 py-3 text-right text-slate-600">RTO</th>
                  <th class="px-4 py-3 text-right text-slate-600">RTO Released</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="technician in visibleRows('technician-performance', technicianPerformance)" :key="technician.technician_id">
                  <td class="px-4 py-3 font-semibold text-slate-900">{{ technician.technician_name }}</td>
                  <td class="px-4 py-3 text-right text-slate-700">{{ technician.successful_jobs }}</td>
                  <td class="px-4 py-3 text-right text-slate-700">{{ technician.average_turnaround_days }} days</td>
                  <td class="px-4 py-3 text-right text-slate-700">{{ technician.rto_jobs }}</td>
                  <td class="px-4 py-3 text-right text-slate-700">{{ technician.rto_released_jobs }}</td>
                </tr>
              </tbody>
            </table>
            <div v-if="technicianPerformance.length" class="flex items-center justify-between gap-3 border-t border-slate-100 px-4 py-3">
              <span class="text-xs text-slate-500">{{ disclosureText('technician-performance', technicianPerformance) }}</span>
              <button v-if="hasMoreRows(technicianPerformance)" type="button" class="inline-flex items-center gap-1 text-sm font-bold text-teal-700 hover:text-teal-900" @click="toggleSection('technician-performance')">
                {{ expandedSections['technician-performance'] ? 'Show Less' : 'Show More' }}
                <span aria-hidden="true">{{ expandedSections['technician-performance'] ? '↑' : '↓' }}</span>
              </button>
            </div>
            <p v-else class="p-6 text-center text-sm text-slate-500">No technician records are available for this shop.</p>
          </div>
          <p v-if="technicianPerformance.length && !hasTechnicianPerformanceJobs" class="border-t border-slate-100 p-4 text-sm text-slate-500">
            No assigned jobs match the selected services and reporting period.
          </p>
        </div>

        <div class="admin-panel overflow-hidden">
          <div class="border-b border-slate-200 p-5">
            <h3 class="flex items-center gap-2 text-lg font-bold text-slate-950"><i class="fas fa-clipboard-list text-sm text-teal-700" aria-hidden="true"></i>Current Technician Workload</h3>
            <p class="mt-1 text-sm text-slate-500">Current snapshot; excludes picked-up and both RTO terminal statuses. Date filters do not apply.</p>
          </div>
          <div class="overflow-x-auto">
            <table v-if="technicianWorkload.length" class="data-table">
              <thead>
                <tr>
                  <th class="px-4 py-3 text-left text-slate-600">Technician</th>
                  <th class="px-4 py-3 text-right text-slate-600">Currently Assigned Jobs</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="technician in visibleRows('technician-workload', technicianWorkload)" :key="technician.technician_id">
                  <td class="px-4 py-3 font-semibold text-slate-900">{{ technician.technician_name }}</td>
                  <td class="px-4 py-3 text-right font-bold text-slate-900">{{ technician.current_jobs }}</td>
                </tr>
              </tbody>
            </table>
            <div v-if="technicianWorkload.length" class="flex items-center justify-between gap-3 border-t border-slate-100 px-4 py-3">
              <span class="text-xs text-slate-500">{{ disclosureText('technician-workload', technicianWorkload) }}</span>
              <button v-if="hasMoreRows(technicianWorkload)" type="button" class="inline-flex items-center gap-1 text-sm font-bold text-teal-700 hover:text-teal-900" @click="toggleSection('technician-workload')">
                {{ expandedSections['technician-workload'] ? 'Show Less' : 'Show More' }}
                <span aria-hidden="true">{{ expandedSections['technician-workload'] ? '↑' : '↓' }}</span>
              </button>
            </div>
            <p v-else class="p-6 text-center text-sm text-slate-500">No technician records are available for this shop.</p>
          </div>
          <p v-if="technicianWorkload.length && !hasTechnicianWorkloadJobs" class="border-t border-slate-100 p-4 text-sm text-slate-500">
            No non-terminal assigned jobs match the selected services.
          </p>
        </div>
      </section>

      <section v-if="activeTab === 'operations'" class="space-y-5">
        <div v-if="activeTab === 'operations'">
        <div class="admin-panel overflow-hidden">
          <div class="border-b border-slate-200 p-5">
            <h3 class="flex items-center gap-2 text-xl font-bold text-slate-950"><i class="fas fa-chart-column text-sm text-teal-700" aria-hidden="true"></i>Job Order Sales &amp; Capital Report</h3>
            <p class="mt-1 text-sm text-slate-500">Gross uses the original job order estimated price (total_cost). Capital uses stored historical parts usage costs. Gross Less Capital is not verified net profit because operating expenses are excluded.</p>
          </div>
          <div class="grid grid-cols-1 gap-3 p-5 sm:grid-cols-3">
            <div class="metric-card">
              <p class="text-sm text-slate-500">Total Gross</p>
              <p class="mt-1 text-2xl font-bold text-slate-900">{{ formatCurrency(jobOrderSalesCapital.summary.total_gross) }}</p>
            </div>
            <div class="metric-card">
              <p class="text-sm text-slate-500">Total Capital</p>
              <p class="mt-1 text-2xl font-bold text-slate-900">{{ formatCurrency(jobOrderSalesCapital.summary.total_capital) }}</p>
            </div>
            <div class="metric-card">
              <p class="text-sm text-slate-500">Gross Less Capital</p>
              <p class="mt-1 text-2xl font-bold text-teal-700">{{ formatCurrency(jobOrderSalesCapital.summary.gross_less_capital) }}</p>
            </div>
          </div>
          <div class="overflow-x-auto">
            <table class="data-table min-w-[1050px]">
              <thead>
                <tr>
                  <th class="px-4 py-3 text-left">Customer Name</th>
                  <th class="px-4 py-3 text-left">Tracking Number</th>
                  <th class="px-4 py-3 text-left">Unit</th>
                  <th class="px-4 py-3 text-left">Service</th>
                  <th class="px-4 py-3 text-right">Gross</th>
                  <th class="px-4 py-3 text-right">Capital</th>
                  <th class="px-4 py-3 text-left">Payment Method</th>
                  <th class="px-4 py-3 text-left">Technician</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in visibleRows('sales-capital', jobOrderSalesCapital.rows)" :key="row.tracking_number">
                  <td class="px-4 py-3 font-semibold text-slate-900">{{ row.customer_name }}</td>
                  <td class="px-4 py-3 text-slate-700">{{ row.tracking_number }}</td>
                  <td class="px-4 py-3 text-slate-700">{{ row.unit || '—' }}</td>
                  <td class="px-4 py-3 text-slate-700">{{ row.service }}</td>
                  <td class="px-4 py-3 text-right text-slate-700">{{ formatCurrency(row.gross) }}</td>
                  <td class="px-4 py-3 text-right text-slate-700">{{ row.capital === null ? '—' : formatCurrency(row.capital) }}</td>
                  <td class="px-4 py-3 text-slate-700">{{ row.payment_method }}</td>
                  <td class="px-4 py-3 text-slate-700">{{ row.technician }}</td>
                </tr>
                <tr v-if="!jobOrderSalesCapital.rows.length">
                  <td colspan="8" class="px-4 py-8 text-center text-slate-500">No job orders match the selected filters.</td>
                </tr>
              </tbody>
            </table>
            <div v-if="jobOrderSalesCapital.rows.length" class="flex items-center justify-between gap-3 border-t border-slate-100 px-4 py-3">
              <span class="text-xs text-slate-500">{{ disclosureText('sales-capital', jobOrderSalesCapital.rows) }}</span>
              <button v-if="hasMoreRows(jobOrderSalesCapital.rows)" type="button" class="inline-flex items-center gap-1 text-sm font-bold text-teal-700 hover:text-teal-900" @click="toggleSection('sales-capital')">
                {{ expandedSections['sales-capital'] ? 'Show Less' : 'Show More' }}
                <span aria-hidden="true">{{ expandedSections['sales-capital'] ? '↑' : '↓' }}</span>
              </button>
            </div>
          </div>
        </div>
        </div>

      </section>

      <section v-if="activeTab === 'inventory'" class="space-y-5">
        <div>
          <h3 class="flex items-center gap-2 text-xl font-bold text-slate-950"><i class="fas fa-boxes-stacked text-sm text-teal-700" aria-hidden="true"></i>Inventory Reports</h3>
          <p class="mt-1 text-sm text-slate-500">The stock snapshot is current and independent of report dates. Movement history is filtered by its created_at timestamp when date filters are provided; otherwise, all dates are included.</p>
        </div>

        <div class="admin-panel p-5">
          <div class="mb-4">
            <h4 class="text-lg font-bold text-slate-950">Current Stock Snapshot</h4>
            <p class="mt-1 text-xs text-slate-500">Estimated Current Inventory Value uses current stock_quantity × editable unit_cost. It is an estimate, not historical valuation or actual purchase cost.</p>
          </div>
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <div class="metric-card">
              <p class="text-sm text-slate-500">Total Inventory Items</p>
              <p class="text-2xl font-bold text-slate-900">{{ inventoryReport.summary.total_items }}</p>
            </div>
            <div class="metric-card">
              <p class="text-sm text-slate-500">Active Inventory Items</p>
              <p class="text-2xl font-bold text-slate-900">{{ inventoryReport.summary.active_items }}</p>
            </div>
            <div class="metric-card">
              <p class="text-sm text-slate-500">Inactive Inventory Items</p>
              <p class="text-2xl font-bold text-slate-900">{{ inventoryReport.summary.inactive_items }}</p>
            </div>
            <div class="metric-card">
              <p class="text-sm text-slate-500">Low Stock Items</p>
              <p class="text-2xl font-bold text-rose-700">{{ inventoryReport.summary.low_stock_items }}</p>
              <p class="mt-1 text-xs text-slate-500">Includes inactive items; stock ≤ reorder level.</p>
            </div>
            <div class="metric-card">
              <p class="text-sm text-slate-500">Estimated Current Inventory Value</p>
              <p class="text-xl font-bold text-slate-900">{{ formatCurrency(inventoryReport.summary.estimated_current_inventory_value) }}</p>
            </div>
          </div>
          <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-4">
            <p class="text-sm font-semibold text-slate-700">Total Current Stock Quantity by Unit</p>
            <p v-if="!inventoryReport.summary.stock_quantity_by_unit.length" class="mt-1 text-sm text-slate-500">No inventory quantities to summarize.</p>
            <div v-else class="mt-2 flex flex-wrap gap-2">
              <span v-for="unitTotal in inventoryReport.summary.stock_quantity_by_unit" :key="unitTotal.unit" class="rounded-full bg-white px-3 py-1 text-sm font-semibold text-slate-700 ring-1 ring-slate-200">
                {{ unitTotal.quantity }} {{ unitTotal.unit }}
              </span>
            </div>
          </div>

          <div class="mt-5 overflow-x-auto">
            <table class="data-table min-w-[1050px]">
              <thead>
                <tr>
                  <th class="px-3 py-3 text-left">Part</th>
                  <th class="px-3 py-3 text-left">Part Number</th>
                  <th class="px-3 py-3 text-left">Category</th>
                  <th class="px-3 py-3 text-left">Unit</th>
                  <th class="px-3 py-3 text-right">Current Stock</th>
                  <th class="px-3 py-3 text-right">Reorder Level</th>
                  <th class="px-3 py-3 text-left">Stock Status</th>
                  <th class="px-3 py-3 text-left">Active Status</th>
                  <th class="px-3 py-3 text-right">Unit Cost</th>
                  <th class="px-3 py-3 text-right">Estimated Item Value</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in visibleRows('inventory-items', inventoryReport.items)" :key="item.id">
                  <td class="px-3 py-3 font-semibold text-slate-900">
                    {{ item.part_name }}
                  </td>
                  <td class="px-3 py-3 text-slate-700">{{ item.part_number || '—' }}</td>
                  <td class="px-3 py-3 text-slate-700">{{ item.category || '—' }}</td>
                  <td class="px-3 py-3 text-slate-700">{{ item.unit }}</td>
                  <td class="px-3 py-3 text-right font-semibold" :class="item.is_low_stock ? 'text-rose-700' : 'text-slate-700'">{{ item.stock_quantity }}</td>
                  <td class="px-3 py-3 text-right text-slate-700">{{ item.reorder_level }}</td>
                  <td class="px-3 py-3 text-slate-700">{{ item.stock_status }}</td>
                  <td class="px-3 py-3 text-slate-700">{{ item.active_status }}</td>
                  <td class="px-3 py-3 text-right text-slate-700">{{ formatCurrency(item.unit_cost) }}</td>
                  <td class="px-3 py-3 text-right text-slate-700">{{ formatCurrency(item.estimated_item_value) }}</td>
                </tr>
                <tr v-if="!inventoryReport.items.length">
                  <td colspan="10" class="px-4 py-8 text-center text-slate-500">No inventory items found for this shop.</td>
                </tr>
              </tbody>
            </table>
            <div v-if="inventoryReport.items.length" class="flex items-center justify-between gap-3 border-t border-slate-100 px-3 py-3">
              <span class="text-xs text-slate-500">{{ disclosureText('inventory-items', inventoryReport.items) }}</span>
              <button v-if="hasMoreRows(inventoryReport.items)" type="button" class="inline-flex items-center gap-1 text-sm font-bold text-teal-700 hover:text-teal-900" @click="toggleSection('inventory-items')">
                {{ expandedSections['inventory-items'] ? 'Show Less' : 'Show More' }}
                <span aria-hidden="true">{{ expandedSections['inventory-items'] ? '↑' : '↓' }}</span>
              </button>
            </div>
          </div>
        </div>

        <div class="admin-panel overflow-hidden">
          <div class="border-b border-slate-200 p-5">
            <h4 class="flex items-center gap-2 text-lg font-bold text-slate-950"><i class="fas fa-arrow-right-arrow-left text-sm text-teal-700" aria-hidden="true"></i>Stock Movement History</h4>
            <p class="mt-1 text-sm text-slate-500">Movement date basis: stock_movements.created_at. Applied report date range when provided; otherwise, all dates.</p>
            <p class="mt-1 text-xs text-slate-500">OUT quantities are recorded issues, not proof of actual consumption. IN movements may include corrections or reversals; they are not treated as purchases and no net usage is calculated.</p>
          </div>
          <div class="grid grid-cols-1 gap-3 p-5 md:grid-cols-3">
            <div class="rounded-lg border border-slate-200 p-4">
              <p class="text-sm text-slate-500">Recorded Parts Issued to Repairs</p>
              <p class="mt-1 font-bold text-slate-900">{{ inventoryReport.movement_summary.repair_linked_out.movement_count }} movements · {{ inventoryReport.movement_summary.repair_linked_out.quantity }} units</p>
            </div>
            <div class="rounded-lg border border-slate-200 p-4">
              <p class="text-sm text-slate-500">Unlinked / Manual OUT</p>
              <p class="mt-1 font-bold text-slate-900">{{ inventoryReport.movement_summary.unlinked_manual_out.movement_count }} movements · {{ inventoryReport.movement_summary.unlinked_manual_out.quantity }} units</p>
            </div>
            <div class="rounded-lg border border-slate-200 p-4">
              <p class="text-sm text-slate-500">OUT with Unavailable Job Reference</p>
              <p class="mt-1 font-bold text-slate-900">{{ inventoryReport.movement_summary.invalid_job_reference_out.movement_count }} movements · {{ inventoryReport.movement_summary.invalid_job_reference_out.quantity }} units</p>
            </div>
          </div>
          <div class="overflow-x-auto">
            <table class="data-table min-w-[1100px]">
              <thead>
                <tr>
                  <th class="px-3 py-3 text-left">Movement Date</th>
                  <th class="px-3 py-3 text-left">Part</th>
                  <th class="px-3 py-3 text-left">Type</th>
                  <th class="px-3 py-3 text-left">OUT Classification</th>
                  <th class="px-3 py-3 text-right">Signed Quantity Change</th>
                  <th class="px-3 py-3 text-right">Balance After</th>
                  <th class="px-3 py-3 text-left">Job Order</th>
                  <th class="px-3 py-3 text-left">Recorded By</th>
                  <th class="px-3 py-3 text-left">Note</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="movement in visibleRows('stock-movements', inventoryReport.movements)" :key="movement.id">
                  <td class="px-3 py-3 text-slate-700">{{ formatMovementDate(movement.movement_date) }}</td>
                  <td class="px-3 py-3 font-semibold text-slate-900">
                    {{ movement.part_name }}
                    <span class="block text-xs font-normal text-slate-500">{{ movement.part_number || 'No part number' }}</span>
                  </td>
                  <td class="px-3 py-3 text-slate-700">{{ movement.type }}</td>
                  <td class="px-3 py-3 text-slate-700">{{ movement.out_classification }}</td>
                  <td class="px-3 py-3 text-right text-slate-700">{{ movement.quantity_change }}</td>
                  <td class="px-3 py-3 text-right text-slate-700">{{ movement.balance_after }}</td>
                  <td class="px-3 py-3 text-slate-700">{{ movement.job_order_reference || '—' }}</td>
                  <td class="px-3 py-3 text-slate-700">{{ movement.recorded_by || '—' }}</td>
                  <td class="px-3 py-3 text-slate-700">{{ movement.note || '—' }}</td>
                </tr>
                <tr v-if="!inventoryReport.movements.length">
                  <td colspan="9" class="px-4 py-8 text-center text-slate-500">No stock movements found for the applied date scope.</td>
                </tr>
              </tbody>
            </table>
            <div v-if="inventoryReport.movements.length" class="flex items-center justify-between gap-3 border-t border-slate-100 px-3 py-3">
              <span class="text-xs text-slate-500">{{ disclosureText('stock-movements', inventoryReport.movements) }}</span>
              <button v-if="hasMoreRows(inventoryReport.movements)" type="button" class="inline-flex items-center gap-1 text-sm font-bold text-teal-700 hover:text-teal-900" @click="toggleSection('stock-movements')">
                {{ expandedSections['stock-movements'] ? 'Show Less' : 'Show More' }}
                <span aria-hidden="true">{{ expandedSections['stock-movements'] ? '↑' : '↓' }}</span>
              </button>
            </div>
          </div>
        </div>
      </section>

      <div v-if="report && activeTab === 'operations'" class="space-y-5">
      <section class="admin-panel overflow-hidden">
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
              <tr v-for="job in visibleRows('job-orders', report.filteredJobOrders)" :key="job.id">
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
          <div v-if="report.filteredJobOrders.length" class="flex items-center justify-between gap-3 border-t border-slate-100 px-4 py-3">
            <span class="text-xs text-slate-500">{{ disclosureText('job-orders', report.filteredJobOrders) }}</span>
            <button v-if="hasMoreRows(report.filteredJobOrders)" type="button" class="inline-flex items-center gap-1 text-sm font-bold text-teal-700 hover:text-teal-900" @click="toggleSection('job-orders')">
              {{ expandedSections['job-orders'] ? 'Show Less' : 'Show More' }}
              <span aria-hidden="true">{{ expandedSections['job-orders'] ? '↑' : '↓' }}</span>
            </button>
          </div>
        </div>
      </section>

      <section class="grid grid-cols-1 gap-5 xl:grid-cols-2">
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
                <tr v-for="service in visibleRows('top-services', report.topServices)" :key="`${service.service_category_id}-${service.service_item_id}`">
                  <td class="px-4 py-3 font-semibold text-slate-900">{{ service.service_category_name }}</td>
                  <td class="px-4 py-3 text-slate-700">{{ service.service_item_name }}</td>
                  <td class="px-4 py-3 font-bold text-slate-900">{{ service.total }}</td>
                </tr>
                <tr v-if="!report.topServices.length">
                  <td colspan="3" class="px-4 py-8 text-center text-slate-500">No service data found.</td>
                </tr>
              </tbody>
            </table>
            <div v-if="report.topServices.length" class="flex items-center justify-between gap-3 border-t border-slate-100 px-4 py-3">
              <span class="text-xs text-slate-500">{{ disclosureText('top-services', report.topServices) }}</span>
              <button v-if="hasMoreRows(report.topServices)" type="button" class="inline-flex items-center gap-1 text-sm font-bold text-teal-700 hover:text-teal-900" @click="toggleSection('top-services')">
                {{ expandedSections['top-services'] ? 'Show Less' : 'Show More' }}
                <span aria-hidden="true">{{ expandedSections['top-services'] ? '↑' : '↓' }}</span>
              </button>
            </div>
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
                <tr v-for="category in visibleRows('service-categories', report.serviceCategoryBreakdown)" :key="category.service_category_id || category.service_category_name">
                  <td class="px-4 py-3 font-semibold text-slate-900">{{ category.service_category_name }}</td>
                  <td class="px-4 py-3 font-bold text-slate-900">{{ category.total }}</td>
                </tr>
                <tr v-if="!report.serviceCategoryBreakdown.length">
                  <td colspan="2" class="px-4 py-8 text-center text-slate-500">No category data found.</td>
                </tr>
              </tbody>
            </table>
            <div v-if="report.serviceCategoryBreakdown.length" class="flex items-center justify-between gap-3 border-t border-slate-100 px-4 py-3">
              <span class="text-xs text-slate-500">{{ disclosureText('service-categories', report.serviceCategoryBreakdown) }}</span>
              <button v-if="hasMoreRows(report.serviceCategoryBreakdown)" type="button" class="inline-flex items-center gap-1 text-sm font-bold text-teal-700 hover:text-teal-900" @click="toggleSection('service-categories')">
                {{ expandedSections['service-categories'] ? 'Show Less' : 'Show More' }}
                <span aria-hidden="true">{{ expandedSections['service-categories'] ? '↑' : '↓' }}</span>
              </button>
            </div>
          </div>
        </div>
      </section>
      </div>
      </div>

      <section v-if="report && activeTab === 'payments'" class="space-y-4" role="tabpanel" id="reports-panel-payments" aria-labelledby="reports-tab-payments">
        <div>
          <h3 class="flex items-center gap-2 text-xl font-bold text-slate-950"><i class="fas fa-rotate-left text-sm text-teal-700" aria-hidden="true"></i>Refund Overview</h3>
          <p class="mt-1 text-sm text-slate-500">Payment dates use received_at. Pending refund dates use initiated_at; issued refund dates use issued_at. Refund status reflects shop records, not external settlement.</p>
        </div>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
          <div class="admin-panel flex items-center justify-between gap-4 px-5 py-4">
            <div>
              <p class="text-sm font-semibold text-slate-700">Pending Refund Obligations</p>
              <p class="mt-1 text-xs text-slate-500">Recorded obligation · initiated_at date basis</p>
            </div>
            <p class="text-xl font-bold text-amber-700">{{ formatCurrency(report.paymentSummary.pending_refund_obligations) }}</p>
          </div>
          <div class="admin-panel flex items-center justify-between gap-4 px-5 py-4">
            <div>
              <p class="text-sm font-semibold text-slate-700">Refunds Recorded as Issued</p>
              <p class="mt-1 text-xs text-slate-500">Staff-recorded issuance, not external settlement · issued_at date basis</p>
            </div>
            <p class="text-xl font-bold text-slate-900">{{ formatCurrency(report.paymentSummary.issued_refunds) }}</p>
          </div>
        </div>

        <div class="admin-panel overflow-hidden">
          <div class="border-b border-slate-200 px-4 py-3">
            <h4 class="font-semibold text-slate-900">Selected Job Financial Details</h4>
            <p class="mt-1 text-xs text-slate-500">Uses the selected job filters and created_at date range; these figures differ from payment and refund dates and the current unpaid-balance snapshot.</p>
          </div>
          <div class="overflow-x-auto">
            <table class="data-table">
              <thead>
                <tr>
                  <th class="px-4 py-2 text-left text-slate-600">Metric</th>
                  <th class="px-4 py-2 text-right text-slate-600">Amount</th>
                  <th class="px-4 py-2 text-left text-slate-600">Basis</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td class="px-4 py-2 font-medium text-slate-900">Payments recorded on selected jobs</td>
                  <td class="px-4 py-2 text-right text-slate-700">{{ formatCurrency(report.totalCollected) }}</td>
                  <td class="px-4 py-2 text-slate-500">Stored job payment total</td>
                </tr>
                <tr>
                  <td class="px-4 py-2 font-medium text-slate-900">Unpaid amounts on selected jobs</td>
                  <td class="px-4 py-2 text-right text-slate-700">{{ formatCurrency(report.pendingReceivables) }}</td>
                  <td class="px-4 py-2 text-slate-500">Positive balance for selected non-RTO jobs</td>
                </tr>
                <tr>
                  <td class="px-4 py-2 font-medium text-slate-900">Pending RTO refunds on selected jobs</td>
                  <td class="px-4 py-2 text-right text-slate-700">{{ formatCurrency(report.pendingRtoRefunds) }}</td>
                  <td class="px-4 py-2 text-slate-500">Pending refund records attached to selected jobs</td>
                </tr>
                <tr>
                  <td class="px-4 py-2 font-medium text-slate-900">Issued RTO refunds on selected jobs</td>
                  <td class="px-4 py-2 text-right text-slate-700">{{ formatCurrency(report.issuedRtoRefunds) }}</td>
                  <td class="px-4 py-2 text-slate-500">Issued refund records attached to selected jobs</td>
                </tr>
                <tr>
                  <td class="px-4 py-2 font-medium text-slate-900">Payments less issued refunds</td>
                  <td class="px-4 py-2 text-right font-semibold text-slate-900">{{ formatCurrency(report.netRetained) }}</td>
                  <td class="px-4 py-2 text-slate-500">Amount paid on selected jobs minus refunds marked issued</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">
          <div class="admin-panel overflow-hidden">
            <div class="border-b border-slate-200 p-5">
              <h4 class="flex items-center gap-2 font-bold text-slate-950"><i class="fas fa-wallet text-sm text-teal-700" aria-hidden="true"></i>Payments by Method</h4>
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
                  <tr v-for="method in visibleRows('payment-methods', report.paymentMethodBreakdown)" :key="method.payment_method">
                    <td class="px-4 py-3 font-semibold text-slate-900">{{ method.payment_method.replaceAll('_', ' ') }}</td>
                    <td class="px-4 py-3 text-slate-700">{{ method.records }}</td>
                    <td class="px-4 py-3 font-semibold text-slate-900">{{ formatCurrency(method.amount) }}</td>
                  </tr>
                  <tr v-if="!report.paymentMethodBreakdown.length">
                    <td colspan="3" class="px-4 py-8 text-center text-slate-500">No payments found.</td>
                  </tr>
                </tbody>
              </table>
              <div v-if="report.paymentMethodBreakdown.length" class="flex items-center justify-between gap-3 border-t border-slate-100 px-4 py-3">
                <span class="text-xs text-slate-500">{{ disclosureText('payment-methods', report.paymentMethodBreakdown) }}</span>
                <button v-if="hasMoreRows(report.paymentMethodBreakdown)" type="button" class="inline-flex items-center gap-1 text-sm font-bold text-teal-700 hover:text-teal-900" @click="toggleSection('payment-methods')">
                  {{ expandedSections['payment-methods'] ? 'Show Less' : 'Show More' }}
                  <span aria-hidden="true">{{ expandedSections['payment-methods'] ? '↑' : '↓' }}</span>
                </button>
              </div>
            </div>
          </div>

          <div class="admin-panel overflow-hidden">
            <div class="border-b border-slate-200 p-5">
              <h4 class="flex items-center gap-2 font-bold text-slate-950"><i class="fas fa-wallet text-sm text-teal-700" aria-hidden="true"></i>Payments by Type</h4>
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
                  <tr v-for="type in visibleRows('payment-types', report.paymentTypeBreakdown)" :key="type.payment_type">
                    <td class="px-4 py-3 font-semibold text-slate-900">{{ type.payment_type.replaceAll('_', ' ') }}</td>
                    <td class="px-4 py-3 text-slate-700">{{ type.records }}</td>
                    <td class="px-4 py-3 font-semibold text-slate-900">{{ formatCurrency(type.amount) }}</td>
                  </tr>
                  <tr v-if="!report.paymentTypeBreakdown.length">
                    <td colspan="3" class="px-4 py-8 text-center text-slate-500">No payments found.</td>
                  </tr>
                </tbody>
              </table>
              <div v-if="report.paymentTypeBreakdown.length" class="flex items-center justify-between gap-3 border-t border-slate-100 px-4 py-3">
                <span class="text-xs text-slate-500">{{ disclosureText('payment-types', report.paymentTypeBreakdown) }}</span>
                <button v-if="hasMoreRows(report.paymentTypeBreakdown)" type="button" class="inline-flex items-center gap-1 text-sm font-bold text-teal-700 hover:text-teal-900" @click="toggleSection('payment-types')">
                  {{ expandedSections['payment-types'] ? 'Show Less' : 'Show More' }}
                  <span aria-hidden="true">{{ expandedSections['payment-types'] ? '↑' : '↓' }}</span>
                </button>
              </div>
            </div>
          </div>
        </div>

        <div class="admin-panel overflow-hidden">
          <div class="border-b border-slate-200 px-4 py-3">
            <h4 class="font-semibold text-slate-950">Partial Payment Status Breakdown</h4>
            <p class="mt-1 text-xs text-slate-500">Held means not yet released; applied means the job was picked up; needs review means RTO or RTO released.</p>
          </div>
          <div class="overflow-x-auto">
            <table class="data-table">
              <thead>
                <tr>
                  <th class="px-4 py-2 text-left text-slate-600">Status</th>
                  <th class="px-4 py-2 text-right text-slate-600">Amount</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td class="px-4 py-2 font-medium text-slate-900">All partial payments</td>
                  <td class="px-4 py-2 text-right font-semibold text-slate-900">{{ formatCurrency(report.paymentSummary.partial_payment_total) }}</td>
                </tr>
                <tr>
                  <td class="px-4 py-2 font-medium text-slate-900">Held</td>
                  <td class="px-4 py-2 text-right text-slate-700">{{ formatCurrency(report.paymentSummary.held_partial_payments) }}</td>
                </tr>
                <tr>
                  <td class="px-4 py-2 font-medium text-slate-900">Applied</td>
                  <td class="px-4 py-2 text-right text-slate-700">{{ formatCurrency(report.paymentSummary.applied_partial_payments) }}</td>
                </tr>
                <tr>
                  <td class="px-4 py-2 font-medium text-slate-900">Needs review</td>
                  <td class="px-4 py-2 text-right text-slate-700">{{ formatCurrency(report.paymentSummary.review_partial_payments) }}</td>
                </tr>
                <tr v-if="!report.paymentSummary.partial_payment_total">
                  <td colspan="2" class="px-4 py-4 text-center text-slate-500">No partial payments found.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="admin-panel overflow-hidden">
          <div class="border-b border-slate-200 p-5">
            <h4 class="flex items-center gap-2 font-bold text-slate-950"><i class="fas fa-receipt text-sm text-teal-700" aria-hidden="true"></i>Payment History (Latest 30)</h4>
            <p class="mt-1 text-sm text-slate-500">Summary totals include all matching payment records. Payment date uses received_at; when blank, the record creation date is shown for display only.</p>
          </div>
          <div class="overflow-x-auto">
            <table class="data-table">
              <thead>
                <tr>
                  <th class="px-4 py-3 text-left text-slate-600">Payment Date</th>
                  <th class="px-4 py-3 text-left text-slate-600">Job Order</th>
                  <th class="px-4 py-3 text-left text-slate-600">Type</th>
                  <th class="px-4 py-3 text-left text-slate-600">Method</th>
                  <th class="px-4 py-3 text-left text-slate-600">Amount</th>
                  <th class="px-4 py-3 text-left text-slate-600">Reference / Notes</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="payment in visibleRows('payment-history', report.paymentRecords)" :key="payment.id">
                  <td class="px-4 py-3 text-slate-700">
                    {{ payment.received_at_formatted }}
                    <p v-if="payment.received_at_is_fallback" class="text-xs text-amber-700">Record creation date shown</p>
                  </td>
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
            <div v-if="report.paymentRecords.length" class="flex items-center justify-between gap-3 border-t border-slate-100 px-4 py-3">
              <span class="text-xs text-slate-500">{{ disclosureText('payment-history', report.paymentRecords) }}</span>
              <button v-if="hasMoreRows(report.paymentRecords)" type="button" class="inline-flex items-center gap-1 text-sm font-bold text-teal-700 hover:text-teal-900" @click="toggleSection('payment-history')">
                {{ expandedSections['payment-history'] ? 'Show Less' : 'Show More' }}
                <span aria-hidden="true">{{ expandedSections['payment-history'] ? '↑' : '↓' }}</span>
              </button>
            </div>
          </div>
        </div>

        <div class="admin-panel overflow-hidden">
          <div class="border-b border-slate-200 p-5">
            <h4 class="flex items-center gap-2 font-bold text-slate-950"><i class="fas fa-rotate-left text-sm text-teal-700" aria-hidden="true"></i>Refund History</h4>
            <p class="mt-1 text-sm text-slate-500">PENDING means an obligation was recorded; ISSUED means staff recorded the refund as issued. ISSUED does not prove external settlement. Refund amounts are based on refund records, not inferred from RTO status.</p>
            <p class="mt-1 text-xs text-slate-500">Pending refunds use initiated_at for date filters; issued refunds use issued_at. Blank date filters include all applicable records. Pending refunds are not subtracted from Payments Received.</p>
            <p class="mt-1 text-xs text-slate-500">Showing the latest 30 refund records; summary totals include all matching refund records.</p>
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
                  <th class="px-4 py-3 text-left text-slate-600">Method / Reference</th>
                  <th class="px-4 py-3 text-left text-slate-600">Initiated</th>
                  <th class="px-4 py-3 text-left text-slate-600">Issued</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="refund in visibleRows('refund-history', report.refundRecords)" :key="refund.id">
                  <td class="px-4 py-3 font-semibold text-slate-900">{{ refund.job_order_number }}</td>
                  <td class="px-4 py-3 text-slate-700">{{ refund.customer_name }}</td>
                  <td class="px-4 py-3 text-slate-700">{{ refund.reason }}</td>
                  <td class="px-4 py-3 text-slate-700">{{ refund.status }}</td>
                  <td class="px-4 py-3 text-right font-semibold text-slate-900">{{ formatCurrency(refund.amount) }}</td>
                  <td class="px-4 py-3 text-slate-700">
                    {{ refund.refund_method?.replaceAll('_', ' ') || '—' }}
                    <p v-if="refund.reference_number" class="text-xs text-slate-500">{{ refund.reference_number }}</p>
                  </td>
                  <td class="px-4 py-3 text-slate-700">{{ refund.initiated_at || '—' }}</td>
                  <td class="px-4 py-3 text-slate-700">{{ refund.issued_at || 'Not issued' }}</td>
                </tr>
                <tr v-if="!report.refundRecords.length">
                  <td colspan="8" class="px-4 py-8 text-center text-slate-500">No refunds found for this report range.</td>
                </tr>
              </tbody>
            </table>
            <div v-if="report.refundRecords.length" class="flex items-center justify-between gap-3 border-t border-slate-100 px-4 py-3">
              <span class="text-xs text-slate-500">{{ disclosureText('refund-history', report.refundRecords) }}</span>
              <button v-if="hasMoreRows(report.refundRecords)" type="button" class="inline-flex items-center gap-1 text-sm font-bold text-teal-700 hover:text-teal-900" @click="toggleSection('refund-history')">
                {{ expandedSections['refund-history'] ? 'Show Less' : 'Show More' }}
                <span aria-hidden="true">{{ expandedSections['refund-history'] ? '↑' : '↓' }}</span>
              </button>
            </div>
          </div>
        </div>
      </section>
    </div>
  </AdminLayout>
</template>
