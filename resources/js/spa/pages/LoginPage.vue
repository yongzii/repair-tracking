<script setup>
import { reactive, ref } from 'vue';
import AppLink from '../components/AppLink.vue';
import { adminRoles, login, authState } from '../composables/auth';
import { navigate } from '../navigation';
import { appConfig } from '../config';

const form = reactive({
    email: '',
    password: '',
});

const loading = ref(false);
const errorMessage = ref('');

if ([...adminRoles, 'staff'].includes(authState.user?.role)) {
    navigate('/admin', { replace: true });
} else if (authState.user?.role === 'technician') {
    navigate('/technician', { replace: true });
}

const submit = async () => {
    loading.value = true;
    errorMessage.value = '';

    try {
        await login(form);
        if (authState.user?.role === 'technician') {
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
  <div class="auth-shell flex flex-col items-center justify-center px-4">
    <div class="w-full max-w-md">
      <div class="text-center mb-8">
        <h1 class="text-4xl font-bold text-white">{{ appConfig.name }}</h1>
        <p class="text-blue-100 mt-2">{{ appConfig.tagline }}</p>
      </div>

      <div class="bg-white rounded-lg shadow-2xl p-8">
        <h2 class="text-2xl font-semibold text-gray-900 mb-6 text-center">Operations Login</h2>

        <div v-if="errorMessage" class="mb-4 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
          <p>{{ errorMessage }}</p>
        </div>

        <form @submit.prevent="submit">
          <div class="mb-6">
            <label for="email" class="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
            <input
              id="email"
              type="email"
              v-model="form.email"
              :placeholder="appConfig.adminLoginHint"
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-600 focus:border-transparent"
              required
            />
          </div>

          <div class="mb-6">
            <label for="password" class="block text-sm font-medium text-gray-700 mb-2">Password</label>
            <input
              id="password"
              type="password"
              v-model="form.password"
              placeholder="Enter your password"
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-600 focus:border-transparent"
              required
            />
          </div>

          <button type="submit" class="auth-button w-full font-semibold py-2 rounded-lg transition mb-4 disabled:opacity-70" :disabled="loading">
            {{ loading ? 'Signing in...' : 'Sign In' }}
          </button>
        </form>
      </div>

      <div class="text-center mt-6">
        <div class="flex items-center justify-center gap-4 text-sm">
          <AppLink to="/track" class="text-blue-100 hover:text-white">Customer Tracking</AppLink>
          <AppLink to="/get-started" class="text-blue-100 hover:text-white">Create Shop Workspace</AppLink>
        </div>
      </div>
    </div>
  </div>
</template>
