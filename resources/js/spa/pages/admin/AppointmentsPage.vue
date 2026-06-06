<script setup>
import { onMounted, reactive, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AppLink from '../../components/AppLink.vue';
import { ensureRoles } from '../../composables/auth';
import { navigate } from '../../navigation';

const loading = ref(true);
const forbidden = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const appointments = ref({ data: [], links: [] });
const statuses = ref([]);
const bookingTypes = ref([]);
const recommendedActions = ref({});
const updatingId = ref(null);
const convertingId = ref(null);
const summary = ref({
    pending: 0,
    confirmed: 0,
    today: 0,
});

const filters = reactive({
    search: '',
    status: '',
    booking_type: '',
});
const responseForms = reactive({});

const formatStatus = (value) => (value ? value.replaceAll('_', ' ') : '');
const toDateTimeLocal = (value) => (value ? String(value).slice(0, 16) : '');

const hydrateResponseForms = () => {
    (appointments.value?.data || []).forEach((appointment) => {
        responseForms[appointment.id] = {
            scheduled_at: toDateTimeLocal(appointment.scheduled_at || appointment.preferred_at),
            staff_note: appointment.staff_note || '',
            customer_response: appointment.customer_response || '',
            recommended_action: appointment.recommended_action || '',
        };
    });
};

const loadAppointments = async (page = 1) => {
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
        const { data } = await window.axios.get('/api/admin/appointments', {
            params: {
                page,
                search: filters.search || undefined,
                status: filters.status || undefined,
                booking_type: filters.booking_type || undefined,
            },
        });
        appointments.value = data.appointments;
        statuses.value = data.statuses || [];
        bookingTypes.value = data.bookingTypes || [];
        recommendedActions.value = data.recommendedActions || {};
        summary.value = data.summary || summary.value;
        hydrateResponseForms();
    } catch (error) {
        forbidden.value = error?.response?.status === 403;
        errorMessage.value = error?.response?.data?.message || 'Unable to load appointments.';
    } finally {
        loading.value = false;
    }
};

const updateStatus = async (appointment, status) => {
    errorMessage.value = '';
    successMessage.value = '';

    const responseForm = responseForms[appointment.id] || {};
    const scheduledAt = responseForm.scheduled_at || null;
    if (['CONFIRMED', 'RESCHEDULED'].includes(status) && !scheduledAt) {
        errorMessage.value = 'Scheduled date/time is required before confirming or rescheduling.';
        return;
    }

    updatingId.value = appointment.id;
    try {
        const { data } = await window.axios.post(`/api/admin/appointments/${appointment.id}/status`, {
            status,
            scheduled_at: scheduledAt || null,
            staff_note: responseForm.staff_note || null,
            customer_response: responseForm.customer_response || null,
            recommended_action: responseForm.recommended_action || null,
        });
        successMessage.value = data.message || 'Appointment updated.';
        await loadAppointments();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to update appointment.';
    } finally {
        updatingId.value = null;
    }
};

const convertToJobOrder = async (appointment) => {
    if (!window.confirm(`Convert ${appointment.confirmation_code} to a job order?`)) return;

    errorMessage.value = '';
    successMessage.value = '';
    convertingId.value = appointment.id;

    try {
        const { data } = await window.axios.post(`/api/admin/appointments/${appointment.id}/convert-to-job-order`);
        successMessage.value = `${data.message} ${data.jobOrder?.job_order_number || ''}`;
        await loadAppointments();
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Unable to convert appointment to job order.';
    } finally {
        convertingId.value = null;
    }
};

const clearFilters = () => {
    filters.search = '';
    filters.status = '';
    filters.booking_type = '';
    loadAppointments(1);
};

const gotoPage = (url) => {
    if (!url) return;
    const parsed = new URL(url, window.location.origin);
    const page = Number(new URLSearchParams(parsed.search).get('page') || 1);
    loadAppointments(page);
};

onMounted(() => loadAppointments());
</script>

<template>
  <AdminLayout title="Customer Requests">
    <div v-if="loading" class="card p-6 text-slate-600">Loading customer requests...</div>
    <div v-else-if="forbidden" class="card border border-rose-200 bg-rose-50 p-6 text-rose-700">You do not have access to this page.</div>

    <div v-else class="space-y-6">
      <section class="admin-hero p-6">
        <p class="text-xs font-bold uppercase text-teal-300">Customer intake</p>
        <h3 class="mt-2 font-display text-3xl">Customer Requests</h3>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">Review consultations and drop-off requests, respond to customers, and convert approved requests into job orders.</p>
      </section>

      <div v-if="successMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700">{{ successMessage }}</div>
      <div v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">{{ errorMessage }}</div>

      <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <div class="metric-card">
          <p class="text-sm text-slate-500">Pending</p>
          <p class="text-3xl font-bold text-amber-700">{{ summary.pending }}</p>
        </div>
        <div class="metric-card">
          <p class="text-sm text-slate-500">Confirmed</p>
          <p class="text-3xl font-bold text-emerald-700">{{ summary.confirmed }}</p>
        </div>
        <div class="metric-card">
          <p class="text-sm text-slate-500">Today</p>
          <p class="text-3xl font-bold text-slate-900">{{ summary.today }}</p>
        </div>
      </div>

      <div class="admin-panel p-5">
        <form class="grid grid-cols-1 gap-3 md:grid-cols-4" @submit.prevent="loadAppointments(1)">
          <input v-model="filters.search" class="md:col-span-2 border border-slate-300 px-4 py-3" placeholder="Search customer requests..." />
          <select v-model="filters.status" class="border border-slate-300 px-4 py-3">
            <option value="">All statuses</option>
            <option v-for="status in statuses" :key="status" :value="status">{{ formatStatus(status) }}</option>
          </select>
          <select v-model="filters.booking_type" class="border border-slate-300 px-4 py-3">
            <option value="">All request types</option>
            <option v-for="type in bookingTypes" :key="type" :value="type">{{ formatStatus(type) }}</option>
          </select>
          <div class="md:col-span-4 flex justify-end gap-3">
            <button type="button" class="btn-soft px-4 py-2" @click="clearFilters">Clear</button>
            <button type="submit" class="btn-primary px-4 py-2">Apply Filters</button>
          </div>
        </form>
      </div>

      <div class="space-y-4">
        <div v-for="appointment in appointments.data" :key="appointment.id" class="admin-panel p-5">
          <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div class="space-y-3">
              <div>
                <div class="flex flex-wrap items-center gap-3">
                  <h3 class="text-lg font-semibold text-slate-900">{{ appointment.customer_name }}</h3>
                  <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700 ring-1 ring-slate-200">{{ appointment.confirmation_code }}</span>
                  <span class="rounded-full px-3 py-1 text-xs font-bold ring-1" :class="appointment.status === 'PENDING' ? 'bg-amber-50 text-amber-700 ring-amber-200' : appointment.status === 'CANCELLED' ? 'bg-rose-50 text-rose-700 ring-rose-200' : 'bg-emerald-50 text-emerald-700 ring-emerald-200'">
                    {{ formatStatus(appointment.status) }}
                  </span>
                </div>
                <p class="mt-1 text-sm text-slate-600">{{ appointment.customer_email || 'No email' }} / {{ appointment.customer_phone || 'No phone' }}</p>
              </div>

              <div class="grid grid-cols-1 gap-3 text-sm md:grid-cols-3">
                <div>
                  <p class="text-slate-500">Type</p>
                  <p class="font-semibold text-slate-900">{{ formatStatus(appointment.booking_type) }}</p>
                </div>
                <div>
                  <p class="text-slate-500">Preferred</p>
                  <p class="font-semibold text-slate-900">{{ appointment.preferred_at_formatted }}</p>
                </div>
                <div>
                  <p class="text-slate-500">Scheduled</p>
                  <p class="font-semibold text-slate-900">{{ appointment.scheduled_at_formatted || 'Not set' }}</p>
                </div>
              </div>

              <div class="rounded-lg bg-slate-50 p-3">
                <p class="text-sm font-semibold text-slate-900">{{ [appointment.brand, appointment.model].filter(Boolean).join(' ') || appointment.device_type }}</p>
                <p class="mt-1 text-sm text-slate-600">{{ appointment.concern }}</p>
                <a
                  v-if="appointment.photo_url"
                  :href="appointment.photo_url"
                  target="_blank"
                  rel="noreferrer"
                  class="mt-2 inline-flex rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-white"
                >
                  View Submitted Photo
                </a>
                <p v-if="appointment.customer_response" class="mt-2 text-sm text-emerald-800">Customer response: {{ appointment.customer_response }}</p>
                <p v-if="appointment.recommended_action_label" class="mt-1 text-sm text-slate-700">Next step: {{ appointment.recommended_action_label }}</p>
                <p v-if="appointment.staff_note" class="mt-1 text-sm text-slate-500">Internal note: {{ appointment.staff_note }}</p>
                <p v-if="appointment.converted_job_order_id" class="mt-2 text-sm">
                  Converted to
                  <AppLink :to="`/admin/job-orders/${appointment.converted_job_order_id}`" class="font-semibold text-slate-900 hover:text-slate-700">
                    {{ appointment.converted_job_order_number || 'job order' }}
                  </AppLink>
                </p>
              </div>

              <div class="rounded-lg border border-slate-200 p-3">
                <p class="mb-3 text-sm font-semibold text-slate-900">Staff Response</p>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                  <label class="space-y-1">
                    <span class="text-xs font-medium text-slate-600">Scheduled Date/Time</span>
                    <input v-model="responseForms[appointment.id].scheduled_at" type="datetime-local" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                  </label>
                  <label class="space-y-1">
                    <span class="text-xs font-medium text-slate-600">Recommended Next Step</span>
                    <select v-model="responseForms[appointment.id].recommended_action" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                      <option value="">No recommendation yet</option>
                      <option v-for="(label, value) in recommendedActions" :key="value" :value="value">{{ label }}</option>
                    </select>
                  </label>
                  <label class="space-y-1 md:col-span-2">
                    <span class="text-xs font-medium text-slate-600">Customer-Facing Response</span>
                    <textarea v-model="responseForms[appointment.id].customer_response" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                  </label>
                  <label class="space-y-1 md:col-span-2">
                    <span class="text-xs font-medium text-slate-600">Internal Staff Note</span>
                    <textarea v-model="responseForms[appointment.id].staff_note" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                  </label>
                </div>
              </div>
            </div>

            <div class="flex flex-wrap gap-2 xl:justify-end">
              <button type="button" class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-bold text-white hover:bg-emerald-700" :disabled="updatingId === appointment.id" @click="updateStatus(appointment, 'CONFIRMED')">Confirm</button>
              <button type="button" class="rounded-lg bg-teal-600 px-3 py-2 text-sm font-bold text-white hover:bg-teal-700" :disabled="updatingId === appointment.id" @click="updateStatus(appointment, 'RESCHEDULED')">Reschedule</button>
              <button type="button" class="rounded-lg bg-slate-950 px-3 py-2 text-sm font-bold text-white hover:bg-slate-800" :disabled="updatingId === appointment.id" @click="updateStatus(appointment, 'COMPLETED')">Complete</button>
              <button type="button" class="rounded-lg bg-amber-400 px-3 py-2 text-sm font-bold text-slate-950 hover:bg-amber-300" :disabled="convertingId === appointment.id || Boolean(appointment.converted_job_order_id)" @click="convertToJobOrder(appointment)">
                {{ appointment.converted_job_order_id ? 'Converted' : 'Create Job Order' }}
              </button>
              <button type="button" class="rounded-lg bg-rose-600 px-3 py-2 text-sm font-bold text-white hover:bg-rose-700" :disabled="updatingId === appointment.id" @click="updateStatus(appointment, 'CANCELLED')">Cancel</button>
            </div>
          </div>
        </div>

        <div v-if="!appointments.data.length" class="admin-panel p-8 text-center text-slate-500">No customer requests found.</div>
      </div>

      <div class="flex flex-wrap gap-2">
        <button
          v-for="link in appointments.links"
          :key="link.label + String(link.url)"
          type="button"
          class="rounded-lg px-3 py-2 text-sm font-bold"
          :class="link.active ? 'bg-slate-950 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 disabled:text-slate-300'"
          :disabled="!link.url"
          @click="gotoPage(link.url)"
          v-html="link.label"
        ></button>
      </div>
    </div>
  </AdminLayout>
</template>
