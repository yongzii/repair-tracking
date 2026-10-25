<script setup>
import { reactive, ref } from 'vue';
import AppLink from '../components/AppLink.vue';
import { adminRoles, login, authState, platformRoles } from '../composables/auth';
import { navigate } from '../navigation';
import { appConfig } from '../config';

const form = reactive({
    identifier: '',
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
        await login({
            email: form.identifier,
            password: form.password,
        });
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
  <div class="login-page">
    <div class="login-container">
      <AppLink to="/" class="login-brand" aria-label="RepairSync home">
        <img src="/images/repairsync-cloud-logo.png" alt="RepairSync Cloud" class="login-brand-logo" />
      </AppLink>

      <main class="login-card">
        <div class="login-heading">
          <p class="login-eyebrow">RepairSync Cloud</p>
          <h1>Welcome back</h1>
          <p>Sign in to manage your repair shop.</p>
        </div>

        <div v-if="errorMessage" class="login-error" role="alert">
          <p>{{ errorMessage }}</p>
        </div>

        <form class="login-form" @submit.prevent="submit">
          <div class="login-field">
            <label for="login-identifier">Email or Username</label>
            <input
              id="login-identifier"
              v-model="form.identifier"
              type="text"
              autocomplete="username"
              :placeholder="`Email (${appConfig.adminLoginHint}) or username`"
              required
            />
          </div>

          <div class="login-field">
            <label for="password">Password</label>
            <input
              id="password"
              v-model="form.password"
              type="password"
              placeholder="Enter your password"
              required
            />
          </div>

          <button type="submit" class="login-submit" :disabled="loading">
            {{ loading ? 'Signing in...' : 'Sign In' }}
          </button>
        </form>
      </main>

      <nav class="login-links" aria-label="Helpful links">
        <AppLink to="/track">Track a Repair</AppLink>
        <AppLink to="/get-started">Register Your Shop</AppLink>
      </nav>
    </div>
  </div>
</template>

<style scoped>
.login-page {
  min-height: 100vh;
  padding: 2.5rem 1.25rem;
  color: var(--ui-text);
  background: var(--ui-page);
}

.login-container {
  display: flex;
  width: min(100%, 28rem);
  min-height: calc(100vh - 5rem);
  margin: 0 auto;
  flex-direction: column;
  justify-content: center;
}

.login-brand {
  display: inline-flex;
  align-items: center;
  align-self: center;
  gap: 0.75rem;
  margin-bottom: 1.75rem;
  color: var(--ui-text-strong);
  text-decoration: none;
}

.login-brand-logo {
  display: block;
  width: 30rem;
  max-width: none;
  height: 9rem;
  object-fit: cover;
  object-position: center;
}

.login-card {
  padding: clamp(1.5rem, 5vw, 2.5rem);
  border: 1px solid var(--ui-border);
  border-radius: var(--ui-radius-xl);
  background: var(--ui-surface);
  box-shadow: var(--ui-shadow-elevated);
}

.login-heading {
  text-align: center;
}

.login-eyebrow {
  margin: 0;
  color: var(--ui-accent-700);
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.login-heading h1 {
  margin: 0.75rem 0 0;
  color: var(--ui-text-strong);
  font-family: var(--font-sans);
  font-size: clamp(1.875rem, 7vw, 2.25rem);
  font-weight: 700;
  letter-spacing: -0.025em;
  line-height: 1.15;
}

.login-heading > p:last-child {
  margin: 0.75rem 0 0;
  color: var(--ui-text-muted);
  font-size: 0.9375rem;
  line-height: 1.5;
}

.login-error {
  margin-top: 1.5rem;
  padding: 0.875rem 1rem;
  border: 1px solid var(--ui-danger);
  border-radius: var(--ui-radius-md);
  color: var(--ui-danger);
  background: var(--ui-surface-subtle);
  font-size: 0.875rem;
  line-height: 1.5;
}

.login-form {
  display: grid;
  gap: 1.25rem;
  margin-top: 2rem;
}

.login-field {
  display: grid;
  gap: 0.5rem;
}

.login-field label {
  color: var(--ui-text);
  font-size: 0.875rem;
  font-weight: 700;
}

.login-field input {
  width: 100%;
  min-height: 3rem;
  padding: 0.75rem 1rem;
  border: 1px solid var(--ui-border-strong);
  border-radius: var(--ui-radius-md);
  color: var(--ui-text);
  background: var(--ui-surface);
  box-shadow: none;
}

.login-field input:focus {
  border-color: var(--ui-focus);
  outline: none;
  box-shadow: 0 0 0 4px rgba(13, 148, 136, 0.14);
}

.login-field input::placeholder {
  color: var(--ui-text-subtle);
  opacity: 1;
}

.login-submit {
  min-height: 3rem;
  margin-top: 0.25rem;
  border: 0;
  border-radius: var(--ui-radius-md);
  color: #ffffff;
  background: var(--ui-brand-900);
  box-shadow: 0 8px 16px rgba(15, 27, 45, 0.14);
  font-size: 0.875rem;
  font-weight: 700;
}

.login-submit:focus-visible {
  outline: 2px solid var(--ui-focus);
  outline-offset: 3px;
}

.login-submit:hover:not(:disabled) {
  background: var(--ui-brand-800);
  transform: translateY(-1px);
}

.login-submit:active:not(:disabled) {
  transform: translateY(0);
}

.login-submit:disabled {
  cursor: not-allowed;
  opacity: 0.7;
}

.login-links {
  display: flex;
  justify-content: center;
  gap: 0.75rem;
  margin-top: 1.25rem;
  text-align: center;
  font-size: 0.875rem;
  font-weight: 700;
}

.login-links a {
  padding: 0.625rem 0.75rem;
  border: 1px solid transparent;
  border-radius: var(--ui-radius-md);
  color: var(--ui-link);
  text-decoration: none;
}

.login-links a:hover {
  border-color: var(--ui-border);
  background: var(--ui-surface);
  color: var(--ui-accent-700);
  text-decoration: underline;
  text-underline-offset: 0.2em;
}

.login-links a:focus-visible {
  outline: 2px solid var(--ui-focus);
  outline-offset: 2px;
}

@media (max-width: 30rem) {
  .login-page {
    padding: 1.5rem 1rem;
  }

  .login-container {
    min-height: calc(100vh - 3rem);
  }

  .login-brand {
    margin-bottom: 1.25rem;
  }

  .login-brand-logo {
    width: min(28rem, calc(100vw - 2rem));
    max-width: 100%;
    height: 7rem;
  }

  .login-links {
    flex-direction: column;
    gap: 0.75rem;
  }

  .login-links a {
    width: 100%;
  }
}
</style>
