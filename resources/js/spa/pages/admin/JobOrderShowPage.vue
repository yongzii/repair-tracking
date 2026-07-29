<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AppLink from '../../components/AppLink.vue';
import { adminRoles, authState, ensureRoles } from '../../composables/auth';
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
const jobOrder = ref(null);
const statusHistories = ref([]);
const parts = ref([]);
const inventoryParts = ref([]);
const payments = ref([]);
const photos = ref([]);
const notifications = ref([]);
const warrantyAssessments = ref([]);
const selectedPhoto = ref(null);
const uploadingPhoto = ref(false);
const issuingWarrantyService = ref(false);
const cancelingWarrantyService = ref(false);
const retryingNotificationId = ref(null);
const activeAction = ref('status');

const statusForm = ref({
    status: '',
    note: '',
});

const pickupForm = ref({
    picked_up_by: '',
    override_unpaid_balance: false,
    override_reason: '',
});

const costForm = ref({
    parts_cost: '',
    labor_cost: '',
});

const paymentForm = ref({
    amount_paid: '',
    payment_type: '',
    payment_method: 'CASH',
    reference_number: '',
    payment_notes: '',
});

const warrantyForm = ref({
    warranty_days: 0,
    warranty_details: '',
    service_performed: '',
});

const approvalForm = ref({
    repair_approved_by_customer: '',
    repair_approval_notes: '',
});

const photoForm = ref({
    photo_type: 'BEFORE',
    description: '',
    visible_to_customer: false,
});

const canOverridePickup = computed(() => adminRoles.includes(authState.user?.role));

const actionTabs = computed(() => {
    const tabs = [
        { key: 'status', label: 'Status' },
    ];

    if (jobOrder.value?.current_status === 'READY_FOR_PICKUP' && !jobOrder.value?.picked_up_at) {
        tabs.push({ key: 'release', label: 'Release' });
    }

    return [
        ...tabs,
        { key: 'payment', label: 'Payment' },
        { key: 'costs', label: 'Costs' },
        { key: 'approval', label: 'Approval' },
        { key: 'warranty', label: 'Warranty' },
        { key: 'assessment', label: 'Warranty Service' },
        { key: 'history', label: 'History' },
    ];
});

const partForm = ref({
    spare_part_id: '',
    part_name: '',
    quantity: 1,
    unit_cost: '',
    notes: '',
});

const balance = computed(() => {
    if (!jobOrder.value) return 0;
    return Number(jobOrder.value.total_cost || 0) - Number(jobOrder.value.amount_paid || 0);
});

const isWarrantyServiceJob = computed(() => jobOrder.value?.service_type === 'WARRANTY_SERVICE');

const canCancelWarrantyService = computed(() => {
    return isWarrantyServiceJob.value
        && jobOrder.value?.current_status === 'RECEIVED'
        && !jobOrder.value?.picked_up_at;
});

const activeWarrantyService = computed(() => warrantyAssessments.value.find((assessment) => {
    const warrantyJob = assessment.warranty_job_order;

    return warrantyJob
        && warrantyJob.current_status !== 'PICKED_UP'
        && warrantyJob.current_status !== 'UNREPAIRABLE';
}) || null);

const warrantyExpiresAt = computed(() => {
    if (!jobOrder.value?.picked_up_at || !jobOrder.value?.warranty_days) return null;
    const pickedUpAt = new Date(jobOrder.value.picked_up_at);

    if (Number.isNaN(pickedUpAt.getTime())) return null;

    pickedUpAt.setDate(pickedUpAt.getDate() + Number(jobOrder.value.warranty_days || 0));

    return pickedUpAt;
});

const warrantyServiceBlockReason = computed(() => {
    if (!jobOrder.value || isWarrantyServiceJob.value || activeWarrantyService.value) return '';

    if (jobOrder.value.current_status !== 'PICKED_UP' || !jobOrder.value.picked_up_at) {
        return 'Warranty service can only be issued after the original unit is released to the customer.';
    }

    if (jobOrder.value.payment_status !== 'PAID' || balance.value > 0) {
        return 'Warranty service can only be issued after the original job order is fully paid.';
    }

    if (Number(jobOrder.value.warranty_days || 0) <= 0) {
        return 'This job order has no warranty coverage recorded.';
    }

    if (warrantyExpiresAt.value && new Date() > warrantyExpiresAt.value) {
        return 'This job order warranty has already expired.';
    }

    return '';
});

const selectedSparePart = computed(() => {
    if (!partForm.value.spare_part_id) return null;
    return inventoryParts.value.find((item) => Number(item.id) === Number(partForm.value.spare_part_id)) || null;
});

const formatCurrency = (value) => {
    const amount = Number(value || 0);
    return `PHP ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

const formatDate = (value, withTime = false) => {
    if (!value) return 'N/A';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    const options = withTime
        ? { month: 'short', day: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }
        : { month: 'short', day: '2-digit', year: 'numeric' };
    return date.toLocaleString(undefined, options);
};

const notificationStatusClass = (status) => {
    if (status === 'sent') return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    if (status === 'failed') return 'bg-rose-50 text-rose-700 ring-rose-200';
    return 'bg-amber-50 text-amber-700 ring-amber-200';
};

const notificationStatusLabel = (notification) => notification.status_label || String(notification.status || '').toUpperCase();

const notificationStatusHelp = (notification) => {
    if (notification.status_help) return notification.status_help;
    if (notification.status === 'sent') return 'Delivered successfully.';
    if (notification.status === 'failed') return 'Delivery failed. Check provider settings and retry.';
    return 'Waiting for the queue worker to send.';
};

const formatStatus = (status) => {
    if (status === 'PICKED_UP') return 'RELEASED';
    if (status === 'UNREPAIRABLE') return 'RTO';
    return status ? status.replaceAll('_', ' ') : '';
};

const defaultApprovalName = () => {
    if (jobOrder.value?.repair_approved_at) {
        return jobOrder.value.repair_approved_by_customer || '';
    }

    return jobOrder.value?.customer_name || '';
};

const syncApprovalName = () => {
    if (!approvalForm.value.repair_approved_by_customer) {
        approvalForm.value.repair_approved_by_customer = defaultApprovalName();
    }
};

const loadJobOrder = async () => {
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
        loading.value = true;
        const [jobOrderResponse, inventoryResponse] = await Promise.all([
            window.axios.get(`/api/admin/job-orders/${props.params.id}`),
            window.axios.get('/api/admin/inventory', { params: { all: 1, active_only: 1 } }),
        ]);

        const data = jobOrderResponse.data;
        jobOrder.value = data.jobOrder;
        statusHistories.value = data.statusHistories || [];
        parts.value = data.parts || [];
        payments.value = data.payments || [];
        photos.value = data.photos || [];
        notifications.value = data.notifications || [];
        warrantyAssessments.value = data.warrantyAssessments || [];
        inventoryParts.value = inventoryResponse.data.items || [];
        costForm.value.parts_cost = jobOrder.value.parts_cost || 0;
        costForm.value.labor_cost = jobOrder.value.labor_cost || 0;
        warrantyForm.value.warranty_days = Number(jobOrder.value.warranty_days || 0);
        warrantyForm.value.warranty_details = jobOrder.value.warranty_details || '';
        warrantyForm.value.service_performed = jobOrder.value.service_performed || '';
        syncApprovalName();
    } catch (error) {
        forbidden.value = true;
        errorMessage.value = error?.response?.data?.message || 'Unable to load job order.';
    } finally {
        loading.value = false;
    }
};

const onPhotoSelected = (event) => {
    selectedPhoto.value = event.target.files?.[0] || null;
};

const uploadPhoto = async () => {
    if (!selectedPhoto.value) return;
    successMessage.value = '';
    errorMessage.value = '';
    uploadingPhoto.value = true;

    const payload = new FormData();
    payload.append('photo', selectedPhoto.value);
    payload.append('photo_type', photoForm.value.photo_type);
    payload.append('description', photoForm.value.description || '');
    payload.append('visible_to_customer', photoForm.value.visible_to_customer ? '1' : '0');

    try {
        const { data } = await window.axios.post(`/api/admin/job-orders/${props.params.id}/photos`, payload, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        successMessage.value = data.message || 'Photo uploaded.';
        selectedPhoto.value = null;
        photoForm.value = { photo_type: 'BEFORE', description: '', visible_to_customer: false };
        await loadJobOrder();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Failed to upload photo.';
    } finally {
        uploadingPhoto.value = false;
    }
};

const togglePhotoVisibility = async (photo) => {
    successMessage.value = '';
    errorMessage.value = '';
    try {
        const { data } = await window.axios.post(`/api/admin/job-orders/${props.params.id}/photos/${photo.id}/visibility`, {
            visible_to_customer: !photo.visible_to_customer,
        });
        successMessage.value = data.message || 'Photo visibility updated.';
        await loadJobOrder();
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Failed to update photo visibility.';
    }
};

const deletePhoto = async (photo) => {
    if (!window.confirm('Delete this job order photo?')) return;
    successMessage.value = '';
    errorMessage.value = '';
    try {
        const { data } = await window.axios.delete(`/api/admin/job-orders/${props.params.id}/photos/${photo.id}`);
        successMessage.value = data.message || 'Photo deleted.';
        await loadJobOrder();
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Failed to delete photo.';
    }
};

const updateStatus = async () => {
    if (!statusForm.value.status) return;
    successMessage.value = '';
    errorMessage.value = '';

    try {
        const { data } = await window.axios.post(
            `/api/admin/job-orders/${props.params.id}/update-status`,
            statusForm.value
        );
        successMessage.value = data.message || 'Status updated.';
        await loadJobOrder();
        statusForm.value.status = '';
        statusForm.value.note = '';
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Failed to update status.';
    }
};

const markPickedUp = async () => {
    if (!pickupForm.value.picked_up_by.trim()) return;
    successMessage.value = '';
    errorMessage.value = '';

    try {
        const { data } = await window.axios.post(
            `/api/admin/job-orders/${props.params.id}/mark-picked-up`,
            pickupForm.value
        );
        successMessage.value = data.message || 'Device released.';
        await loadJobOrder();
        pickupForm.value.picked_up_by = '';
        pickupForm.value.override_unpaid_balance = false;
        pickupForm.value.override_reason = '';
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Failed to release device.';
    }
};

const recordRepairApproval = async () => {
    successMessage.value = '';
    errorMessage.value = '';
    try {
        const { data } = await window.axios.post(`/api/admin/job-orders/${props.params.id}/record-repair-approval`, approvalForm.value);
        successMessage.value = data.message || 'Customer repair approval recorded.';
        approvalForm.value.repair_approval_notes = '';
        await loadJobOrder();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Failed to record customer approval.';
    }
};

const updateCosts = async () => {
    successMessage.value = '';
    errorMessage.value = '';
    try {
        const { data } = await window.axios.post(`/api/admin/job-orders/${props.params.id}/update-costs`, costForm.value);
        successMessage.value = data.message || 'Costs updated.';
        await loadJobOrder();
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Failed to update costs.';
    }
};

const recordPayment = async () => {
    successMessage.value = '';
    errorMessage.value = '';
    try {
        const { data } = await window.axios.post(`/api/admin/job-orders/${props.params.id}/record-payment`, paymentForm.value);
        successMessage.value = data.message || 'Payment recorded.';
        paymentForm.value.amount_paid = '';
        paymentForm.value.payment_type = '';
        paymentForm.value.payment_method = 'CASH';
        paymentForm.value.reference_number = '';
        paymentForm.value.payment_notes = '';
        await loadJobOrder();
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Failed to record payment.';
    }
};

const updateWarranty = async () => {
    successMessage.value = '';
    errorMessage.value = '';
    try {
        const { data } = await window.axios.post(`/api/admin/job-orders/${props.params.id}/update-warranty`, warrantyForm.value);
        successMessage.value = data.message || 'Warranty details updated.';
        await loadJobOrder();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Failed to update warranty details.';
    }
};

const issueWarrantyService = async () => {
    successMessage.value = '';
    errorMessage.value = '';
    issuingWarrantyService.value = true;

    try {
        const { data } = await window.axios.post(`/api/admin/job-orders/${props.params.id}/issue-warranty-service`);
        successMessage.value = data.message || 'Warranty service job created.';
        await loadJobOrder();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Failed to issue warranty service.';
    } finally {
        issuingWarrantyService.value = false;
    }
};

const cancelWarrantyService = async () => {
    successMessage.value = '';
    errorMessage.value = '';
    cancelingWarrantyService.value = true;

    try {
        const { data } = await window.axios.post(`/api/admin/job-orders/${props.params.id}/cancel-warranty-service`);
        successMessage.value = data.message || 'Warranty service canceled.';

        if (data.parentJobOrder?.id) {
            navigate(`/admin/job-orders/${data.parentJobOrder.id}`);
            return;
        }

        navigate('/admin/job-orders');
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Failed to cancel warranty service.';
    } finally {
        cancelingWarrantyService.value = false;
    }
};

const retryNotification = async (notification) => {
    if (retryingNotificationId.value) return;

    successMessage.value = '';
    errorMessage.value = '';
    retryingNotificationId.value = notification.id;

    try {
        const { data } = await window.axios.post(`/api/admin/job-orders/${props.params.id}/notifications/${notification.id}/retry`);
        successMessage.value = data.message || 'Notification queued for retry.';
        await loadJobOrder();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Failed to retry notification.';
    } finally {
        retryingNotificationId.value = null;
    }
};

const addPart = async () => {
    successMessage.value = '';
    errorMessage.value = '';
    try {
        const payload = {
            ...partForm.value,
            spare_part_id: partForm.value.spare_part_id || null,
        };
        const { data } = await window.axios.post(`/api/admin/job-orders/${props.params.id}/parts`, payload);
        successMessage.value = data.message || 'Part added.';
        partForm.value = { spare_part_id: '', part_name: '', quantity: 1, unit_cost: '', notes: '' };
        await loadJobOrder();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Failed to add part.';
    }
};

const onSparePartChange = () => {
    if (!selectedSparePart.value) return;
    partForm.value.part_name = selectedSparePart.value.name;
    partForm.value.unit_cost = selectedSparePart.value.unit_cost;
};

const deletePart = async (partId) => {
    if (!window.confirm('Delete this part?')) return;
    successMessage.value = '';
    errorMessage.value = '';
    try {
        const { data } = await window.axios.delete(`/api/admin/job-orders/${props.params.id}/parts/${partId}`);
        successMessage.value = data.message || 'Part deleted.';
        await loadJobOrder();
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Failed to delete part.';
    }
};

onMounted(loadJobOrder);
</script>

<template>
  <AdminLayout :title="jobOrder ? jobOrder.job_order_number : 'Job Order'">
    <div v-if="loading" class="card p-6 text-slate-600">Loading job order...</div>
    <div v-else-if="forbidden" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">{{ errorMessage || 'You do not have access to this page.' }}</div>

    <div v-else-if="jobOrder" class="space-y-6">
      <div v-if="successMessage" class="card p-4 border border-emerald-200 bg-emerald-50 text-emerald-900">{{ successMessage }}</div>
      <div v-if="errorMessage" class="card p-4 border border-rose-200 bg-rose-50 text-rose-900">{{ errorMessage }}</div>

      <div class="flex items-center justify-between gap-3">
        <div>
          <div class="flex flex-wrap items-center gap-2">
            <h3 class="text-2xl font-bold text-slate-900">{{ jobOrder.job_order_number }}</h3>
            <span v-if="jobOrder.service_type === 'WARRANTY_SERVICE'" class="inline-flex rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold uppercase text-emerald-700 ring-1 ring-emerald-200">
              Under Warranty
            </span>
          </div>
          <p class="text-slate-600">{{ jobOrder.customer_name }} - {{ jobOrder.device_type }}</p>
        </div>
        <div class="flex items-center gap-3">
          <AppLink :to="`/admin/job-orders/${jobOrder.id}/print/intake`" class="text-teal-700 hover:text-teal-800 font-semibold">Claim Stab / Job Slip</AppLink>
          <AppLink :to="`/admin/job-orders/${jobOrder.id}/print/warranty`" class="text-emerald-700 hover:text-emerald-800 font-semibold">Warranty</AppLink>
          <AppLink :to="`/admin/job-orders/${jobOrder.id}/invoice`" class="text-indigo-700 hover:text-indigo-800 font-semibold">{{ jobOrder.service_type === 'WARRANTY_SERVICE' ? 'Service Summary' : 'Invoice' }}</AppLink>
          <AppLink :to="`/admin/job-orders/${jobOrder.id}/edit`" class="text-slate-900 hover:text-slate-700 font-semibold">Edit</AppLink>
          <AppLink to="/admin/job-orders" class="text-slate-700 hover:text-slate-900 font-semibold">Back to Job Orders</AppLink>
        </div>
      </div>

      <div v-if="jobOrder.service_type === 'WARRANTY_SERVICE'" class="card border border-emerald-200 bg-emerald-50 p-4 text-emerald-900">
        <p class="font-bold">Under Warranty Service</p>
        <p class="mt-1 text-sm">
          This job order was created from a covered warranty assessment. Charges should remain waived unless the shop records an approved exception.
        </p>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
          <div class="card p-6">
            <h4 class="text-lg font-semibold text-slate-900 mb-4">Details</h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
              <div>
                <p class="text-slate-500">Status</p>
                <p class="font-semibold text-slate-900">{{ formatStatus(jobOrder.current_status) }}</p>
              </div>
              <div>
                <p class="text-slate-500">Priority</p>
                <p class="font-semibold text-slate-900">{{ jobOrder.priority }}</p>
              </div>
              <div>
                <p class="text-slate-500">Received</p>
                <p class="font-semibold text-slate-900">{{ formatDate(jobOrder.created_at) }}</p>
              </div>
              <div>
                <p class="text-slate-500">Payment Status</p>
                <p class="font-semibold text-slate-900">{{ (jobOrder.payment_status || 'PENDING').replaceAll('_', ' ') }}</p>
              </div>
              <div>
                <p class="text-slate-500">Amount Paid</p>
                <p class="font-semibold text-slate-900">{{ formatCurrency(jobOrder.amount_paid) }}</p>
              </div>
              <div>
                <p class="text-slate-500">Balance</p>
                <p class="font-semibold text-slate-900">{{ formatCurrency(balance) }}</p>
              </div>
              <div class="md:col-span-2">
                <p class="text-slate-500">Problem</p>
                <p class="font-semibold text-slate-900">{{ jobOrder.problem_description }}</p>
              </div>
              <div>
                <p class="text-slate-500">Included Accessories</p>
                <p class="font-semibold text-slate-900 whitespace-pre-line">{{ jobOrder.included_accessories || 'None recorded' }}</p>
              </div>
              <div class="md:col-span-2">
                <p class="text-slate-500">Repair Approval</p>
                <p v-if="jobOrder.repair_approved_at" class="font-semibold text-emerald-700">
                  Approved by {{ jobOrder.repair_approved_by_customer }} - Recorded by {{ jobOrder.repair_approval_recorded_by }} - {{ formatDate(jobOrder.repair_approved_at, true) }}
                </p>
                <p v-else class="font-semibold text-amber-700">Customer approval not yet recorded</p>
                <p v-if="jobOrder.repair_approval_notes" class="mt-1 text-slate-700">{{ jobOrder.repair_approval_notes }}</p>
              </div>
            </div>
          </div>

          <div v-if="jobOrder.picked_up_at" class="card border border-emerald-200 bg-emerald-50 p-6 text-emerald-950">
            <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
              <div>
                <h4 class="text-lg font-semibold">Release Record</h4>
                <p class="mt-1 text-sm text-emerald-800">This unit has been released to the customer or authorized claimant.</p>
              </div>
              <span class="rounded-full bg-white px-3 py-1 text-xs font-bold uppercase text-emerald-700 ring-1 ring-emerald-200">RELEASED</span>
            </div>
            <div class="grid grid-cols-1 gap-4 text-sm md:grid-cols-2">
              <div>
                <p class="text-emerald-700">Released to</p>
                <p class="font-semibold">{{ jobOrder.picked_up_by || 'Not recorded' }}</p>
              </div>
              <div>
                <p class="text-emerald-700">Released date</p>
                <p class="font-semibold">{{ formatDate(jobOrder.picked_up_at, true) }}</p>
              </div>
              <div v-if="jobOrder.pickup_override_by">
                <p class="text-emerald-700">Override approved by</p>
                <p class="font-semibold">{{ jobOrder.pickup_override_by }}</p>
              </div>
              <div v-if="jobOrder.pickup_override_reason" class="md:col-span-2">
                <p class="text-emerald-700">Override reason</p>
                <p class="font-semibold">{{ jobOrder.pickup_override_reason }}</p>
              </div>
            </div>
          </div>

          <div class="card p-6">
            <h4 class="text-lg font-semibold text-slate-900 mb-4">Timeline</h4>
            <div class="space-y-3">
              <div v-for="entry in statusHistories" :key="entry.id || entry.created_at" class="border-b border-slate-100 pb-3 last:border-0 last:pb-0">
                <p class="font-semibold text-slate-900">{{ formatStatus(entry.status) }}</p>
                <p class="text-sm text-slate-500">{{ formatDate(entry.created_at, true) }}</p>
                <p v-if="entry.note" class="text-sm text-slate-700">{{ entry.note }}</p>
              </div>
              <p v-if="!statusHistories.length" class="text-sm text-slate-500">No history yet.</p>
            </div>
          </div>

          <div class="card p-6">
            <div class="mb-4 flex items-start justify-between gap-3">
              <div>
                <h4 class="text-lg font-semibold text-slate-900">Photos & Evidence</h4>
                <p class="text-sm text-slate-500">Attach before, during, after, or proof photos for this repair.</p>
              </div>
              <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">{{ photos.length }} files</span>
            </div>

            <div v-if="photos.length" class="mb-5 grid grid-cols-1 gap-4 md:grid-cols-2">
              <div v-for="photo in photos" :key="photo.id" class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <a :href="photo.url" target="_blank" rel="noopener" class="block aspect-[4/3] bg-slate-100">
                  <img :src="photo.url" :alt="photo.photo_type_label" class="h-full w-full object-cover" />
                </a>
                <div class="space-y-2 p-3">
                  <div class="flex items-start justify-between gap-3">
                    <div>
                      <p class="text-sm font-semibold text-slate-900">{{ photo.photo_type_label }}</p>
                      <p class="text-xs text-slate-500">{{ photo.created_at_formatted || formatDate(photo.created_at, true) }}</p>
                    </div>
                    <button type="button" class="text-sm font-semibold text-rose-600 hover:text-rose-700" @click="deletePhoto(photo)">Delete</button>
                  </div>
                  <button
                    type="button"
                    class="rounded-full px-3 py-1 text-xs font-semibold"
                    :class="photo.visible_to_customer ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-1 ring-slate-200'"
                    @click="togglePhotoVisibility(photo)"
                  >
                    {{ photo.visible_to_customer ? 'Visible to customer' : 'Internal only' }}
                  </button>
                  <p v-if="photo.description" class="text-sm text-slate-700">{{ photo.description }}</p>
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
              <input type="file" accept="image/png,image/jpeg,image/webp" class="rounded-lg border border-slate-300 px-4 py-2 md:col-span-3" required @change="onPhotoSelected" />
              <input v-model="photoForm.description" class="rounded-lg border border-slate-300 px-4 py-2 md:col-span-3" placeholder="Photo note (optional)" />
              <label class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700">
                <input v-model="photoForm.visible_to_customer" type="checkbox" class="rounded border-slate-300" />
                Customer visible
              </label>
              <button type="submit" class="rounded-lg bg-slate-900 py-2 font-semibold text-white hover:bg-slate-800 disabled:opacity-60" :disabled="uploadingPhoto || !selectedPhoto">
                {{ uploadingPhoto ? 'Uploading...' : 'Upload Photo' }}
              </button>
            </form>
          </div>

          <div class="card p-6">
            <h4 class="text-lg font-semibold text-slate-900 mb-4">Parts</h4>
            <div v-if="parts.length" class="space-y-3 mb-4">
              <div v-for="part in parts" :key="part.id" class="flex items-center justify-between border border-slate-200 rounded-lg px-3 py-2">
                <div>
                  <p class="font-semibold text-slate-900">{{ part.part_name }}</p>
                  <p v-if="part.spare_part" class="text-xs text-slate-500">
                    From inventory · Remaining: {{ part.spare_part.stock_quantity }} {{ part.spare_part.unit || 'pcs' }}
                  </p>
                  <p class="text-sm text-slate-600">Qty {{ part.quantity }} x {{ formatCurrency(part.unit_cost) }}</p>
                </div>
                <div class="flex items-center gap-3">
                  <span class="text-sm font-semibold text-slate-800">{{ formatCurrency(part.total_cost) }}</span>
                  <button type="button" class="text-rose-600 font-semibold" @click="deletePart(part.id)">Delete</button>
                </div>
              </div>
            </div>
            <p v-else class="text-sm text-slate-500 mb-4">No parts recorded yet.</p>

            <form class="grid grid-cols-1 md:grid-cols-4 gap-3" @submit.prevent="addPart">
              <select v-model="partForm.spare_part_id" class="md:col-span-2 px-4 py-2 border border-slate-300 rounded-lg" @change="onSparePartChange">
                <option value="">Custom part (no inventory deduction)</option>
                <option
                  v-for="item in inventoryParts"
                  :key="item.id"
                  :value="String(item.id)"
                  :disabled="item.stock_quantity <= 0"
                >
                  {{ item.name }} · Stock {{ item.stock_quantity }} · {{ formatCurrency(item.unit_cost) }}
                </option>
              </select>
              <input v-model.number="partForm.quantity" type="number" min="1" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Qty" required />
              <input v-model="partForm.unit_cost" type="number" min="0" step="0.01" class="px-4 py-2 border border-slate-300 rounded-lg" placeholder="Unit cost (auto from inventory)" :readonly="Boolean(selectedSparePart)" required />
              <input v-model="partForm.part_name" class="md:col-span-4 px-4 py-2 border border-slate-300 rounded-lg" placeholder="Part name (required for custom part)" :readonly="Boolean(selectedSparePart)" :required="!selectedSparePart" />
              <input v-model="partForm.notes" class="md:col-span-3 px-4 py-2 border border-slate-300 rounded-lg" placeholder="Notes (optional)" />
              <button type="submit" class="md:col-span-1 bg-slate-900 hover:bg-slate-800 text-white font-semibold py-2 rounded-lg">Add Part</button>
            </form>
          </div>

        </div>

        <div class="space-y-6">
          <div class="card p-5">
            <div class="mb-4 flex items-center justify-between gap-3">
              <div>
                <h4 class="text-lg font-semibold text-slate-900">Actions</h4>
                <p class="text-sm text-slate-500">Choose one task to keep this page compact.</p>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-2">
              <button
                v-for="tab in actionTabs"
                :key="tab.key"
                type="button"
                class="rounded-lg px-3 py-2 text-sm font-bold transition"
                :class="activeAction === tab.key ? 'bg-slate-950 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
                @click="activeAction = tab.key"
              >
                {{ tab.label }}
              </button>
            </div>
          </div>

          <div class="card p-5">
            <h4 class="text-lg font-semibold text-slate-900 mb-3">Snapshot</h4>
            <div class="grid grid-cols-2 gap-3 text-sm">
              <div class="rounded-lg bg-slate-50 p-3">
                <p class="text-xs font-bold uppercase text-slate-400">Parts</p>
                <p class="mt-1 font-bold text-slate-900">{{ parts.length }}</p>
              </div>
              <div class="rounded-lg bg-slate-50 p-3">
                <p class="text-xs font-bold uppercase text-slate-400">Photos</p>
                <p class="mt-1 font-bold text-slate-900">{{ photos.length }}</p>
              </div>
              <div class="rounded-lg bg-slate-50 p-3">
                <p class="text-xs font-bold uppercase text-slate-400">Payments</p>
                <p class="mt-1 font-bold text-slate-900">{{ payments.length }}</p>
              </div>
              <div class="rounded-lg bg-slate-50 p-3">
                <p class="text-xs font-bold uppercase text-slate-400">Notices</p>
                <p class="mt-1 font-bold text-slate-900">{{ notifications.length }}</p>
              </div>
            </div>
          </div>

          <div v-if="activeAction === 'status'" class="card p-6">
            <h4 class="text-lg font-semibold text-slate-900 mb-4">Update Status</h4>
            <form class="space-y-3" @submit.prevent="updateStatus">
              <select v-model="statusForm.status" class="w-full px-4 py-2 border border-slate-300 rounded-lg" required>
                <option value="">Select status</option>
                <option value="RECEIVED">Received</option>
                <option value="DIAGNOSING">Diagnosing</option>
                <option value="WAITING_PARTS">Waiting for Parts</option>
                <option value="IN_PROGRESS">Repair in Progress</option>
                <option value="READY_FOR_PICKUP">Ready for Pickup</option>
                <option value="UNREPAIRABLE">RTO (Return to Owner)</option>
              </select>
              <textarea v-model="statusForm.note" rows="3" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Note (optional)"></textarea>
              <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white font-semibold py-2 rounded-lg">Save Status</button>
            </form>
          </div>

          <div class="card p-6" v-if="activeAction === 'release' && jobOrder.current_status === 'READY_FOR_PICKUP' && !jobOrder.picked_up_at">
            <h4 class="text-lg font-semibold text-slate-900 mb-4">Release Unit</h4>
            <div class="mb-4 grid grid-cols-1 gap-3 text-sm">
              <div class="rounded-lg border p-3" :class="balance > 0 ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-emerald-200 bg-emerald-50 text-emerald-900'">
                <p class="font-bold">{{ balance > 0 ? 'Balance required before release' : 'Ready for release' }}</p>
                <p class="mt-1">Total: {{ formatCurrency(jobOrder.total_cost) }} · Paid: {{ formatCurrency(jobOrder.amount_paid) }} · Balance: {{ formatCurrency(balance) }}</p>
              </div>
            </div>
            <form class="space-y-3" @submit.prevent="markPickedUp">
              <input v-model="pickupForm.picked_up_by" type="text" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Released to / claimed by" required />
              <div v-if="balance > 0" class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                Remaining balance: {{ formatCurrency(balance) }}. Release is blocked unless an admin records an override.
              </div>
              <label v-if="balance > 0 && canOverridePickup" class="flex items-center gap-2 text-sm font-semibold text-slate-700">
                <input v-model="pickupForm.override_unpaid_balance" type="checkbox" class="rounded border-slate-300" />
                Approve unpaid-balance release override
              </label>
              <textarea
                v-if="balance > 0 && canOverridePickup && pickupForm.override_unpaid_balance"
                v-model="pickupForm.override_reason"
                rows="3"
                class="w-full px-4 py-2 border border-slate-300 rounded-lg"
                placeholder="Required override reason"
                required
              ></textarea>
              <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2 rounded-lg">Confirm Release</button>
            </form>
          </div>

          <div v-if="activeAction === 'costs'" class="card p-6">
            <h4 class="text-lg font-semibold text-slate-900 mb-4">Update Costs</h4>
            <form class="space-y-3" @submit.prevent="updateCosts">
              <input v-model="costForm.parts_cost" type="number" min="0" step="0.01" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Parts cost" required />
              <input v-model="costForm.labor_cost" type="number" min="0" step="0.01" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Labor cost" required />
              <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white font-semibold py-2 rounded-lg">Save Costs</button>
            </form>
          </div>

          <div v-if="activeAction === 'approval'" class="card p-6">
            <h4 class="text-lg font-semibold text-slate-900 mb-4">Customer Repair Approval</h4>
            <form class="space-y-3" @submit.prevent="recordRepairApproval">
              <input v-model="approvalForm.repair_approved_by_customer" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Customer/authorized person name" required />
              <textarea v-model="approvalForm.repair_approval_notes" rows="3" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Approval method, approved estimate, or notes"></textarea>
              <button type="submit" class="w-full bg-teal-600 hover:bg-teal-700 text-white font-semibold py-2 rounded-lg">Record Approval</button>
            </form>
          </div>

          <div v-if="activeAction === 'warranty'" class="card p-6">
            <h4 class="text-lg font-semibold text-slate-900 mb-4">Warranty</h4>
            <form class="space-y-3" @submit.prevent="updateWarranty">
              <input v-model.number="warrantyForm.warranty_days" type="number" min="0" max="3650" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Warranty days" required />
              <textarea v-model="warrantyForm.service_performed" rows="3" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Service performed"></textarea>
              <textarea v-model="warrantyForm.warranty_details" rows="3" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Warranty coverage details"></textarea>
              <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2 rounded-lg">Save Warranty</button>
            </form>
            <p class="mt-3 text-xs text-slate-500">Set to 0 if no warranty applies. Print the warranty certificate only after coverage is confirmed.</p>
          </div>

          <div v-if="activeAction === 'assessment'" class="card p-6">
            <h4 class="text-lg font-semibold text-slate-900 mb-2">Warranty Service</h4>
            <p class="mb-4 text-sm text-slate-500">
              Use this only after the unit is checked in the shop and approved for warranty service.
            </p>

            <div v-if="warrantyAssessments.length" class="mb-4 space-y-3">
              <div v-for="assessment in warrantyAssessments" :key="assessment.id" class="rounded-lg border border-slate-200 p-3">
                <div class="flex items-start justify-between gap-3">
                  <div>
                    <p class="font-semibold text-slate-900">
                      {{ assessment.warranty_job_order ? 'Warranty service issued' : assessment.result_label }}
                    </p>
                    <p class="text-xs text-slate-500">
                      {{ assessment.brought_at_formatted || assessment.created_at_formatted }}
                      <span v-if="assessment.checked_by"> - Checked by {{ assessment.checked_by }}</span>
                    </p>
                  </div>
                  <AppLink
                    v-if="assessment.warranty_job_order"
                    :to="`/admin/job-orders/${assessment.warranty_job_order.id}`"
                    class="text-xs font-bold text-teal-700 hover:text-teal-800"
                  >
                    {{ assessment.warranty_job_order.job_order_number }}
                  </AppLink>
                </div>
                <p class="mt-2 text-sm text-slate-700">{{ assessment.reported_issue }}</p>
                <p v-if="assessment.assessment_notes" class="mt-1 text-sm text-slate-600">{{ assessment.assessment_notes }}</p>
                <p v-if="assessment.created_by" class="mt-2 text-xs text-slate-500">Recorded by {{ assessment.created_by }}</p>
              </div>
            </div>
            <p v-else class="mb-4 text-sm text-slate-500">No warranty service issued yet.</p>

            <div v-if="isWarrantyServiceJob" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
              <p>This is already an under warranty service job. Continue updating its status like a normal repair.</p>
              <button
                v-if="canCancelWarrantyService"
                type="button"
                class="mt-3 w-full rounded-lg bg-rose-600 py-2 font-semibold text-white hover:bg-rose-700 disabled:bg-slate-400"
                :disabled="cancelingWarrantyService"
                @click="cancelWarrantyService"
              >
                {{ cancelingWarrantyService ? 'Canceling...' : 'Cancel Warranty Service' }}
              </button>
              <p v-else class="mt-3 text-xs">
                Cancellation is only available while this warranty service is newly received.
              </p>
            </div>
            <div v-else-if="activeWarrantyService" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
              Active warranty service already exists:
              <AppLink
                :to="`/admin/job-orders/${activeWarrantyService.warranty_job_order.id}`"
                class="font-bold underline"
              >
                {{ activeWarrantyService.warranty_job_order.job_order_number }}
              </AppLink>
            </div>
            <div v-else-if="warrantyServiceBlockReason" class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
              {{ warrantyServiceBlockReason }}
            </div>
            <form v-else class="space-y-3" @submit.prevent="issueWarrantyService">
              <button
                type="submit"
                class="w-full bg-emerald-600 hover:bg-emerald-700 disabled:bg-slate-400 text-white font-semibold py-2 rounded-lg"
                :disabled="issuingWarrantyService"
              >
                {{ issuingWarrantyService ? 'Creating Warranty Service...' : 'Issue Warranty Service' }}
              </button>
              <p class="text-xs text-slate-500">
                Creates a new under warranty job order with zero charge so the customer can track the unit again.
              </p>
            </form>
          </div>

          <div v-if="activeAction === 'payment'" class="card p-6">
            <h4 class="text-lg font-semibold text-slate-900 mb-4">Record Payment</h4>
            <form class="space-y-3" @submit.prevent="recordPayment">
              <input v-model="paymentForm.amount_paid" type="number" min="0.01" step="0.01" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Amount paid" required />
              <select v-model="paymentForm.payment_type" class="w-full px-4 py-2 border border-slate-300 rounded-lg">
                <option value="">Auto detect type</option>
                <option value="PARTIAL_PAYMENT">Partial Payment</option>
                <option value="FINAL_PAYMENT">Final Payment</option>
              </select>
              <select v-model="paymentForm.payment_method" class="w-full px-4 py-2 border border-slate-300 rounded-lg">
                <option value="CASH">Cash</option>
                <option value="GCASH">GCash</option>
                <option value="BANK_TRANSFER">Bank Transfer</option>
                <option value="CARD">Card</option>
              </select>
              <input v-model="paymentForm.reference_number" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Reference number (optional)" />
              <textarea v-model="paymentForm.payment_notes" rows="2" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="Payment notes"></textarea>
              <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2 rounded-lg">Record</button>
            </form>
          </div>

          <div v-if="activeAction === 'history'" class="card p-6">
            <h4 class="text-lg font-semibold text-slate-900 mb-4">Payment History</h4>
            <div v-if="payments.length" class="space-y-3">
              <div v-for="payment in payments" :key="payment.id" class="border border-slate-200 rounded-lg p-3">
                <div class="flex items-start justify-between gap-3">
                  <div>
                    <p class="font-semibold text-slate-900">{{ formatCurrency(payment.amount) }}</p>
                    <p class="text-xs text-slate-500">{{ (payment.payment_type || '').replaceAll('_', ' ') }}</p>
                  </div>
                  <p class="text-xs text-slate-500 text-right">{{ payment.received_at_formatted || formatDate(payment.received_at, true) }}</p>
                </div>
                <p class="text-sm text-slate-700 mt-1">
                  {{ payment.payment_method || 'N/A' }}
                  <span v-if="payment.reference_number"> · Ref: {{ payment.reference_number }}</span>
                </p>
                <p v-if="payment.notes" class="text-sm text-slate-600 mt-1">{{ payment.notes }}</p>
                <p v-if="payment.received_by" class="text-xs text-slate-500 mt-1">Received by {{ payment.received_by }}</p>
              </div>
            </div>
            <p v-else class="text-sm text-slate-500">No payment records yet.</p>
          </div>

          <div v-if="activeAction === 'history'" class="card p-6">
            <h4 class="mb-4 text-lg font-semibold text-slate-900">Notification History</h4>
            <div v-if="notifications.length" class="space-y-3">
              <div v-for="notification in notifications" :key="notification.id" class="rounded-lg border border-slate-200 p-3">
                <div class="flex items-start justify-between gap-3">
                  <div>
                    <p class="font-semibold text-slate-900">{{ notification.channel_label }} to {{ notification.recipient }}</p>
                    <p class="text-xs text-slate-500">{{ notification.trigger || 'Customer update' }}</p>
                  </div>
                  <span class="rounded-full px-2 py-1 text-xs font-bold ring-1" :class="notificationStatusClass(notification.status)">
                    {{ notificationStatusLabel(notification) }}
                  </span>
                </div>
                <p v-if="notification.subject" class="mt-2 text-sm font-semibold text-slate-800">{{ notification.subject }}</p>
                <p class="mt-1 text-sm text-slate-600">{{ notification.message_preview }}</p>
                <p class="mt-2 rounded border p-2 text-xs" :class="notification.status === 'failed' ? 'border-rose-200 bg-rose-50 text-rose-700' : notification.status === 'sent' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-amber-200 bg-amber-50 text-amber-700'">
                  {{ notificationStatusHelp(notification) }}
                </p>
                <p class="mt-2 text-xs text-slate-500">
                  Queued {{ notification.queued_at_formatted || notification.created_at_formatted }}
                  <span v-if="notification.sent_at_formatted"> - Sent {{ notification.sent_at_formatted }}</span>
                  <span v-if="notification.failed_at_formatted"> - Failed {{ notification.failed_at_formatted }}</span>
                </p>
                <p v-if="notification.error_message" class="mt-2 rounded border border-rose-200 bg-rose-50 p-2 text-xs text-rose-700">
                  {{ notification.error_message }}
                </p>
                <button
                  v-if="notification.can_retry"
                  type="button"
                  class="mt-3 rounded-lg border border-rose-200 px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-50 disabled:opacity-60"
                  :disabled="retryingNotificationId === notification.id"
                  @click="retryNotification(notification)"
                >
                  {{ retryingNotificationId === notification.id ? 'Retrying...' : 'Retry Notification' }}
                </button>
              </div>
            </div>
            <p v-else class="text-sm text-slate-500">No customer notifications recorded yet.</p>
          </div>

        </div>
      </div>
    </div>
  </AdminLayout>
</template>
