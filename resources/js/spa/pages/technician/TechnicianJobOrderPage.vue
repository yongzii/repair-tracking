<script setup>
import { onMounted, ref } from 'vue';
import TechnicianLayout from '../../layouts/TechnicianLayout.vue';
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
const successMessage = ref('');
const job = ref(null);
const timeline = ref([]);
const allowedStatuses = ref([]);
const availableParts = ref([]);
const removingPartId = ref(null);
const selectedPhoto = ref(null);
const uploadingPhoto = ref(false);

const form = ref({
    status: '',
    note: '',
});

const photoForm = ref({
    photo_type: 'DURING',
    description: '',
});

const partForm = ref({
    spare_part_id: '',
    quantity: 1,
    notes: '',
});

const requestForm = ref({
    part_name: '',
    quantity: 1,
    note: '',
});
const submittingRequest = ref(false);
const requestIdempotencyKey = ref(null);

const partOrderForm = ref({
    part_name: '',
    quantity: 1,
    reason: '',
    estimated_unit_price: '',
});

const approvalForm = ref({
  repair_approved_by_customer: '',
  repair_approval_notes: '',
});

const formatDateTime = (value) => value ? new Date(value).toLocaleString() : '';

const resetForms = () => {
    form.value.note = '';
    partForm.value = {
        spare_part_id: '',
        quantity: 1,
        notes: '',
    };
    requestForm.value = {
        part_name: '',
        quantity: 1,
        note: '',
    };
    requestIdempotencyKey.value = null;
    partOrderForm.value = {
        part_name: '',
        quantity: 1,
        reason: '',
        estimated_unit_price: '',
    };
    selectedPhoto.value = null;
    photoForm.value = {
        photo_type: 'DURING',
        description: '',
    };
};

const loadJob = async () => {
    const auth = await ensureRoles(['technician']);
    if (!auth.ok) {
        if (auth.reason === 'unauthenticated') {
            navigate('/login', { replace: true });
            return;
        }

        forbidden.value = true;
        loading.value = false;
        return;
    }

    loading.value = true;

    try {
        const { data } = await window.axios.get(`/api/technician/job-orders/${props.params.id}`);
        job.value = data.job;
        timeline.value = data.timeline || [];
        allowedStatuses.value = data.allowedStatuses || [];
        availableParts.value = data.availableParts || [];
        form.value.status = data.job.current_status;
        approvalForm.value.repair_approved_by_customer = data.job.repair_approved_by_customer || '';
        approvalForm.value.repair_approval_notes = data.job.repair_approval_notes || '';
        resetForms();
        forbidden.value = false;
        errorMessage.value = '';
    } catch (error) {
        forbidden.value = true;
        errorMessage.value = error?.response?.data?.message || 'Unable to load assigned job.';
    } finally {
        loading.value = false;
    }
};

const submit = async () => {
    errorMessage.value = '';
    successMessage.value = '';

    try {
        const { data } = await window.axios.post(`/api/technician/job-orders/${props.params.id}/update-status`, form.value);
        successMessage.value = data.message || 'Job updated successfully.';
        await loadJob();
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Unable to update job status.';
    }
};

const recordRepairApproval = async () => {
  errorMessage.value = '';
  successMessage.value = '';

  try {
    const { data } = await window.axios.post(`/api/technician/job-orders/${props.params.id}/record-repair-approval`, approvalForm.value);
    job.value = { ...job.value, ...data.jobOrder };
    successMessage.value = data.message || 'Customer repair approval recorded.';
  } catch (error) {
    const firstError = error?.response?.data?.errors
      ? Object.values(error.response.data.errors)[0]?.[0]
      : null;
    errorMessage.value = firstError || error?.response?.data?.message || 'Unable to record customer approval.';
  }
};

const onPhotoSelected = (event) => {
    selectedPhoto.value = event.target.files?.[0] || null;
};

const uploadPhoto = async () => {
    if (!selectedPhoto.value) return;
    errorMessage.value = '';
    successMessage.value = '';
    uploadingPhoto.value = true;

    const payload = new FormData();
    payload.append('photo', selectedPhoto.value);
    payload.append('photo_type', photoForm.value.photo_type);
    payload.append('description', photoForm.value.description || '');

    try {
        const { data } = await window.axios.post(`/api/technician/job-orders/${props.params.id}/photos`, payload, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        successMessage.value = data.message || 'Photo uploaded successfully.';
        await loadJob();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to upload photo.';
    } finally {
        uploadingPhoto.value = false;
    }
};

const deletePhoto = async (photo) => {
    if (!window.confirm('Delete this job photo?')) {
        return;
    }

    errorMessage.value = '';
    successMessage.value = '';

    try {
        const { data } = await window.axios.delete(`/api/technician/job-orders/${props.params.id}/photos/${photo.id}`);
        successMessage.value = data.message || 'Photo deleted.';
        await loadJob();
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Unable to delete photo.';
    }
};

  const togglePhotoVisibility = async (photo) => {
    errorMessage.value = '';
    successMessage.value = '';

    try {
      const { data } = await window.axios.post(`/api/technician/job-orders/${props.params.id}/photos/${photo.id}/visibility`, {
        visible_to_customer: !photo.visible_to_customer,
      });
      Object.assign(photo, data.photo);
      successMessage.value = data.message || 'Photo visibility updated.';
    } catch (error) {
      errorMessage.value = error?.response?.data?.message || 'Unable to update photo visibility.';
    }
  };

const addPart = async () => {
    errorMessage.value = '';
    successMessage.value = '';

    try {
        const { data } = await window.axios.post(`/api/technician/job-orders/${props.params.id}/parts`, {
            spare_part_id: partForm.value.spare_part_id,
            quantity: partForm.value.quantity,
            notes: partForm.value.notes || null,
        });

        successMessage.value = data.message || 'Part recorded successfully.';
        await loadJob();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to record part usage.';
    }
};

const submitPartOrder = async () => {
    errorMessage.value = '';
    successMessage.value = '';

    try {
        const { data } = await window.axios.post(`/api/technician/job-orders/${props.params.id}/part-orders`, partOrderForm.value);
        successMessage.value = data.message || 'Customer-specific parts order submitted.';
        await loadJob();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to submit parts order.';
    }
};

const issueReceivedPartOrder = async (partOrder) => {
    if (!window.confirm(`Issue ${partOrder.quantity} ${partOrder.part_name} from inventory to this repair?`)) {
        return;
    }

    errorMessage.value = '';
    successMessage.value = '';

    try {
        const { data } = await window.axios.patch(`/api/technician/job-orders/${props.params.id}/part-orders/${partOrder.id}/issue`);
        successMessage.value = data.message || 'Received part issued to the Job Order.';
        await loadJob();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to issue this received part.';
    }
};

const updateDeviceLocation = async (deviceLocation) => {
    errorMessage.value = '';
    successMessage.value = '';

    try {
        const { data } = await window.axios.patch(`/api/technician/job-orders/${props.params.id}/device-location`, {
            device_location: deviceLocation,
        });
        job.value = { ...job.value, ...data.job_order };
        successMessage.value = data.message || 'Device location updated.';
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to update device location.';
    }
};

const removePart = async (part) => {
    if (!window.confirm(`Remove ${part.part_name} from this job and restore the stock?`)) {
        return;
    }

    errorMessage.value = '';
    successMessage.value = '';
    removingPartId.value = part.id;

    try {
        const { data } = await window.axios.delete(`/api/technician/job-orders/${props.params.id}/parts/${part.id}`);
        successMessage.value = data.message || 'Part removed successfully.';
        await loadJob();
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Unable to remove part entry.';
    } finally {
        removingPartId.value = null;
    }
};

const requestPart = async () => {
    errorMessage.value = '';
    successMessage.value = '';
    submittingRequest.value = true;
    if (!requestIdempotencyKey.value) {
        requestIdempotencyKey.value = window.crypto.randomUUID();
    }

    try {
        const { data } = await window.axios.post('/api/technician/part-requests', {
            part_name: requestForm.value.part_name,
            quantity: requestForm.value.quantity,
            note: requestForm.value.note || null,
            idempotency_key: requestIdempotencyKey.value,
        });

        successMessage.value = data.message || 'Internal purchasing request submitted.';
        requestForm.value = { part_name: '', quantity: 1, note: '' };
        requestIdempotencyKey.value = null;
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to record parts request.';
    } finally {
        submittingRequest.value = false;
    }
};

const requestBlockedJobPart = async () => {
    if (!window.confirm('This is a job-linked repair blockage and will mark this Job Order as Waiting for Parts. Continue?')) {
        return;
    }

    errorMessage.value = '';
    successMessage.value = '';

    try {
        const { data } = await window.axios.post(`/api/technician/job-orders/${props.params.id}/blocked-part-requests`, {
            part_name: requestForm.value.part_name,
            quantity: requestForm.value.quantity,
            note: requestForm.value.note || null,
        });
        successMessage.value = data.message || 'Job marked Waiting for Parts.';
        await loadJob();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to mark this job as waiting for parts.';
    }
};

const formatCurrency = (value) => {
    const amount = Number(value || 0);
    return `PHP ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

const formatStatus = (status) => {
    if (status === 'PICKED_UP') return 'Released';
    if (status === 'RTO_RELEASED') return 'RTO Released';
    if (status === 'RTO' || status === 'UNREPAIRABLE') return 'RTO';
    return status ? status.replaceAll('_', ' ') : '';
};

onMounted(loadJob);
</script>

<template>
  <TechnicianLayout :title="job ? job.job_order_number : 'Assigned Job'">
    <div v-if="loading" class="card p-6 text-slate-600">Loading assigned job...</div>
    <div v-else-if="forbidden" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">
      {{ errorMessage || 'You do not have access to this page.' }}
    </div>

    <div v-else-if="job" class="space-y-6">
      <div v-if="successMessage" class="rounded border border-emerald-200 bg-emerald-50 p-3 text-emerald-700">
        {{ successMessage }}
      </div>
      <div v-if="errorMessage" class="rounded border border-rose-200 bg-rose-50 p-3 text-rose-700">
        {{ errorMessage }}
      </div>

      <div class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
          <p class="text-xs font-bold uppercase tracking-[0.16em] text-teal-700">Assigned repair</p>
          <h3 class="mt-1 break-words text-2xl font-bold text-slate-950">{{ job.job_order_number }}</h3>
          <p class="mt-1 text-sm text-slate-600">{{ job.customer_name }} · {{ [job.brand, job.model].filter(Boolean).join(' ') || job.device_type }}</p>
        </div>
        <AppLink to="/technician" class="btn-soft inline-flex shrink-0 items-center justify-center px-4 py-2 text-sm">Back to My Jobs</AppLink>
      </div>

      <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
          <div class="card p-6">
            <h4 class="mb-4 text-lg font-semibold text-slate-900">Job Details</h4>
            <div class="grid grid-cols-1 gap-4 text-sm md:grid-cols-2">
              <div>
                <p class="text-slate-500">Status</p>
                <p class="font-semibold text-slate-900">{{ formatStatus(job.current_status) }}</p>
              </div>
              <div>
                <p class="text-slate-500">Priority</p>
                <p class="font-semibold text-slate-900">{{ job.priority }}</p>
              </div>
              <div class="md:col-span-2">
                <p class="text-slate-500">Device Location</p>
                <p class="font-semibold text-slate-900">{{ job.device_location === 'AT_SHOP' ? 'At Shop' : job.device_location === 'WITH_CUSTOMER' ? 'With Customer' : 'Not recorded' }}</p>
                <p v-if="job.device_location_updated_at" class="mt-1 text-xs text-slate-500">Updated {{ formatDateTime(job.device_location_updated_at) }} by {{ job.device_location_updated_by || 'a shop user' }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                  <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold hover:bg-slate-50" :disabled="job.device_location === 'AT_SHOP'" @click="updateDeviceLocation('AT_SHOP')">Record Returned to Shop</button>
                  <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold hover:bg-slate-50" :disabled="job.device_location === 'WITH_CUSTOMER'" @click="updateDeviceLocation('WITH_CUSTOMER')">Record Released to Customer</button>
                </div>
              </div>
              <div>
                <p class="text-slate-500">Device</p>
                <p class="font-semibold text-slate-900">{{ [job.brand, job.model].filter(Boolean).join(' ') || job.device_type }}</p>
              </div>
              <div>
                <p class="text-slate-500">Estimated Completion</p>
                <p class="font-semibold text-slate-900">{{ job.estimated_completion_formatted || 'Not set' }}</p>
              </div>
              <div class="md:col-span-2">
                <p class="text-slate-500">Problem</p>
                <p class="font-semibold text-slate-900">{{ job.problem_description }}</p>
              </div>
            </div>
          </div>

          <div class="card p-6">
            <h4 class="mb-4 text-lg font-semibold text-slate-900">Customer Repair Approval</h4>
            <p v-if="job.repair_approved_at" class="mb-4 text-sm text-emerald-700">
              Approved by {{ job.repair_approved_by_customer }} and recorded by {{ job.repair_approval_recorded_by }} on {{ job.repair_approved_at }}.
            </p>
            <form class="space-y-3" @submit.prevent="recordRepairApproval">
              <input v-model="approvalForm.repair_approved_by_customer" class="w-full rounded-lg border border-slate-300 px-4 py-2" placeholder="Customer/authorized person name" required />
              <textarea v-model="approvalForm.repair_approval_notes" rows="3" class="w-full rounded-lg border border-slate-300 px-4 py-2" placeholder="Approval method, approved estimate, or notes"></textarea>
              <button type="submit" class="w-full rounded-lg bg-teal-600 py-2 font-semibold text-white hover:bg-teal-700">Record Customer Approval</button>
            </form>
          </div>

          <div class="card p-6">
            <h4 class="mb-4 text-lg font-semibold text-slate-900">Timeline</h4>
            <div class="space-y-3">
              <div v-for="entry in timeline" :key="entry.id" class="border-b border-slate-100 pb-3 last:border-0 last:pb-0">
                <p class="font-semibold text-slate-900">{{ formatStatus(entry.status) }}</p>
                <p class="text-sm text-slate-500">{{ entry.created_at_formatted }}</p>
                <p v-if="entry.note" class="text-sm text-slate-700">{{ entry.note }}</p>
              </div>
              <p v-if="!timeline.length" class="text-sm text-slate-500">No status history available.</p>
            </div>
          </div>

          <div class="card p-6">
            <div class="mb-4 flex items-start justify-between gap-3">
              <div>
                <h4 class="text-lg font-semibold text-slate-900">Photos & Evidence</h4>
                <p class="text-sm text-slate-500">Upload repair photos from camera, gallery, or computer files.</p>
              </div>
              <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">{{ job.photos?.length || 0 }} files</span>
            </div>

            <div v-if="job.photos?.length" class="mb-5 grid grid-cols-1 gap-4 md:grid-cols-2">
              <div v-for="photo in job.photos" :key="photo.id" class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <a :href="photo.url" target="_blank" rel="noopener" class="block aspect-[4/3] bg-slate-100">
                  <img :src="photo.url" :alt="photo.photo_type_label" class="h-full w-full object-cover" />
                </a>
                <div class="space-y-2 p-3">
                  <div class="flex items-start justify-between gap-3">
                    <div>
                      <p class="text-sm font-semibold text-slate-900">{{ photo.photo_type_label }}</p>
                      <p class="text-xs text-slate-500">{{ photo.created_at_formatted }}</p>
                    </div>
                    <button type="button" class="text-sm font-semibold text-rose-600 hover:text-rose-700" @click="deletePhoto(photo)">Delete</button>
                  </div>
                  <p v-if="photo.description" class="text-sm text-slate-700">{{ photo.description }}</p>
                  <button
                    type="button"
                    class="rounded-full px-3 py-1 text-xs font-semibold"
                    :class="photo.visible_to_customer ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-1 ring-slate-200'"
                    @click="togglePhotoVisibility(photo)"
                  >
                    {{ photo.visible_to_customer ? 'Visible to customer' : 'Internal only' }}
                  </button>
                  <p class="text-xs text-slate-500">Uploaded by {{ photo.uploaded_by || 'Unknown' }}</p>
                </div>
              </div>
            </div>
            <p v-else class="mb-4 text-sm text-slate-500">No photos uploaded yet.</p>

            <form class="grid grid-cols-1 gap-3 md:grid-cols-4" @submit.prevent="uploadPhoto">
              <select v-model="photoForm.photo_type" class="rounded-lg border border-slate-300 px-4 py-2">
                <option value="BEFORE">Before</option>
                <option value="DURING">During</option>
                <option value="AFTER">After</option>
                <option value="EVIDENCE">Evidence</option>
              </select>
              <input type="file" accept="image/png,image/jpeg,image/webp" capture="environment" class="rounded-lg border border-slate-300 px-4 py-2 md:col-span-3" required @change="onPhotoSelected" />
              <input v-model="photoForm.description" class="rounded-lg border border-slate-300 px-4 py-2 md:col-span-3" placeholder="Photo note (optional)" />
              <button type="submit" class="rounded-lg bg-slate-900 py-2 font-semibold text-white hover:bg-slate-800 disabled:opacity-60" :disabled="uploadingPhoto || !selectedPhoto">
                {{ uploadingPhoto ? 'Uploading...' : 'Upload Photo' }}
              </button>
            </form>
          </div>

          <div class="card p-6">
            <h4 class="mb-4 text-lg font-semibold text-slate-900">Parts Used</h4>
            <div v-if="job.parts?.length" class="space-y-3">
              <div v-for="part in job.parts" :key="part.id" class="rounded-lg border border-slate-200 p-3">
                <p class="font-semibold text-slate-900">{{ part.part_name }}</p>
                <p class="text-sm text-slate-600">Qty {{ part.quantity }} x {{ formatCurrency(part.unit_cost) }}</p>
                <p class="text-sm font-semibold text-slate-800">{{ formatCurrency(part.total_cost) }}</p>
                <p v-if="part.notes" class="mt-1 text-sm text-slate-600">{{ part.notes }}</p>
                <button
                  v-if="job.can_record_parts && !part.job_order_part_order_id"
                  type="button"
                  class="mt-3 text-sm font-semibold text-rose-700 hover:text-rose-800 disabled:opacity-60"
                  :disabled="removingPartId === part.id"
                  @click="removePart(part)"
                >
                  {{ removingPartId === part.id ? 'Removing...' : 'Remove Part Entry' }}
                </button>
                <p v-else-if="part.job_order_part_order_id" class="mt-3 text-xs text-slate-500">This part is linked to a customer order and cannot be removed from the technician workspace.</p>
              </div>
            </div>
            <p v-else class="text-sm text-slate-500">No parts recorded yet.</p>
          </div>

          <div class="card p-6">
            <h4 class="mb-2 text-lg font-semibold text-slate-900">Parts &amp; Orders for This Repair</h4>
            <p class="mb-4 text-sm text-slate-600">Customer-specific orders require an authorized staff member to record the customer's decision and verify the required downpayment before ordering.</p>
            <div v-if="job.part_orders?.length" class="mb-5 space-y-3">
              <article v-for="partOrder in job.part_orders" :key="partOrder.id" class="rounded-lg border border-slate-200 p-3">
                <div class="flex flex-wrap justify-between gap-2">
                  <p class="font-semibold text-slate-900">{{ partOrder.part_name }} · Qty {{ partOrder.quantity }}</p>
                  <span class="text-xs font-bold capitalize text-slate-600">{{ partOrder.status.replaceAll('_', ' ') }}</span>
                </div>
                <p class="mt-1 text-sm text-slate-600">{{ partOrder.reason }}</p>
                <p class="mt-1 text-xs text-slate-500">Estimate {{ formatCurrency(partOrder.estimated_total) }} · Customer decision {{ partOrder.customer_decision }}</p>
                <p v-if="partOrder.required_downpayment_amount" class="mt-1 text-xs text-slate-500">Required downpayment {{ formatCurrency(partOrder.required_downpayment_amount) }}</p>
                <p v-if="partOrder.supplier_name" class="mt-1 text-xs text-slate-500">Supplier {{ partOrder.supplier_name }}<span v-if="partOrder.expected_arrival_date"> · Expected {{ partOrder.expected_arrival_date }}</span></p>
                <button
                  v-if="partOrder.status === 'received' && !partOrder.issued_at && job.device_location === 'AT_SHOP' && job.can_record_parts"
                  type="button"
                  class="mt-3 rounded-lg bg-teal-700 px-3 py-2 text-xs font-bold text-white hover:bg-teal-800"
                  @click="issueReceivedPartOrder(partOrder)"
                >Issue Received Part to Repair</button>
                <p v-else-if="partOrder.issued_at" class="mt-2 text-xs font-semibold text-emerald-700">Issued to repair; Job Order status remains independently controlled.</p>
                <p v-else-if="partOrder.status === 'received' && job.device_location === 'WITH_CUSTOMER'" class="mt-2 text-xs font-semibold text-amber-700">Part received. Ask the customer to return the device and record its location before issuing.</p>
              </article>
            </div>
            <form v-if="job.can_record_parts" class="grid grid-cols-1 gap-3 md:grid-cols-2" @submit.prevent="submitPartOrder">
              <input v-model="partOrderForm.part_name" class="rounded-lg border border-slate-300 px-4 py-2" placeholder="Required part" required maxlength="150" />
              <input v-model.number="partOrderForm.quantity" type="number" min="1" class="rounded-lg border border-slate-300 px-4 py-2" placeholder="Quantity" required />
              <input v-model.number="partOrderForm.estimated_unit_price" type="number" min="0.01" step="0.01" class="rounded-lg border border-slate-300 px-4 py-2" placeholder="Estimated unit price" required />
              <textarea v-model="partOrderForm.reason" rows="2" class="rounded-lg border border-slate-300 px-4 py-2" placeholder="Diagnosis / why the repair needs this part" required maxlength="2000"></textarea>
              <button type="submit" class="rounded-lg bg-teal-700 py-2 font-semibold text-white hover:bg-teal-800 md:col-span-2">Submit Customer Parts Order</button>
            </form>
          </div>

          <div class="card p-6">
            <h4 class="mb-4 text-lg font-semibold text-slate-900">Record Used Part</h4>
            <div v-if="job.can_record_parts">
              <form class="grid grid-cols-1 gap-3 md:grid-cols-3" @submit.prevent="addPart">
                <select v-model="partForm.spare_part_id" class="rounded-lg border border-slate-300 px-4 py-2 md:col-span-2" required>
                  <option value="">Select inventory part</option>
                  <option v-for="part in availableParts" :key="part.id" :value="part.id">
                    {{ part.name }} - Stock {{ part.stock_quantity }} - {{ formatCurrency(part.unit_cost) }}{{ part.is_low_stock ? ' - Low stock' : '' }}
                  </option>
                </select>
                <input v-model.number="partForm.quantity" type="number" min="1" class="rounded-lg border border-slate-300 px-4 py-2" placeholder="Quantity" required />
                <input v-model="partForm.notes" class="rounded-lg border border-slate-300 px-4 py-2 md:col-span-3" placeholder="Usage note (optional)" />
                <button type="submit" class="rounded-lg bg-slate-900 py-2 font-semibold text-white hover:bg-slate-800 md:col-span-3">
                  Record Part Usage
                </button>
              </form>
              <div class="mt-3 space-y-1 text-xs text-slate-500">
                <p>This uses the inventory cost automatically and deducts stock right away.</p>
                <p>If a wrong part is recorded by mistake, remove that entry from the list above and the stock will be restored automatically.</p>
                <p v-if="availableParts.some((part) => part.is_low_stock)" class="font-semibold text-amber-700">
                  Some available parts are already at low stock level.
                </p>
              </div>
            </div>
            <p v-else class="text-sm text-slate-500">Part recording is locked for this job because it is already finalized or ready for pickup.</p>
          </div>

          <div class="card p-6">
            <h4 class="mb-2 text-lg font-semibold text-slate-900">Internal Purchase / Restock Request</h4>
            <p class="mb-4 text-sm text-slate-600">This is a shop purchasing request only. It is not linked to this Job Order and does not change repair status.</p>
            <form class="grid grid-cols-1 gap-3 md:grid-cols-3" @submit.prevent="requestPart">
              <input v-model="requestForm.part_name" class="rounded-lg border border-slate-300 px-4 py-2 md:col-span-2" placeholder="Part name" required />
              <input v-model.number="requestForm.quantity" type="number" min="1" class="rounded-lg border border-slate-300 px-4 py-2" placeholder="Quantity" required />
              <input v-model="requestForm.note" class="rounded-lg border border-slate-300 px-4 py-2 md:col-span-3" placeholder="Restock / future repair note (optional)" />
              <button type="submit" class="rounded-lg bg-amber-600 py-2 font-semibold text-white hover:bg-amber-700 disabled:opacity-60 md:col-span-3" :disabled="submittingRequest">
                {{ submittingRequest ? 'Submitting...' : 'Submit Purchase Request' }}
              </button>
            </form>
            <div v-if="job.can_record_parts" class="mt-5 border-t border-slate-200 pt-4">
              <p class="text-sm font-semibold text-slate-800">Repair cannot proceed?</p>
              <p class="mt-1 text-xs text-slate-500">This separate, explicitly job-linked action records the unavailable part on the Job Order and marks it Waiting for Parts.</p>
              <button type="button" class="mt-3 rounded-lg border border-amber-300 px-4 py-2 text-sm font-bold text-amber-800 hover:bg-amber-50" @click="requestBlockedJobPart">
                Report Part Blocking This Job
              </button>
            </div>
          </div>
        </div>

        <div class="space-y-6">
          <div class="card p-6">
            <h4 class="mb-4 text-lg font-semibold text-slate-900">Update Repair Status</h4>
            <form class="space-y-3" @submit.prevent="submit">
              <select v-model="form.status" class="w-full rounded-lg border border-slate-300 px-4 py-2" required>
                <option v-for="status in allowedStatuses" :key="status" :value="status">{{ formatStatus(status) }}</option>
              </select>
              <textarea v-model="form.note" rows="3" class="w-full rounded-lg border border-slate-300 px-4 py-2" placeholder="Work note (optional)"></textarea>
              <button type="submit" class="w-full rounded-lg bg-slate-900 py-2 font-semibold text-white hover:bg-slate-800">Save Status</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </TechnicianLayout>
</template>
