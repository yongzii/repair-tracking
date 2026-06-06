<script setup>
import { reactive, ref, watch } from 'vue';
import AppLink from '../components/AppLink.vue';
import { isCsrfMismatch, refreshCsrfToken } from '../composables/auth';
import { navigate } from '../navigation';

const loading = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const createdTrackingUrl = ref('');

const form = reactive({
    shop_name: '',
    shop_slug: '',
    shop_email: '',
    shop_phone: '',
    shop_address: '',
    owner_name: '',
    owner_email: '',
    owner_password: '',
    owner_password_confirmation: '',
});

let slugTimer = null;

watch(
    () => form.shop_name,
    (value) => {
        if (form.shop_slug.trim() !== '') {
            return;
        }

        window.clearTimeout(slugTimer);
        slugTimer = window.setTimeout(async () => {
            if (!value.trim()) return;
            try {
                const { data } = await window.axios.get('/api/onboarding/check-slug', {
                    params: { slug: value },
                });
                form.shop_slug = data.slug || '';
            } catch {
                // no-op
            }
        }, 250);
    }
);

const submit = async () => {
    loading.value = true;
    errorMessage.value = '';
    successMessage.value = '';

    try {
        await refreshCsrfToken();
        const { data } = await window.axios.post('/api/onboarding/register-shop', form);
        successMessage.value = data.message || 'Shop workspace created successfully.';
        createdTrackingUrl.value = data.shop?.tracking_url || '';
        window.setTimeout(() => {
            navigate('/login', { replace: true });
        }, 1200);
    } catch (error) {
        if (isCsrfMismatch(error)) {
            try {
                await refreshCsrfToken();
                const { data } = await window.axios.post('/api/onboarding/register-shop', form);
                successMessage.value = data.message || 'Shop workspace created successfully.';
                createdTrackingUrl.value = data.shop?.tracking_url || '';
                window.setTimeout(() => {
                    navigate('/login', { replace: true });
                }, 1200);
                return;
            } catch (retryError) {
                error = retryError;
            }
        }

        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to create shop workspace.';
    } finally {
        loading.value = false;
    }
};
</script>

<template>
  <div class="public-shell px-4 py-10">
    <div class="mx-auto grid max-w-6xl grid-cols-1 gap-8 lg:grid-cols-5">
      <div class="admin-hero p-8 lg:col-span-2">
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-teal-300">SaaS Onboarding</p>
        <h1 class="mt-3 font-display text-4xl leading-tight text-white">Launch Your Repair Shop Workspace</h1>
        <p class="mt-4 leading-7 text-slate-300">
          Create a dedicated shop account with its own staff, tracking portal, inventory, and job orders.
        </p>

        <div class="mt-8 space-y-4 text-sm text-slate-300">
          <div class="rounded-xl border border-white/10 bg-white/5 p-4">
            <p class="font-bold text-white">What you get first</p>
            <p class="mt-1">A separate shop workspace, owner login, and a public tracking link unique to your business.</p>
          </div>
          <div class="rounded-xl border border-white/10 bg-white/5 p-4">
            <p class="font-bold text-white">Built for repair operations</p>
            <p class="mt-1">Start with job orders, customer tracking, inventory monitoring, and staff access.</p>
          </div>
        </div>

        <div class="mt-8">
          <AppLink to="/login" class="inline-flex rounded-lg bg-white px-4 py-2 text-sm font-bold text-slate-950 hover:bg-slate-100">Already have a workspace? Sign in</AppLink>
        </div>
      </div>

      <div class="public-card p-8 lg:col-span-3">
        <div class="mb-6">
          <p class="text-xs font-bold uppercase text-teal-700">Workspace setup</p>
          <h2 class="mt-2 text-2xl font-bold text-slate-950">Create Shop Workspace</h2>
        </div>

        <div v-if="errorMessage" class="mb-4 p-4 rounded-lg border border-rose-200 bg-rose-50 text-rose-700">{{ errorMessage }}</div>
        <div v-if="successMessage" class="mb-4 p-4 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-700">
          {{ successMessage }}
          <p v-if="createdTrackingUrl" class="mt-2 text-sm break-all">{{ createdTrackingUrl }}</p>
        </div>

        <form class="grid grid-cols-1 md:grid-cols-2 gap-4" @submit.prevent="submit">
          <label class="space-y-2 md:col-span-2">
            <span class="text-sm font-bold text-slate-700">Shop Name</span>
            <input v-model="form.shop_name" class="w-full px-4 py-2 border border-slate-300 rounded-lg" required />
          </label>

          <label class="space-y-2">
            <span class="text-sm font-bold text-slate-700">Shop Code / Slug</span>
            <input v-model="form.shop_slug" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="repair-hub" required />
          </label>

          <label class="space-y-2">
            <span class="text-sm font-bold text-slate-700">Shop Email</span>
            <input v-model="form.shop_email" type="email" class="w-full px-4 py-2 border border-slate-300 rounded-lg" />
          </label>

          <label class="space-y-2">
            <span class="text-sm font-bold text-slate-700">Shop Phone</span>
            <input v-model="form.shop_phone" class="w-full px-4 py-2 border border-slate-300 rounded-lg" />
          </label>

          <label class="space-y-2">
            <span class="text-sm font-bold text-slate-700">Shop Address</span>
            <input v-model="form.shop_address" class="w-full px-4 py-2 border border-slate-300 rounded-lg" />
          </label>

          <label class="space-y-2">
            <span class="text-sm font-bold text-slate-700">Owner Name</span>
            <input v-model="form.owner_name" class="w-full px-4 py-2 border border-slate-300 rounded-lg" required />
          </label>

          <label class="space-y-2">
            <span class="text-sm font-bold text-slate-700">Owner Email</span>
            <input v-model="form.owner_email" type="email" class="w-full px-4 py-2 border border-slate-300 rounded-lg" required />
          </label>

          <label class="space-y-2">
            <span class="text-sm font-bold text-slate-700">Password</span>
            <input v-model="form.owner_password" type="password" class="w-full px-4 py-2 border border-slate-300 rounded-lg" required />
          </label>

          <label class="space-y-2">
            <span class="text-sm font-bold text-slate-700">Confirm Password</span>
            <input v-model="form.owner_password_confirmation" type="password" class="w-full px-4 py-2 border border-slate-300 rounded-lg" required />
          </label>

          <div class="md:col-span-2 flex justify-end pt-2">
            <button type="submit" class="btn-primary px-6 py-3" :disabled="loading">
              {{ loading ? 'Creating Workspace...' : 'Create Workspace' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
