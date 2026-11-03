<script setup>
import { onMounted, reactive, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import { ensureRoles } from '../../composables/auth';
import { navigate } from '../../navigation';

const loading = ref(true);
const forbidden = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const appointments = ref({ data: [], links: [] });
const selectedAppointment = ref(null);
const updatingId = ref(null);
const responseForms = reactive({});

const consultationStatusLabel = (appointment) => {
    if (appointment.status === 'CANCELLED') return 'Cancelled';
    if (appointment.customer_response?.trim()) return 'Responded';
    if (appointment.status === 'PENDING') return 'Pending';
    if (appointment.status === 'COMPLETED') return 'Resolved';
    return appointment.status?.replaceAll('_', ' ') || '';
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
            staff_note: appointment.staff_note || '',
            customer_response: appointment.customer_response || '',
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
        hydrateResponseForms();
        if (selectedId) {
            selectedAppointment.value = appointments.value.data.find((appointment) => appointment.id === selectedId) || null;
        }
    } catch (error) {
        forbidden.value = error?.response?.status === 403;
        errorMessage.value = error?.response?.data?.message || 'Unable to load consultation requests.';
    } finally {
        loading.value = false;
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
            staff_note: form.staff_note || null,
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
        <p class="mt-2 text-sm leading-6 text-slate-600">Select a consultation request to review the details and reply to the customer.</p>
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
            <p class="text-xs font-bold uppercase tracking-wide text-teal-700">Consultation request</p>
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
            </dl>
          </section>

          <section class="rounded-xl border border-slate-200 p-4">
            <h3 class="font-semibold text-slate-900">Problem and Device History</h3>
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

          <section v-if="selectedAppointment.status !== 'CANCELLED'" class="rounded-xl border border-slate-200 p-4">
            <h3 class="font-semibold text-slate-900">Reply to Customer</h3>
            <p class="mt-1 text-xs leading-5 text-slate-500">Write your complete advice and any next steps. If an in-person inspection is needed, explain that in your reply.</p>
            <textarea v-model="responseForms[selectedAppointment.id].customer_response" aria-label="Reply to Customer" rows="5" maxlength="3000" class="mt-3 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
            <label class="mt-3 block space-y-1">
              <span class="text-xs font-medium text-slate-600">Internal Staff Note</span>
              <textarea v-model="responseForms[selectedAppointment.id].staff_note" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
            </label>
            <div class="mt-3 flex flex-wrap gap-2">
              <button type="button" class="btn-primary px-4 py-2 text-sm" :disabled="updatingId === selectedAppointment.id" @click="saveConsultationReply(selectedAppointment)">
                {{ updatingId === selectedAppointment.id ? 'Sending...' : 'Send Reply' }}
              </button>
              <button v-if="selectedAppointment.status === 'PENDING'" type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50" :disabled="updatingId === selectedAppointment.id" @click="saveConsultationReply(selectedAppointment, 'COMPLETED')">Mark Resolved</button>
              <button type="button" class="rounded-lg border border-rose-200 px-4 py-2 text-sm font-bold text-rose-700 hover:bg-rose-50" :disabled="updatingId === selectedAppointment.id" @click="cancelConsultation(selectedAppointment)">Cancel Request</button>
            </div>
          </section>

          <div v-if="selectedAppointment.staff_note && selectedAppointment.status === 'CANCELLED'" class="rounded-lg bg-slate-50 p-3 text-sm text-slate-600">
            <p class="font-semibold text-slate-700">Internal staff note</p>
            <p class="mt-1 whitespace-pre-line">{{ selectedAppointment.staff_note }}</p>
          </div>
        </div>
      </section>
    </div>
  </AdminLayout>
</template>
