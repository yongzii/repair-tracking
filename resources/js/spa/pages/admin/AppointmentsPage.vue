<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AppLink from '../../components/AppLink.vue';
import { ensureRoles } from '../../composables/auth';
import { navigate } from '../../navigation';

const loading = ref(true);
const forbidden = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const appointments = ref({ data: [], links: [] });
const selectedAppointment = ref(null);
const recommendedActions = ref({});
const updatingId = ref(null);
const convertingId = ref(null);
const responseForms = reactive({});
const dropOffRecommendedActions = computed(() => Object.fromEntries(
    Object.entries(recommendedActions.value).filter(([value]) => value !== 'REQUEST_INFORMATION'),
));

const formatStatus = (value) => (value
    ? value.replaceAll('_', ' ').toLowerCase().replace(/\b\w/g, (letter) => letter.toUpperCase())
    : '');
const toDateTimeLocal = (value) => (value ? String(value).slice(0, 16) : '');
const isConsultation = (appointment) => appointment.booking_type === 'CONSULTATION';
const consultationStatusLabel = (appointment) => {
    if (!isConsultation(appointment)) return formatStatus(appointment.status);
    if (appointment.status === 'CANCELLED') return 'Cancelled';
    if (appointment.customer_response?.trim()) return 'Responded';
    if (appointment.status === 'PENDING') return 'Pending';
    if (appointment.status === 'COMPLETED') return 'Resolved';
    return formatStatus(appointment.status);
};
const parseRequestDetails = (concern) => {
    const labels = [
        'Problem description',
        'When it started',
        'Previous damage or incidents',
        'Previous repairs',
        'Troubleshooting already attempted',
        'Additional information',
    ];
    const parts = String(concern || '').split(/\n\n(?=(?:Problem description|When it started|Previous damage or incidents|Previous repairs|Troubleshooting already attempted|Additional information):\n)/);
    const sections = parts.map((part) => {
        const match = part.match(/^([^:\n]+):\n([\s\S]*)$/);
        return match && labels.includes(match[1])
            ? { label: match[1], value: match[2].trim() }
            : null;
    }).filter((section) => section?.value);

    return sections.length ? sections : [{ label: 'Problem description', value: concern }];
};
const openRequest = (appointment) => {
    selectedAppointment.value = appointment;
    errorMessage.value = '';
    successMessage.value = '';
};
const closeRequest = () => {
    selectedAppointment.value = null;
    errorMessage.value = '';
    successMessage.value = '';
};

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
        const selectedId = selectedAppointment.value?.id;
        const { data } = await window.axios.get('/api/admin/appointments', { params: { page } });
        appointments.value = data.appointments;
        recommendedActions.value = data.recommendedActions || {};
        hydrateResponseForms();
        if (selectedId) {
            selectedAppointment.value = appointments.value.data.find((appointment) => appointment.id === selectedId) || null;
        }
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
        await loadAppointments(appointments.value?.current_page || 1);
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to update appointment.';
    } finally {
        updatingId.value = null;
    }
};

const saveConsultationReply = async (appointment, status = 'PENDING') => {
    const form = responseForms[appointment.id] || {};
    const customerResponse = form.customer_response?.trim();
    if (!customerResponse) {
        errorMessage.value = 'Enter a reply to the customer before sending.';
        return;
    }

    errorMessage.value = '';
    successMessage.value = '';
    updatingId.value = appointment.id;

    try {
        const { data } = await window.axios.post(`/api/admin/appointments/${appointment.id}/status`, {
            status,
            customer_response: customerResponse,
            recommended_action: null,
        });
        successMessage.value = status === 'COMPLETED'
            ? 'Consultation marked resolved.'
            : data.message || 'Reply sent to the customer.';
        await loadAppointments(appointments.value?.current_page || 1);
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to send reply.';
    } finally {
        updatingId.value = null;
    }
};

const cancelConsultation = async (appointment) => {
    if (!window.confirm(`Cancel consultation ${appointment.confirmation_code}?`)) return;

    errorMessage.value = '';
    successMessage.value = '';
    updatingId.value = appointment.id;

    try {
        const { data } = await window.axios.post(`/api/admin/appointments/${appointment.id}/status`, {
            status: 'CANCELLED',
        });
        successMessage.value = data.message || 'Consultation cancelled.';
        await loadAppointments(appointments.value?.current_page || 1);
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to cancel consultation.';
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
        await loadAppointments(appointments.value?.current_page || 1);
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Unable to convert appointment to job order.';
    } finally {
        convertingId.value = null;
    }
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

    <div v-else class="space-y-5">
      <section class="admin-hero p-5 sm:p-6">
        <p class="text-xs font-bold uppercase text-teal-700">Customer requests</p>
        <h3 class="mt-2 font-display text-2xl sm:text-3xl">Customer Requests</h3>
        <p class="mt-2 text-sm leading-6 text-slate-600">Select a request to review its details and reply or manage the booking.</p>
      </section>
      <div v-if="successMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700">{{ successMessage }}</div>
      <div v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">{{ errorMessage }}</div>

      <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <button
          v-for="appointment in appointments.data"
          :key="appointment.id"
          type="button"
          class="grid w-full grid-cols-1 gap-2 border-b border-slate-200 px-4 py-4 text-left last:border-b-0 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-teal-700 sm:grid-cols-[minmax(0,1.3fr)_minmax(0,1.5fr)_auto] sm:items-center sm:gap-4 sm:px-5"
          @click="openRequest(appointment)"
        >
          <span class="min-w-0">
            <span class="block truncate font-semibold text-slate-900">{{ appointment.customer_name }}</span>
            <span class="mt-1 block text-xs text-slate-500">{{ appointment.confirmation_code }}</span>
          </span>
          <span class="min-w-0 text-sm text-slate-600">
            <span class="block truncate font-medium text-slate-800">{{ appointment.service_category_name || appointment.device_type }}</span>
            <span v-if="appointment.brand || appointment.model" class="mt-1 block truncate text-xs">{{ [appointment.brand, appointment.model].filter(Boolean).join(' ') }}</span>
          </span>
          <span class="w-fit rounded-full px-3 py-1 text-xs font-bold ring-1" :class="appointment.status === 'PENDING' ? 'bg-amber-50 text-amber-700 ring-amber-200' : appointment.status === 'CANCELLED' ? 'bg-rose-50 text-rose-700 ring-rose-200' : 'bg-emerald-50 text-emerald-700 ring-emerald-200'">
            {{ consultationStatusLabel(appointment) }}
          </span>
        </button>

        <div v-if="!appointments.data.length" class="p-8 text-center text-sm text-slate-500">No customer requests found.</div>
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

    <div v-if="selectedAppointment" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-3 sm:p-6" @click.self="closeRequest" @keydown.esc.window="closeRequest">
      <section role="dialog" aria-modal="true" :aria-labelledby="`request-modal-title-${selectedAppointment.id}`" class="max-h-[92vh] w-full max-w-3xl overflow-y-auto rounded-2xl border border-slate-200 bg-white shadow-2xl">
        <header class="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-slate-200 bg-white p-4 sm:p-6">
          <div class="min-w-0">
            <p class="text-xs font-bold uppercase tracking-wide text-teal-700">{{ isConsultation(selectedAppointment) ? 'Consultation request' : 'Drop-off booking' }}</p>
            <h2 :id="`request-modal-title-${selectedAppointment.id}`" class="mt-1 break-words text-xl font-bold text-slate-950">{{ selectedAppointment.customer_name }}</h2>
            <p class="mt-1 break-all text-sm text-slate-600">{{ selectedAppointment.confirmation_code }}</p>
          </div>
          <button type="button" class="shrink-0 rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" aria-label="Close request details" @click="closeRequest">Close</button>
        </header>

        <div class="space-y-5 p-4 sm:p-6">
          <div v-if="successMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm font-semibold text-emerald-800">{{ successMessage }}</div>
          <div v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm font-semibold text-rose-700">{{ errorMessage }}</div>

          <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full px-3 py-1 text-xs font-bold ring-1" :class="selectedAppointment.status === 'PENDING' ? 'bg-amber-50 text-amber-700 ring-amber-200' : selectedAppointment.status === 'CANCELLED' ? 'bg-rose-50 text-rose-700 ring-rose-200' : 'bg-emerald-50 text-emerald-700 ring-emerald-200'">
              {{ consultationStatusLabel(selectedAppointment) }}
            </span>
            <span class="text-xs text-slate-500">Submitted {{ selectedAppointment.created_at_formatted || 'date unavailable' }}</span>
          </div>

          <section class="rounded-xl border border-slate-200 p-4">
            <h3 class="font-semibold text-slate-900">Customer Information</h3>
            <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
              <div><dt class="text-xs text-slate-500">Name</dt><dd class="mt-1 font-medium text-slate-800">{{ selectedAppointment.customer_name }}</dd></div>
              <div v-if="selectedAppointment.customer_email"><dt class="text-xs text-slate-500">Email</dt><dd class="mt-1 break-all font-medium text-slate-800">{{ selectedAppointment.customer_email }}</dd></div>
              <div v-if="selectedAppointment.customer_phone"><dt class="text-xs text-slate-500">Phone</dt><dd class="mt-1 font-medium text-slate-800">{{ selectedAppointment.customer_phone }}</dd></div>
            </dl>
          </section>

          <section class="rounded-xl border border-slate-200 p-4">
            <h3 class="font-semibold text-slate-900">Device Information</h3>
            <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
              <div><dt class="text-xs text-slate-500">Device category</dt><dd class="mt-1 font-medium text-slate-800">{{ selectedAppointment.service_category_name || selectedAppointment.device_type }}</dd></div>
              <div v-if="selectedAppointment.brand"><dt class="text-xs text-slate-500">Brand</dt><dd class="mt-1 font-medium text-slate-800">{{ selectedAppointment.brand }}</dd></div>
              <div v-if="selectedAppointment.model"><dt class="text-xs text-slate-500">Model</dt><dd class="mt-1 font-medium text-slate-800">{{ selectedAppointment.model }}</dd></div>
              <div v-if="!isConsultation(selectedAppointment) && selectedAppointment.service_item_name"><dt class="text-xs text-slate-500">Service needed</dt><dd class="mt-1 font-medium text-slate-800">{{ selectedAppointment.service_item_name }}</dd></div>
              <div v-if="!isConsultation(selectedAppointment)"><dt class="text-xs text-slate-500">Preferred drop-off</dt><dd class="mt-1 font-medium text-slate-800">{{ selectedAppointment.preferred_at_formatted || 'Not set' }}</dd></div>
              <div v-if="!isConsultation(selectedAppointment)"><dt class="text-xs text-slate-500">Scheduled</dt><dd class="mt-1 font-medium text-slate-800">{{ selectedAppointment.scheduled_at_formatted || 'Not set' }}</dd></div>
            </dl>
          </section>

          <section class="rounded-xl border border-slate-200 p-4">
            <h3 class="font-semibold text-slate-900">{{ isConsultation(selectedAppointment) ? 'Problem and Device History' : 'Problem Description' }}</h3>
            <dl class="mt-3 space-y-3">
              <div v-for="section in parseRequestDetails(selectedAppointment.concern)" :key="section.label">
                <dt class="text-xs font-medium text-slate-500">{{ section.label }}</dt>
                <dd class="mt-1 whitespace-pre-line break-words text-sm text-slate-800">{{ section.value }}</dd>
              </div>
            </dl>
            <a v-if="selectedAppointment.photo_url" :href="selectedAppointment.photo_url" target="_blank" rel="noreferrer" class="mt-4 inline-flex rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">View Submitted Photo</a>
          </section>

          <div v-if="selectedAppointment.customer_response?.trim()" class="rounded-xl border border-teal-200 bg-teal-50 p-4">
            <h3 class="font-semibold text-teal-950">Reply from the Shop</h3>
            <p class="mt-2 whitespace-pre-line text-sm text-teal-900">{{ selectedAppointment.customer_response }}</p>
            <p v-if="selectedAppointment.responded_at_formatted" class="mt-2 text-xs text-teal-800">Last sent {{ selectedAppointment.responded_at_formatted }}</p>
          </div>

          <section v-if="isConsultation(selectedAppointment) && selectedAppointment.status !== 'CANCELLED'" class="rounded-xl border border-slate-200 p-4">
            <h3 class="font-semibold text-slate-900">Reply to Customer</h3>
            <p class="mt-1 text-xs leading-5 text-slate-500">Write your complete advice and any next steps. If an in-person inspection is needed, explain that in your reply.</p>
            <textarea v-model="responseForms[selectedAppointment.id].customer_response" aria-label="Reply to Customer" rows="5" maxlength="3000" class="mt-3 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
            <div class="mt-3 flex flex-wrap gap-2">
              <button type="button" class="btn-primary px-4 py-2 text-sm" :disabled="updatingId === selectedAppointment.id" @click="saveConsultationReply(selectedAppointment)">
                {{ updatingId === selectedAppointment.id ? 'Sending...' : 'Send Reply' }}
              </button>
              <button v-if="selectedAppointment.status === 'PENDING'" type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50" :disabled="updatingId === selectedAppointment.id" @click="saveConsultationReply(selectedAppointment, 'COMPLETED')">Mark Resolved</button>
              <button type="button" class="rounded-lg border border-rose-200 px-4 py-2 text-sm font-bold text-rose-700 hover:bg-rose-50" :disabled="updatingId === selectedAppointment.id" @click="cancelConsultation(selectedAppointment)">Cancel Request</button>
            </div>
          </section>

          <div v-if="selectedAppointment.staff_note" class="rounded-lg bg-slate-50 p-3 text-sm text-slate-600">
            <p class="font-semibold text-slate-700">Internal staff note</p>
            <p class="mt-1 whitespace-pre-line">{{ selectedAppointment.staff_note }}</p>
          </div>

          <section v-if="!isConsultation(selectedAppointment)" class="rounded-xl border border-slate-200 p-4">
            <h3 class="font-semibold text-slate-900">Drop-off Scheduling</h3>
            <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
              <label class="space-y-1">
                <span class="text-xs font-medium text-slate-600">Scheduled Date/Time</span>
                <input v-model="responseForms[selectedAppointment.id].scheduled_at" type="datetime-local" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
              </label>
              <label class="space-y-1">
                <span class="text-xs font-medium text-slate-600">Recommended Next Step</span>
                <select v-model="responseForms[selectedAppointment.id].recommended_action" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                  <option value="">No recommendation yet</option>
                  <option v-for="(label, value) in dropOffRecommendedActions" :key="value" :value="value">{{ label }}</option>
                </select>
              </label>
              <label class="space-y-1 sm:col-span-2">
                <span class="text-xs font-medium text-slate-600">Customer-Facing Response</span>
                <textarea v-model="responseForms[selectedAppointment.id].customer_response" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
              </label>
              <label class="space-y-1 sm:col-span-2">
                <span class="text-xs font-medium text-slate-600">Internal Staff Note</span>
                <textarea v-model="responseForms[selectedAppointment.id].staff_note" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
              </label>
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
              <button type="button" class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-bold text-white hover:bg-emerald-700" :disabled="updatingId === selectedAppointment.id" @click="updateStatus(selectedAppointment, 'CONFIRMED')">Confirm</button>
              <button type="button" class="rounded-lg bg-teal-700 px-3 py-2 text-sm font-bold text-white hover:bg-teal-800" :disabled="updatingId === selectedAppointment.id" @click="updateStatus(selectedAppointment, 'RESCHEDULED')">Reschedule</button>
              <button type="button" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-bold text-white hover:bg-slate-800" :disabled="updatingId === selectedAppointment.id" @click="updateStatus(selectedAppointment, 'COMPLETED')">Complete</button>
              <button type="button" class="rounded-lg bg-amber-400 px-3 py-2 text-sm font-bold text-slate-950 hover:bg-amber-300" :disabled="convertingId === selectedAppointment.id || Boolean(selectedAppointment.converted_job_order_id)" @click="convertToJobOrder(selectedAppointment)">
                {{ selectedAppointment.converted_job_order_id ? 'Converted' : 'Create Job Order' }}
              </button>
              <button type="button" class="rounded-lg bg-rose-600 px-3 py-2 text-sm font-bold text-white hover:bg-rose-700" :disabled="updatingId === selectedAppointment.id" @click="updateStatus(selectedAppointment, 'CANCELLED')">Cancel</button>
            </div>
            <p v-if="selectedAppointment.recommended_action_label" class="mt-3 text-sm text-slate-600">Next step: {{ selectedAppointment.recommended_action_label }}</p>
            <p v-if="selectedAppointment.converted_job_order_id" class="mt-3 text-sm">
              Converted to
              <AppLink :to="`/admin/job-orders/${selectedAppointment.converted_job_order_id}`" class="font-semibold text-slate-900 hover:text-slate-700">
                {{ selectedAppointment.converted_job_order_number || 'job order' }}
              </AppLink>
            </p>
          </section>
        </div>
      </section>
    </div>
  </AdminLayout>
</template>
