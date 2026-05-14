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

const form = ref({
    status: '',
    note: '',
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

    try {
        const { data } = await window.axios.post(`/api/technician/job-orders/${props.params.id}/request-part`, {
            part_name: requestForm.value.part_name,
            quantity: requestForm.value.quantity,
            note: requestForm.value.note || null,
        });

        successMessage.value = data.message || 'Parts request recorded successfully.';
        await loadJob();
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to record parts request.';
    }
};

const formatCurrency = (value) => {
    const amount = Number(value || 0);
    return `PHP ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
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

      <div class="flex items-center justify-between">
        <div>
          <h3 class="text-2xl font-bold text-slate-900">{{ job.job_order_number }}</h3>
          <p class="text-slate-600">{{ job.customer_name }} - {{ job.device_type }}</p>
        </div>
        <AppLink to="/technician" class="font-semibold text-slate-700 hover:text-slate-900">Back to My Jobs</AppLink>
      </div>

      <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
          <div class="card p-6">
            <h4 class="mb-4 text-lg font-semibold text-slate-900">Job Details</h4>
            <div class="grid grid-cols-1 gap-4 text-sm md:grid-cols-2">
              <div>
                <p class="text-slate-500">Status</p>
                <p class="font-semibold text-slate-900">{{ job.current_status.replaceAll('_', ' ') }}</p>
              </div>
              <div>
                <p class="text-slate-500">Priority</p>
                <p class="font-semibold text-slate-900">{{ job.priority }}</p>
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
            <h4 class="mb-4 text-lg font-semibold text-slate-900">Timeline</h4>
            <div class="space-y-3">
              <div v-for="entry in timeline" :key="entry.id" class="border-b border-slate-100 pb-3 last:border-0 last:pb-0">
                <p class="font-semibold text-slate-900">{{ entry.status.replaceAll('_', ' ') }}</p>
                <p class="text-sm text-slate-500">{{ entry.created_at_formatted }}</p>
                <p v-if="entry.note" class="text-sm text-slate-700">{{ entry.note }}</p>
              </div>
              <p v-if="!timeline.length" class="text-sm text-slate-500">No status history available.</p>
            </div>
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
                  v-if="job.can_record_parts"
                  type="button"
                  class="mt-3 text-sm font-semibold text-rose-700 hover:text-rose-800 disabled:opacity-60"
                  :disabled="removingPartId === part.id"
                  @click="removePart(part)"
                >
                  {{ removingPartId === part.id ? 'Removing...' : 'Remove Part Entry' }}
                </button>
              </div>
            </div>
            <p v-else class="text-sm text-slate-500">No parts recorded yet.</p>
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
            <h4 class="mb-4 text-lg font-semibold text-slate-900">Request Missing Part</h4>
            <div v-if="job.can_record_parts">
              <form class="grid grid-cols-1 gap-3 md:grid-cols-3" @submit.prevent="requestPart">
                <input v-model="requestForm.part_name" class="rounded-lg border border-slate-300 px-4 py-2 md:col-span-2" placeholder="Requested part name" required />
                <input v-model.number="requestForm.quantity" type="number" min="1" class="rounded-lg border border-slate-300 px-4 py-2" placeholder="Quantity" required />
                <input v-model="requestForm.note" class="rounded-lg border border-slate-300 px-4 py-2 md:col-span-3" placeholder="Why this part is needed / note" />
                <button type="submit" class="rounded-lg bg-amber-600 py-2 font-semibold text-white hover:bg-amber-700 md:col-span-3">
                  Request Part and Mark Waiting
                </button>
              </form>
              <p class="mt-3 text-xs text-slate-500">
                This will add a timeline entry and move the job to <span class="font-semibold">WAITING FOR PARTS</span> so staff and admin can see it in the queue.
              </p>
            </div>
            <p v-else class="text-sm text-slate-500">Parts request is locked for this job because it is already finalized or ready for pickup.</p>
          </div>
        </div>

        <div class="space-y-6">
          <div class="card p-6">
            <h4 class="mb-4 text-lg font-semibold text-slate-900">Update Repair Status</h4>
            <form class="space-y-3" @submit.prevent="submit">
              <select v-model="form.status" class="w-full rounded-lg border border-slate-300 px-4 py-2" required>
                <option v-for="status in allowedStatuses" :key="status" :value="status">{{ status.replaceAll('_', ' ') }}</option>
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
