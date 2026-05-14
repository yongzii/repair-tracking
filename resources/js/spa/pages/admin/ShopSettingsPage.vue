<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import { authState, ensureAdmin } from '../../composables/auth';
import { navigate } from '../../navigation';

const loading = ref(true);
const forbidden = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const shop = ref(null);

const form = reactive({
    name: '',
    slug: '',
    email: '',
    phone: '',
    address: '',
    primary_color: '#0f766e',
    default_warranty_days: 30,
});

const trackingUrl = computed(() => shop.value?.public_tracking_url || '');
const bookingUrl = computed(() => shop.value?.public_booking_url || '');
const consultationUrl = computed(() => shop.value?.public_consultation_url || '');

const loadShop = async () => {
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

    loading.value = true;
    try {
        const { data } = await window.axios.get('/api/admin/shop');
        shop.value = data.shop;
        Object.assign(form, {
            name: data.shop.name || '',
            slug: data.shop.slug || '',
            email: data.shop.email || '',
            phone: data.shop.phone || '',
            address: data.shop.address || '',
            primary_color: data.shop.primary_color || '#0f766e',
            default_warranty_days: data.shop.default_warranty_days ?? 30,
        });
    } catch (error) {
        forbidden.value = true;
        errorMessage.value = error?.response?.data?.message || 'Unable to load shop settings.';
    } finally {
        loading.value = false;
    }
};

const saveShop = async () => {
    errorMessage.value = '';
    successMessage.value = '';

    try {
        const { data } = await window.axios.put('/api/admin/shop', {
            name: form.name,
            slug: form.slug,
            email: form.email || null,
            phone: form.phone || null,
            address: form.address || null,
            primary_color: form.primary_color || null,
            default_warranty_days: Number(form.default_warranty_days || 0),
        });

        shop.value = data.shop;
        if (authState.user?.shop) {
            authState.user.shop = {
                ...authState.user.shop,
                id: data.shop.id,
                name: data.shop.name,
                slug: data.shop.slug,
            };
        }
        successMessage.value = data.message || 'Shop settings updated successfully.';
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to save shop settings.';
    }
};

onMounted(loadShop);
</script>

<template>
  <AdminLayout title="Shop Settings">
    <div v-if="loading" class="card p-6 text-slate-600">Loading shop settings...</div>
    <div v-else-if="forbidden" class="card p-6 border border-rose-200 bg-rose-50 text-rose-700">{{ errorMessage || 'You do not have access to this page.' }}</div>

    <div v-else class="space-y-6">
      <div v-if="successMessage" class="p-3 rounded border border-emerald-200 bg-emerald-50 text-emerald-700">{{ successMessage }}</div>
      <div v-if="errorMessage" class="p-3 rounded border border-rose-200 bg-rose-50 text-rose-700">{{ errorMessage }}</div>

      <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
        <div class="xl:col-span-2 card p-6">
          <div class="flex items-center justify-between mb-4">
            <div>
              <h3 class="text-lg font-semibold text-slate-900">Business Profile</h3>
              <p class="text-sm text-slate-500">This information will represent your shop across the SaaS workspace.</p>
            </div>
          </div>

          <form class="grid grid-cols-1 md:grid-cols-2 gap-4" @submit.prevent="saveShop">
            <label class="space-y-2">
              <span class="text-sm font-medium text-slate-700">Shop Name</span>
              <input v-model="form.name" class="w-full px-4 py-2 border border-slate-300 rounded-lg" required />
            </label>

            <label class="space-y-2">
              <span class="text-sm font-medium text-slate-700">Shop Code / Slug</span>
              <input v-model="form.slug" class="w-full px-4 py-2 border border-slate-300 rounded-lg" placeholder="repair-hub" required />
            </label>

            <label class="space-y-2">
              <span class="text-sm font-medium text-slate-700">Email</span>
              <input v-model="form.email" type="email" class="w-full px-4 py-2 border border-slate-300 rounded-lg" />
            </label>

            <label class="space-y-2">
              <span class="text-sm font-medium text-slate-700">Phone</span>
              <input v-model="form.phone" class="w-full px-4 py-2 border border-slate-300 rounded-lg" />
            </label>

            <label class="space-y-2 md:col-span-2">
              <span class="text-sm font-medium text-slate-700">Address</span>
              <input v-model="form.address" class="w-full px-4 py-2 border border-slate-300 rounded-lg" />
            </label>

            <label class="space-y-2">
              <span class="text-sm font-medium text-slate-700">Primary Color</span>
              <input v-model="form.primary_color" type="color" class="h-11 w-full px-2 py-2 border border-slate-300 rounded-lg" />
            </label>

            <label class="space-y-2">
              <span class="text-sm font-medium text-slate-700">Default Warranty Days</span>
              <input v-model.number="form.default_warranty_days" type="number" min="0" max="3650" class="w-full px-4 py-2 border border-slate-300 rounded-lg" required />
            </label>

            <div class="space-y-2">
              <span class="text-sm font-medium text-slate-700">Subscription Plan</span>
              <div class="h-11 flex items-center px-4 rounded-lg border border-slate-200 bg-slate-50 text-slate-700 uppercase">
                {{ shop?.subscription_plan || 'starter' }}
              </div>
            </div>

            <div class="md:col-span-2 flex justify-end">
              <button type="submit" class="px-5 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-800 font-semibold">Save Shop Settings</button>
            </div>
          </form>
        </div>

        <div class="space-y-6">
          <div class="card p-6">
            <h3 class="text-lg font-semibold text-slate-900">Public Tracking</h3>
            <p class="text-sm text-slate-500 mt-2">Share this link or print the shop code on receipts so customers can track repairs in your own workspace.</p>
            <div class="mt-4 p-3 rounded-lg border border-slate-200 bg-slate-50 break-all text-sm text-slate-700">
              {{ trackingUrl || 'Tracking link unavailable.' }}
            </div>
            <p class="text-xs text-slate-500 mt-3">Shop code: <span class="font-semibold text-slate-700">{{ shop?.slug }}</span></p>
          </div>

          <div class="card p-6">
            <h3 class="text-lg font-semibold text-slate-900">Public Drop-off Booking</h3>
            <div class="mt-4 p-3 rounded-lg border border-slate-200 bg-slate-50 break-all text-sm text-slate-700">
              {{ bookingUrl || 'Drop-off booking link unavailable.' }}
            </div>
          </div>

          <div class="card p-6">
            <h3 class="text-lg font-semibold text-slate-900">Public Free Consultation</h3>
            <div class="mt-4 p-3 rounded-lg border border-slate-200 bg-slate-50 break-all text-sm text-slate-700">
              {{ consultationUrl || 'Consultation link unavailable.' }}
            </div>
          </div>

          <div class="card p-6">
            <h3 class="text-lg font-semibold text-slate-900">Workspace Status</h3>
            <div class="mt-4 space-y-3 text-sm">
              <div class="flex items-center justify-between">
                <span class="text-slate-500">Workspace</span>
                <span class="font-semibold" :class="shop?.is_active ? 'text-emerald-700' : 'text-rose-700'">{{ shop?.is_active ? 'Active' : 'Inactive' }}</span>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-slate-500">Trial Ends</span>
                <span class="font-semibold text-slate-800">{{ shop?.trial_ends_at_formatted || 'Not set' }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
