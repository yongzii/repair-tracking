<script setup>
import { onMounted, reactive, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import { ensureAdmin } from '../../composables/auth';
import { navigate } from '../../navigation';

const loading = ref(true);
const forbidden = ref(false);
const report = ref(null);

const filters = reactive({
    start_date: '',
    end_date: '',
    status: '',
});

const formatCurrency = (value) => {
    const amount = Number(value || 0);
    return `PHP ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
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
    const query = new URLSearchParams(filters).toString();
    window.location.href = `/api/admin/reports/export/csv${query ? `?${query}` : ''}`;
};

const downloadPdf = () => {
    const query = new URLSearchParams(filters).toString();
    window.location.href = `/api/admin/reports/export/pdf${query ? `?${query}` : ''}`;
};

onMounted(loadReport);
</script>

<template>
  <AdminLayout title="Reports">
    <div v-if="loading" class="card p-6 text-slate-600">Loading reports...</div>
    <div v-else-if="forbidden" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">You do not have access to this page.</div>

    <div v-else class="space-y-6">
      <div class="card p-6">
        <h3 class="text-lg font-semibold text-slate-900 mb-4">Filters</h3>
        <form class="grid grid-cols-1 md:grid-cols-4 gap-3" @submit.prevent="loadReport">
          <input v-model="filters.start_date" type="date" class="px-4 py-2 border border-slate-300 rounded-lg" />
          <input v-model="filters.end_date" type="date" class="px-4 py-2 border border-slate-300 rounded-lg" />
          <select v-model="filters.status" class="px-4 py-2 border border-slate-300 rounded-lg">
            <option value="">All Statuses</option>
            <option value="RECEIVED">RECEIVED</option>
            <option value="DIAGNOSING">DIAGNOSING</option>
            <option value="WAITING_PARTS">WAITING_PARTS</option>
            <option value="IN_PROGRESS">IN_PROGRESS</option>
            <option value="READY_FOR_PICKUP">READY_FOR_PICKUP</option>
            <option value="PICKED_UP">PICKED_UP</option>
            <option value="UNREPAIRABLE">UNREPAIRABLE</option>
          </select>
          <button type="submit" class="bg-slate-900 hover:bg-slate-800 text-white font-semibold py-2 rounded-lg">Apply</button>
        </form>

        <div class="mt-4 flex gap-3">
          <button type="button" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-semibold" @click="downloadCsv">Export CSV</button>
          <button type="button" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold" @click="downloadPdf">Export PDF</button>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-4" v-if="report">
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

      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4" v-if="report">
        <div class="metric-card">
          <p class="text-slate-500 text-sm">Total Revenue</p>
          <p class="text-2xl font-bold text-slate-900">{{ formatCurrency(report.totalRevenue) }}</p>
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
    </div>
  </AdminLayout>
</template>
