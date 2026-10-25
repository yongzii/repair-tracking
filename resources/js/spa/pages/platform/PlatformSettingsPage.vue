<script setup>
import { onMounted, reactive, ref } from 'vue';
import PlatformLayout from '../../layouts/PlatformLayout.vue';
import { ensurePlatformAdmin } from '../../composables/auth';
import { navigate } from '../../navigation';

const loading = ref(true);
const saving = ref(false);
const forbidden = ref(false);
const errorMessage = ref('');
const successMessage = ref('');

const form = reactive({
    instructions: '',
    plans: [],
    payment_methods: [],
});

const prepareSettings = (settings) => {
    form.instructions = settings?.instructions || '';
    form.plans = (settings?.plans || []).map((plan) => ({ ...plan }));
    form.payment_methods = (settings?.payment_methods || []).map((method) => ({ ...method }));
};

const loadSettings = async () => {
    const auth = await ensurePlatformAdmin();
    if (!auth.ok) {
        if (auth.reason === 'unauthenticated') {
            navigate('/login', { replace: true });
            return;
        }
        forbidden.value = true;
        loading.value = false;
        return;
    }

    errorMessage.value = '';
    try {
        const { data } = await window.axios.get('/api/platform/subscription-settings');
        prepareSettings(data.settings);
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Unable to load platform settings.';
    } finally {
        loading.value = false;
    }
};

const retryLoad = () => {
    loading.value = true;
    loadSettings();
};

const addPlan = () => {
    form.plans.push({
        key: `plan-${form.plans.length + 1}`,
        name: 'New Plan',
        amount: 0,
        duration_months: 1,
    });
};

const addPaymentMethod = () => {
    form.payment_methods.push({
        key: `method-${form.payment_methods.length + 1}`,
        name: 'Payment Method',
        account_name: '',
        account_number: '',
        instructions: '',
    });
};

const saveSettings = async () => {
    saving.value = true;
    errorMessage.value = '';
    successMessage.value = '';

    try {
        const { data } = await window.axios.put('/api/platform/subscription-settings', {
            instructions: form.instructions,
            plans: form.plans,
            payment_methods: form.payment_methods,
        });
        prepareSettings(data.settings);
        successMessage.value = data.message || 'Platform settings updated.';
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to save platform settings.';
    } finally {
        saving.value = false;
    }
};

onMounted(loadSettings);
</script>

<template>
  <PlatformLayout title="Platform Settings">
    <section class="platform-page-heading">
      <div>
        <p class="platform-eyebrow">PLATFORM ADMIN</p>
        <h1>Platform Settings</h1>
        <p>Configure the subscription plans and payment instructions presented to shops.</p>
      </div>
    </section>

    <div v-if="successMessage" class="platform-alert platform-alert-success" role="status">{{ successMessage }}</div>
    <div v-if="errorMessage" class="platform-alert platform-alert-danger" role="alert">{{ errorMessage }}</div>

    <section v-if="loading" class="platform-card platform-state" role="status">
      <span class="platform-spinner" aria-hidden="true"></span>
      <span>Loading platform settings...</span>
    </section>
    <section v-else-if="forbidden" class="platform-card platform-state platform-state-danger" role="alert">
      Only platform admins can access platform settings.
    </section>
    <section v-else-if="errorMessage && !form.plans.length && !form.payment_methods.length" class="platform-card platform-state" role="alert">
      <span class="platform-state-icon platform-state-icon-danger" aria-hidden="true"><i class="fas fa-triangle-exclamation"></i></span>
      <strong>Settings unavailable</strong>
      <span>Retry loading the current settings before making changes.</span>
      <button type="button" class="btn-soft mt-2 px-4 py-2 text-sm" @click="retryLoad">Try again</button>
    </section>
    <form v-else class="space-y-5" @submit.prevent="saveSettings">
      <section class="platform-card p-5 sm:p-6">
        <div class="platform-section-heading">
          <div>
            <h2>Subscription Instructions</h2>
            <p>Shown to shops when they submit a subscription request.</p>
          </div>
        </div>
        <label class="platform-field mt-5">
          <span>Instructions</span>
          <textarea v-model="form.instructions" rows="4" maxlength="2000" placeholder="Enter payment and subscription instructions"></textarea>
        </label>
      </section>

      <section class="platform-card p-5 sm:p-6">
        <div class="platform-section-heading">
          <div>
            <h2>Subscription Plans</h2>
            <p>Manage the plan names, prices, and coverage durations offered to shops.</p>
          </div>
          <button type="button" class="platform-button platform-button-secondary" @click="addPlan">
            <i class="fas fa-plus" aria-hidden="true"></i><span>Add Plan</span>
          </button>
        </div>

        <div v-if="form.plans.length" class="mt-5 space-y-3">
          <fieldset v-for="(plan, index) in form.plans" :key="`plan-${index}`" class="platform-form-row">
            <legend class="sr-only">Subscription plan {{ index + 1 }}</legend>
            <label class="platform-field"><span>Plan key</span><input v-model="plan.key" required maxlength="80" placeholder="monthly" /></label>
            <label class="platform-field"><span>Display name</span><input v-model="plan.name" required maxlength="120" placeholder="Monthly" /></label>
            <label class="platform-field"><span>Amount (PHP)</span><input v-model="plan.amount" type="number" min="0.01" max="9999999.99" step="0.01" required /></label>
            <label class="platform-field"><span>Duration (months)</span><input v-model="plan.duration_months" type="number" min="1" max="120" required /></label>
            <button type="button" class="platform-button platform-button-danger" :disabled="form.plans.length <= 1" @click="form.plans.splice(index, 1)">Remove</button>
          </fieldset>
        </div>
        <div v-else class="platform-inline-empty">No plans configured. Add a plan before saving.</div>
      </section>

      <section class="platform-card p-5 sm:p-6">
        <div class="platform-section-heading">
          <div>
            <h2>Payment Methods</h2>
            <p>Payment details displayed during subscription checkout.</p>
          </div>
          <button type="button" class="platform-button platform-button-secondary" @click="addPaymentMethod">
            <i class="fas fa-plus" aria-hidden="true"></i><span>Add Method</span>
          </button>
        </div>

        <div v-if="form.payment_methods.length" class="mt-5 space-y-3">
          <fieldset v-for="(method, index) in form.payment_methods" :key="`method-${index}`" class="platform-payment-row">
            <legend class="sr-only">Payment method {{ index + 1 }}</legend>
            <label class="platform-field"><span>Method key</span><input v-model="method.key" required maxlength="80" placeholder="gcash" /></label>
            <label class="platform-field"><span>Display name</span><input v-model="method.name" required maxlength="120" placeholder="GCash" /></label>
            <label class="platform-field"><span>Account name</span><input v-model="method.account_name" maxlength="150" /></label>
            <label class="platform-field"><span>Account number</span><input v-model="method.account_number" maxlength="150" /></label>
            <label class="platform-field platform-payment-instructions"><span>Method instructions</span><input v-model="method.instructions" maxlength="500" /></label>
            <button type="button" class="platform-button platform-button-danger" :disabled="form.payment_methods.length <= 1" @click="form.payment_methods.splice(index, 1)">Remove</button>
          </fieldset>
        </div>
        <div v-else class="platform-inline-empty">No payment methods configured. Add a method before saving.</div>
      </section>

      <div class="flex justify-end">
        <button type="submit" class="platform-button platform-button-primary" :disabled="saving || !form.plans.length || !form.payment_methods.length">
          <i v-if="saving" class="fas fa-spinner fa-spin" aria-hidden="true"></i>
          {{ saving ? 'Saving...' : 'Save Platform Settings' }}
        </button>
      </div>
    </form>
  </PlatformLayout>
</template>
