<script setup>
import { computed, reactive, ref } from 'vue';
import AppLink from '../components/AppLink.vue';
import { isCsrfMismatch, refreshCsrfToken } from '../composables/auth';

const props = defineProps({
    params: {
        type: Object,
        default: () => ({}),
    },
});

const shopSlug = ref(props.params.shopSlug || '');
const isConsultationMode = computed(() => props.params.mode === 'consultation');
const defaultBookingType = isConsultationMode.value ? 'CONSULTATION' : 'DROP_OFF';
const pageLabel = computed(() => isConsultationMode.value ? 'Free Consultation' : 'Drop-off Booking');
const pageTitle = computed(() => isConsultationMode.value ? 'Request Free Pre-Diagnosis' : 'Book a Device Drop-off');
const concernLabel = computed(() => isConsultationMode.value ? 'Describe the Issue' : 'Concern');
const preferredTimeLabel = computed(() => isConsultationMode.value ? 'Preferred Consultation Time' : 'Preferred Drop-off Date and Time');
const submitLabel = computed(() => isConsultationMode.value ? 'Submit Consultation' : 'Submit Drop-off Request');
const successFallback = computed(() => isConsultationMode.value ? 'Consultation request submitted.' : 'Drop-off request submitted.');
const loading = ref(false);
const lookupLoading = ref(false);
const errorMessage = ref('');
const lookupErrorMessage = ref('');
const successMessage = ref('');
const appointment = ref(null);
const statusAppointment = ref(null);
const photoInput = ref(null);
const consultationPhoto = ref(null);

const form = reactive({
    customer_name: '',
    customer_email: '',
    customer_phone: '',
    booking_type: defaultBookingType,
    device_type: '',
    brand: '',
    model: '',
    concern: '',
    preferred_at: '',
});
const statusForm = reactive({
    shop_slug: props.params.shopSlug || '',
    confirmation_code: '',
});

const formatStatus = (value) => (value ? value.replaceAll('_', ' ') : '');

const onPhotoChange = (event) => {
    consultationPhoto.value = event.target.files?.[0] || null;
};

const buildPayload = () => {
    const payload = new FormData();

    Object.entries(form).forEach(([key, value]) => {
        payload.append(key, value || '');
    });

    payload.set('customer_email', form.customer_email || '');
    payload.set('customer_phone', form.customer_phone || '');
    payload.set('brand', form.brand || '');
    payload.set('model', form.model || '');

    if (isConsultationMode.value && consultationPhoto.value) {
        payload.append('photo', consultationPhoto.value);
    }

    return payload;
};

const submitRequest = async () => {
    loading.value = true;
    errorMessage.value = '';
    successMessage.value = '';
    appointment.value = null;

    const normalizedShopSlug = shopSlug.value.trim().toLowerCase();
    if (!normalizedShopSlug) {
        errorMessage.value = 'Please enter the shop code before booking.';
        loading.value = false;
        return;
    }

    const payload = buildPayload();

    try {
        await refreshCsrfToken();
        const { data } = await window.axios.post(`/api/shops/${encodeURIComponent(normalizedShopSlug)}/appointments`, payload);
        appointment.value = data.appointment;
        statusAppointment.value = data.appointment;
        statusForm.shop_slug = normalizedShopSlug;
        statusForm.confirmation_code = data.appointment?.confirmation_code || '';
        successMessage.value = data.message || successFallback.value;
        Object.assign(form, {
            customer_name: '',
            customer_email: '',
            customer_phone: '',
            booking_type: defaultBookingType,
            device_type: '',
            brand: '',
            model: '',
            concern: '',
            preferred_at: '',
        });
        consultationPhoto.value = null;
        if (photoInput.value) {
            photoInput.value.value = '';
        }
    } catch (error) {
        if (isCsrfMismatch(error)) {
            try {
                await refreshCsrfToken();
                const { data } = await window.axios.post(`/api/shops/${encodeURIComponent(normalizedShopSlug)}/appointments`, payload);
                appointment.value = data.appointment;
                statusAppointment.value = data.appointment;
                statusForm.shop_slug = normalizedShopSlug;
                statusForm.confirmation_code = data.appointment?.confirmation_code || '';
                successMessage.value = data.message || successFallback.value;
                return;
            } catch (retryError) {
                error = retryError;
            }
        }

        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to submit appointment request.';
    } finally {
        loading.value = false;
    }
};

const lookupStatus = async () => {
    lookupLoading.value = true;
    lookupErrorMessage.value = '';
    statusAppointment.value = null;

    const normalizedShopSlug = statusForm.shop_slug.trim().toLowerCase();
    const confirmationCode = statusForm.confirmation_code.trim().toUpperCase();

    if (!normalizedShopSlug || !confirmationCode) {
        lookupErrorMessage.value = 'Shop code and confirmation code are required.';
        lookupLoading.value = false;
        return;
    }

    try {
        const { data } = await window.axios.get(`/api/shops/${encodeURIComponent(normalizedShopSlug)}/appointments/${encodeURIComponent(confirmationCode)}`);
        statusAppointment.value = data.appointment;
    } catch (error) {
        lookupErrorMessage.value = error?.response?.data?.message || 'Appointment request not found.';
    } finally {
        lookupLoading.value = false;
    }
};
</script>

<template>
  <div class="min-h-screen bg-slate-50 py-10 px-4">
    <div class="mx-auto max-w-5xl space-y-6">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <p class="text-xs uppercase tracking-[0.2em] text-slate-500">{{ pageLabel }}</p>
          <h1 class="mt-2 text-3xl font-bold text-slate-900">{{ pageTitle }}</h1>
          <p v-if="isConsultationMode" class="mt-2 max-w-2xl text-sm text-slate-600">
            Submit your device concern first so the shop can review it, recommend the next step, and tell you whether to bring the unit in.
          </p>
          <p v-else class="mt-2 max-w-2xl text-sm text-slate-600">
            Schedule when you plan to bring the device to the shop so staff can prepare the intake queue.
          </p>
        </div>
        <AppLink :to="shopSlug ? `/shops/${shopSlug}/track` : '/track'" class="rounded-lg bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-200">Track Repair</AppLink>
      </div>

      <div v-if="errorMessage" class="card border border-rose-200 bg-rose-50 p-4 text-rose-700">{{ errorMessage }}</div>
      <div v-if="successMessage" class="card border border-emerald-200 bg-emerald-50 p-4 text-emerald-800">
        {{ successMessage }}
        <p v-if="appointment" class="mt-2 text-sm">Confirmation code: <span class="font-semibold">{{ appointment.confirmation_code }}</span></p>
      </div>

      <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
      <div class="card p-6 xl:col-span-2">
        <form class="grid grid-cols-1 gap-4 md:grid-cols-2" @submit.prevent="submitRequest">
          <label class="space-y-2 md:col-span-2">
            <span class="text-sm font-medium text-slate-700">Shop Code</span>
            <input v-model="shopSlug" class="w-full rounded-lg border border-slate-300 px-4 py-2" placeholder="default-shop" required />
          </label>

          <label class="space-y-2">
            <span class="text-sm font-medium text-slate-700">Customer Name</span>
            <input v-model="form.customer_name" class="w-full rounded-lg border border-slate-300 px-4 py-2" required />
          </label>

          <label class="space-y-2">
            <span class="text-sm font-medium text-slate-700">Email</span>
            <input v-model="form.customer_email" type="email" class="w-full rounded-lg border border-slate-300 px-4 py-2" />
          </label>

          <label class="space-y-2">
            <span class="text-sm font-medium text-slate-700">Phone</span>
            <input v-model="form.customer_phone" class="w-full rounded-lg border border-slate-300 px-4 py-2" />
          </label>

          <label class="space-y-2">
            <span class="text-sm font-medium text-slate-700">Device Type</span>
            <input v-model="form.device_type" class="w-full rounded-lg border border-slate-300 px-4 py-2" placeholder="Laptop, phone, desktop..." required />
          </label>

          <label class="space-y-2">
            <span class="text-sm font-medium text-slate-700">{{ preferredTimeLabel }}</span>
            <input v-model="form.preferred_at" type="datetime-local" class="w-full rounded-lg border border-slate-300 px-4 py-2" required />
          </label>

          <label class="space-y-2">
            <span class="text-sm font-medium text-slate-700">Brand</span>
            <input v-model="form.brand" class="w-full rounded-lg border border-slate-300 px-4 py-2" />
          </label>

          <label class="space-y-2">
            <span class="text-sm font-medium text-slate-700">Model</span>
            <input v-model="form.model" class="w-full rounded-lg border border-slate-300 px-4 py-2" />
          </label>

          <label class="space-y-2 md:col-span-2">
            <span class="text-sm font-medium text-slate-700">{{ concernLabel }}</span>
            <textarea v-model="form.concern" rows="5" class="w-full rounded-lg border border-slate-300 px-4 py-2" required></textarea>
          </label>

          <label v-if="isConsultationMode" class="space-y-2 md:col-span-2">
            <span class="text-sm font-medium text-slate-700">Photo of Device or Issue</span>
            <input ref="photoInput" type="file" accept="image/jpeg,image/png,image/webp" class="w-full rounded-lg border border-slate-300 px-4 py-2" @change="onPhotoChange" />
            <span class="block text-xs text-slate-500">Optional. JPG, PNG, or WebP up to 5 MB.</span>
          </label>

          <div class="md:col-span-2 flex justify-end">
            <button type="submit" class="rounded-lg bg-slate-900 px-6 py-2 font-semibold text-white hover:bg-slate-800" :disabled="loading">
              {{ loading ? 'Submitting...' : submitLabel }}
            </button>
          </div>
        </form>
      </div>

      <div class="card p-6">
        <h2 class="text-lg font-semibold text-slate-900">Check Request Status</h2>
        <form class="mt-4 space-y-3" @submit.prevent="lookupStatus">
          <label class="space-y-2 block">
            <span class="text-sm font-medium text-slate-700">Shop Code</span>
            <input v-model="statusForm.shop_slug" class="w-full rounded-lg border border-slate-300 px-4 py-2" placeholder="default-shop" required />
          </label>
          <label class="space-y-2 block">
            <span class="text-sm font-medium text-slate-700">Confirmation Code</span>
            <input v-model="statusForm.confirmation_code" class="w-full rounded-lg border border-slate-300 px-4 py-2" placeholder="APT-2026-ABC123" required />
          </label>
          <button type="submit" class="w-full rounded-lg bg-slate-900 px-4 py-2 font-semibold text-white hover:bg-slate-800" :disabled="lookupLoading">
            {{ lookupLoading ? 'Checking...' : 'Check Status' }}
          </button>
        </form>

        <div v-if="lookupErrorMessage" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{{ lookupErrorMessage }}</div>

        <div v-if="statusAppointment" class="mt-4 space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm">
          <div>
            <p class="text-slate-500">Status</p>
            <p class="font-semibold text-slate-900">{{ formatStatus(statusAppointment.status) }}</p>
          </div>
          <div>
            <p class="text-slate-500">Preferred</p>
            <p class="font-semibold text-slate-900">{{ statusAppointment.preferred_at_formatted }}</p>
          </div>
          <div>
            <p class="text-slate-500">Scheduled</p>
            <p class="font-semibold text-slate-900">{{ statusAppointment.scheduled_at_formatted || 'Not set yet' }}</p>
          </div>
          <div v-if="statusAppointment.customer_response">
            <p class="text-slate-500">Shop Response</p>
            <p class="font-semibold text-slate-900">{{ statusAppointment.customer_response }}</p>
          </div>
          <div v-if="statusAppointment.photo_url">
            <p class="text-slate-500">Submitted Photo</p>
            <a :href="statusAppointment.photo_url" target="_blank" rel="noreferrer" class="font-semibold text-slate-900 hover:text-slate-700">View photo</a>
          </div>
          <div v-if="statusAppointment.recommended_action_label">
            <p class="text-slate-500">Next Step</p>
            <p class="font-semibold text-slate-900">{{ statusAppointment.recommended_action_label }}</p>
          </div>
        </div>
      </div>
      </div>
    </div>
  </div>
</template>
