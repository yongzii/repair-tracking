<script setup>
import { reactive, ref } from 'vue';
import AppLink from '../components/AppLink.vue';
import { adminRoles, login, authState, platformRoles } from '../composables/auth';
import { navigate } from '../navigation';
import { appConfig } from '../config';

const form = reactive({
    email: '',
    password: '',
});

const loading = ref(false);
const errorMessage = ref('');

if (platformRoles.includes(authState.user?.role)) {
    navigate('/platform', { replace: true });
} else if ([...adminRoles, 'staff'].includes(authState.user?.role)) {
    navigate('/admin', { replace: true });
} else if (authState.user?.role === 'technician') {
    navigate('/technician', { replace: true });
}

const submit = async () => {
    loading.value = true;
    errorMessage.value = '';

    try {
        await login(form);
        if (platformRoles.includes(authState.user?.role)) {
            navigate('/platform', { replace: true });
        } else if (authState.user?.role === 'technician') {
            navigate('/technician', { replace: true });
        } else {
            navigate('/admin', { replace: true });
        }
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Login failed.';
    } finally {
        loading.value = false;
    }
};
</script>

<template>
  <div class="auth-shell flex min-h-screen items-center justify-center px-5 py-8">
    <div class="grid w-full max-w-6xl overflow-hidden rounded-[1.75rem] border border-white/10 bg-white shadow-2xl shadow-slate-950/30 lg:grid-cols-[0.95fr_1.05fr]">
      <section class="relative hidden min-h-[660px] overflow-hidden bg-slate-950 p-10 text-white lg:flex lg:flex-col lg:justify-between">
        <div class="absolute inset-x-0 top-0 h-2 bg-[linear-gradient(90deg,#0ea5a4,#f59e0b)]"></div>

        <div>
          <AppLink to="/" class="inline-flex items-center gap-3">
            <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-white text-sm font-bold text-slate-950">RS</span>
            <span>
              <span class="block text-sm font-bold">RepairSync</span>
              <span class="block text-xs font-medium text-slate-300">Operations Portal</span>
            </span>
          </AppLink>

          <div class="mt-16">
            <p class="text-xs font-bold uppercase text-teal-300">Secure shop access</p>
            <h1 class="mt-4 max-w-md font-display text-5xl leading-tight">
              Manage repair work from intake to pickup.
            </h1>
            <p class="mt-5 max-w-md text-base leading-7 text-slate-300">
              Sign in to organize job orders, technician work, spare parts, invoices, payments, and customer repair updates.
            </p>
          </div>
        </div>

        <div class="grid gap-3">
          <div class="rounded-xl border border-white/10 bg-white/5 p-4">
            <p class="text-sm font-bold text-white">Repair queue visibility</p>
            <p class="mt-1 text-sm leading-6 text-slate-300">Track active jobs, overdue work, and ready-for-pickup repairs.</p>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div class="rounded-xl border border-white/10 bg-white/5 p-4">
              <p class="text-sm font-bold text-white">Inventory</p>
              <p class="mt-1 text-xs leading-5 text-slate-300">Parts usage and requests</p>
            </div>
            <div class="rounded-xl border border-white/10 bg-white/5 p-4">
              <p class="text-sm font-bold text-white">Reports</p>
              <p class="mt-1 text-xs leading-5 text-slate-300">Revenue and operations summaries</p>
            </div>
          </div>
        </div>
      </section>

      <section class="flex min-h-[660px] flex-col justify-center bg-[linear-gradient(135deg,#ffffff_0%,#f8fafc_58%,#e9f7f5_100%)] p-6 sm:p-10">
        <div class="mx-auto w-full max-w-md">
          <div class="mb-8 lg:hidden">
            <AppLink to="/" class="inline-flex items-center gap-3">
              <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-slate-950 text-sm font-bold text-white">RS</span>
              <span>
                <span class="block text-sm font-bold text-slate-950">RepairSync</span>
                <span class="block text-xs font-medium text-slate-500">Operations Portal</span>
              </span>
            </AppLink>
          </div>

          <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-xl shadow-slate-900/8 sm:p-8">
            <div class="text-center">
              <p class="text-xs font-bold uppercase text-teal-700">Repair service management</p>
              <h2 class="mt-3 text-3xl font-bold text-slate-950">Operations Login</h2>
              <p class="mt-3 text-sm leading-6 text-slate-500">
                Access the admin, staff, or technician workspace for your repair shop.
              </p>
            </div>

            <div v-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
              <p>{{ errorMessage }}</p>
            </div>

            <form class="mt-7 space-y-5" @submit.prevent="submit">
              <div>
                <label for="email" class="block text-sm font-bold text-slate-700">Email Address</label>
                <input
                  id="email"
                  v-model="form.email"
                  type="email"
                  :placeholder="appConfig.adminLoginHint"
                  class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-4 py-3 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-teal-500 focus:ring-4 focus:ring-teal-100"
                  required
                />
              </div>

              <div>
                <label for="password" class="block text-sm font-bold text-slate-700">Password</label>
                <input
                  id="password"
                  v-model="form.password"
                  type="password"
                  placeholder="Enter your password"
                  class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-4 py-3 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-teal-500 focus:ring-4 focus:ring-teal-100"
                  required
                />
              </div>

              <button type="submit" class="auth-button flex min-h-12 w-full items-center justify-center rounded-lg px-5 text-sm font-bold transition disabled:cursor-not-allowed disabled:opacity-70" :disabled="loading">
                {{ loading ? 'Signing in...' : 'Sign In' }}
              </button>
            </form>
          </div>

          <div class="mt-6 flex flex-col gap-3 text-center text-sm font-bold text-slate-600 sm:flex-row sm:items-center sm:justify-center">
            <AppLink to="/track" class="rounded-lg border border-slate-200 bg-white px-4 py-2 hover:border-teal-200 hover:text-teal-700">Customer Tracking</AppLink>
            <AppLink to="/get-started" class="rounded-lg border border-slate-200 bg-white px-4 py-2 hover:border-teal-200 hover:text-teal-700">Create Shop Workspace</AppLink>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>
