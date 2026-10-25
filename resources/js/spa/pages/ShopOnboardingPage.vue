<script setup>
import { reactive, ref, watch } from 'vue';
import AppLink from '../components/AppLink.vue';
import { isCsrfMismatch, refreshCsrfToken, setAuthenticatedUser } from '../composables/auth';
import { navigate } from '../navigation';

const loading = ref(false);
const errorMessage = ref('');

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

    try {
        await refreshCsrfToken();
        const { data } = await window.axios.post('/api/onboarding/register-shop', form);
        setAuthenticatedUser(data.user, data.csrf_token);
        navigate('/admin', { replace: true });
    } catch (error) {
        if (isCsrfMismatch(error)) {
            try {
                await refreshCsrfToken();
                const { data } = await window.axios.post('/api/onboarding/register-shop', form);
                setAuthenticatedUser(data.user, data.csrf_token);
                navigate('/admin', { replace: true });
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
  <div class="registration-page">
    <div class="registration-container">
      <div class="registration-grid">
        <section class="registration-intro">
          <AppLink to="/" class="registration-brand" aria-label="RepairSync Cloud home">
            <span class="registration-brand-frame">
              <img src="/images/repairsync-cloud-logo.png" alt="RepairSync Cloud" class="registration-brand-logo" />
            </span>
          </AppLink>
          <p class="registration-eyebrow">SaaS onboarding</p>
          <h1>Launch your repair shop workspace</h1>
          <p class="registration-intro-copy">
          Create a dedicated shop account with its own staff, tracking portal, inventory, and job orders.
          </p>

          <div class="registration-panels">
            <div class="registration-panel">
              <p>What you get first</p>
              <span>A separate shop workspace, owner login, and a public tracking link reserved for your business.</span>
            </div>
            <div class="registration-panel">
              <p>Activation required</p>
              <span>You can explore preview mode first. Saving records and transactions opens after subscription payment is recorded.</span>
            </div>
          </div>

          <AppLink to="/login" class="registration-sign-in">Already have a workspace? Sign in</AppLink>
        </section>

        <section class="registration-card">
          <div class="registration-heading">
            <p class="registration-eyebrow">Workspace setup</p>
            <h2>Create shop workspace</h2>
          </div>

          <div v-if="errorMessage" class="registration-error" role="alert">{{ errorMessage }}</div>
          <form class="registration-form" @submit.prevent="submit">
            <label class="registration-field registration-field-wide">
              <span>Shop Name</span>
              <input v-model="form.shop_name" required />
            </label>

            <label class="registration-field">
              <span>Shop Code / Slug</span>
              <input v-model="form.shop_slug" placeholder="repair-hub" required />
            </label>

            <label class="registration-field">
              <span>Shop Email</span>
              <input v-model="form.shop_email" type="email" />
            </label>

            <label class="registration-field">
              <span>Shop Phone</span>
              <input v-model="form.shop_phone" />
            </label>

            <label class="registration-field">
              <span>Shop Address</span>
              <input v-model="form.shop_address" />
            </label>

            <label class="registration-field">
              <span>Owner Name</span>
              <input v-model="form.owner_name" required />
            </label>

            <label class="registration-field">
              <span>Owner Email</span>
              <input v-model="form.owner_email" type="email" required />
            </label>

            <label class="registration-field">
              <span>Password</span>
              <input v-model="form.owner_password" type="password" required />
            </label>

            <label class="registration-field">
              <span>Confirm Password</span>
              <input v-model="form.owner_password_confirmation" type="password" required />
            </label>

            <div class="registration-actions">
              <button type="submit" class="registration-submit" :disabled="loading">
                {{ loading ? 'Creating Workspace...' : 'Create Workspace' }}
              </button>
            </div>
          </form>
        </section>
      </div>
    </div>
  </div>
</template>

<style scoped>
.registration-page {
  min-height: 100vh;
  padding: 2.25rem 1rem 2rem;
  color: var(--ui-text);
  background: var(--ui-page);
}

.registration-container {
  display: flex;
  width: min(100%, 70rem);
  min-height: calc(100vh - 4.25rem);
  margin: 0 auto;
  flex-direction: column;
  justify-content: center;
}

.registration-brand {
  display: block;
  width: fit-content;
  margin: 0 0 1.25rem -1.5rem;
  border-radius: var(--ui-radius-md);
}

.registration-brand-frame {
  display: block;
  width: min(100%, 20rem);
  height: 4.1rem;
  overflow: hidden;
}

.registration-brand-logo {
  display: block;
  width: 100%;
  height: auto;
  transform: translateY(-35%);
}

.registration-grid {
  display: grid;
  grid-template-columns: minmax(0, 0.85fr) minmax(0, 1.15fr);
  gap: 2rem;
  align-items: start;
}

.registration-intro {
  padding: 0 0.5rem;
}

.registration-eyebrow {
  margin: 0;
  color: var(--ui-accent-700);
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.registration-intro h1 {
  max-width: 28rem;
  margin: 0.75rem 0 0;
  color: var(--ui-text-strong);
  font-family: var(--font-sans);
  font-size: clamp(2rem, 4vw, 3rem);
  font-weight: 700;
  letter-spacing: -0.03em;
  line-height: 1.1;
}

.registration-intro-copy {
  max-width: 31rem;
  margin: 1.25rem 0 0;
  color: var(--ui-text-muted);
  font-size: 1rem;
  line-height: 1.7;
}

.registration-panels {
  display: grid;
  gap: 0.75rem;
  margin-top: 2rem;
}

.registration-panel {
  padding: 1rem 1.125rem;
  border: 1px solid var(--ui-border);
  border-radius: var(--ui-radius-lg);
  background: var(--ui-surface);
  box-shadow: var(--ui-shadow-card);
}

.registration-panel p {
  margin: 0;
  color: var(--ui-text);
  font-size: 0.875rem;
  font-weight: 700;
}

.registration-panel span {
  display: block;
  margin-top: 0.35rem;
  color: var(--ui-text-muted);
  font-size: 0.875rem;
  line-height: 1.5;
}

.registration-sign-in {
  display: inline-flex;
  margin-top: 2rem;
  padding: 0.7rem 1rem;
  border: 1px solid var(--ui-border-strong);
  border-radius: var(--ui-radius-md);
  color: var(--ui-link);
  background: var(--ui-surface);
  font-size: 0.875rem;
  font-weight: 700;
  text-decoration: none;
}

.registration-sign-in:hover {
  border-color: var(--ui-focus);
  background: var(--ui-surface-subtle);
}

.registration-card {
  padding: clamp(1.5rem, 4vw, 2.5rem);
  border: 1px solid var(--ui-border);
  border-radius: var(--ui-radius-xl);
  background: var(--ui-surface);
  box-shadow: var(--ui-shadow-elevated);
}

.registration-heading h2 {
  margin: 0.6rem 0 0;
  color: var(--ui-text-strong);
  font-size: clamp(1.5rem, 3vw, 2rem);
  font-weight: 700;
  letter-spacing: -0.02em;
}

.registration-error {
  margin-top: 1.5rem;
  padding: 0.875rem 1rem;
  border: 1px solid var(--ui-danger);
  border-radius: var(--ui-radius-md);
  color: var(--ui-danger);
  background: var(--ui-surface-subtle);
  font-size: 0.875rem;
  line-height: 1.5;
}

.registration-form {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1.125rem 1rem;
  margin-top: 1.75rem;
}

.registration-field {
  display: grid;
  gap: 0.5rem;
}

.registration-field-wide {
  grid-column: 1 / -1;
}

.registration-field span {
  color: var(--ui-text);
  font-size: 0.875rem;
  font-weight: 700;
}

.registration-field input {
  width: 100%;
  min-height: 2.75rem;
  padding: 0.7rem 0.9rem;
  border: 1px solid var(--ui-border-strong);
  border-radius: var(--ui-radius-md);
  color: var(--ui-text);
  background: var(--ui-surface);
  outline: none;
}

.registration-field input::placeholder {
  color: var(--ui-text-subtle);
  opacity: 1;
}

.registration-field input:focus {
  border-color: var(--ui-focus);
  box-shadow: 0 0 0 4px rgba(13, 148, 136, 0.14);
}

.registration-actions {
  display: flex;
  grid-column: 1 / -1;
  justify-content: flex-end;
  padding-top: 0.25rem;
}

.registration-submit {
  min-height: 3rem;
  padding: 0.75rem 1.5rem;
  border: 0;
  border-radius: var(--ui-radius-md);
  color: #ffffff;
  background: var(--ui-brand-900);
  box-shadow: 0 8px 16px rgba(15, 27, 45, 0.14);
  font-size: 0.875rem;
  font-weight: 700;
}

.registration-submit:hover:not(:disabled) {
  background: var(--ui-brand-800);
  transform: translateY(-1px);
}

.registration-submit:focus-visible,
.registration-sign-in:focus-visible,
.registration-brand:focus-visible {
  outline: 2px solid var(--ui-focus);
  outline-offset: 3px;
}

.registration-submit:disabled {
  cursor: not-allowed;
  opacity: 0.7;
}

@media (min-width: 48.0625rem) {
  .registration-card {
    padding: 1.75rem 2rem;
  }

  .registration-form {
    gap: 0.75rem 1rem;
    margin-top: 1.5rem;
  }
}

@media (max-width: 48rem) {
  .registration-container {
    min-height: 0;
  }

  .registration-grid {
    grid-template-columns: 1fr;
    gap: 1rem;
  }

  .registration-intro {
    padding: 0.5rem 0;
  }

  .registration-brand {
    margin-left: 0;
  }

  .registration-panels {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 36rem) {
  .registration-page {
    padding: 1.5rem 1rem 2.5rem;
  }

  .registration-brand-frame {
    width: min(100%, 18rem);
    height: 3.7rem;
  }

  .registration-panels,
  .registration-form {
    grid-template-columns: 1fr;
  }

  .registration-field-wide,
  .registration-actions {
    grid-column: auto;
  }

  .registration-actions {
    justify-content: stretch;
  }

  .registration-submit {
    width: 100%;
  }
}
</style>
