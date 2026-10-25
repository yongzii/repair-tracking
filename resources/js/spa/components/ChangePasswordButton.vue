<script setup>
import { reactive, ref } from 'vue';

const isOpen = ref(false);
const isSubmitting = ref(false);
const errorMessage = ref('');
const successMessage = ref('');

const form = reactive({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const close = () => {
    if (isSubmitting.value) return;
    isOpen.value = false;
    errorMessage.value = '';
    successMessage.value = '';
    form.current_password = '';
    form.password = '';
    form.password_confirmation = '';
};

const submit = async () => {
    isSubmitting.value = true;
    errorMessage.value = '';
    successMessage.value = '';

    try {
        const { data } = await window.axios.post('/api/auth/password', form);
        successMessage.value = data.message || 'Password changed successfully.';
        form.current_password = '';
        form.password = '';
        form.password_confirmation = '';
    } catch (error) {
        const firstError = error?.response?.data?.errors
            ? Object.values(error.response.data.errors)[0]?.[0]
            : null;
        errorMessage.value = firstError || error?.response?.data?.message || 'Unable to change password.';
    } finally {
        isSubmitting.value = false;
    }
};
</script>

<template>
  <button
    type="button"
    class="account-security-trigger mt-3 w-full rounded-lg border px-3 py-2 text-left text-xs font-bold"
    @click="isOpen = true"
  >
    <i class="fas fa-key mr-2" aria-hidden="true"></i>
    Change Password
  </button>

  <div
    v-if="isOpen"
    class="account-dialog-backdrop fixed inset-0 z-[100] flex items-center justify-center px-4 py-6 backdrop-blur-sm"
    @click.self="close"
    @keydown.esc="close"
  >
    <section
      class="account-dialog w-full max-w-md rounded-2xl p-6"
      role="dialog"
      aria-modal="true"
      aria-labelledby="change-password-title"
    >
      <div class="mb-5 flex items-start justify-between gap-4">
        <div>
          <p class="account-dialog-accent text-xs font-bold uppercase tracking-[0.16em]">Account Security</p>
          <h2 id="change-password-title" class="account-dialog-title mt-1 text-xl font-bold">Change Password</h2>
        </div>
        <button type="button" class="account-dialog-close rounded-lg border px-3 py-1.5 text-sm font-bold" :disabled="isSubmitting" @click="close">Close</button>
      </div>

      <p class="account-dialog-copy mb-4 text-sm">Enter your current password, then choose a new password with at least 8 characters.</p>
      <div v-if="errorMessage" class="account-dialog-error mb-4 rounded-lg border p-3 text-sm font-semibold">{{ errorMessage }}</div>
      <div v-if="successMessage" class="account-dialog-success mb-4 rounded-lg border p-3 text-sm font-semibold">{{ successMessage }}</div>

      <form class="space-y-4" @submit.prevent="submit">
        <label class="block space-y-1.5">
          <span class="account-dialog-label text-sm font-semibold">Current password</span>
          <input v-model="form.current_password" type="password" autocomplete="current-password" class="w-full rounded-lg border border-slate-300 px-3 py-2.5" required :disabled="isSubmitting" />
        </label>
        <label class="block space-y-1.5">
          <span class="account-dialog-label text-sm font-semibold">New password</span>
          <input v-model="form.password" type="password" autocomplete="new-password" minlength="8" class="w-full rounded-lg border border-slate-300 px-3 py-2.5" required :disabled="isSubmitting" />
        </label>
        <label class="block space-y-1.5">
          <span class="account-dialog-label text-sm font-semibold">Confirm new password</span>
          <input v-model="form.password_confirmation" type="password" autocomplete="new-password" minlength="8" class="w-full rounded-lg border border-slate-300 px-3 py-2.5" required :disabled="isSubmitting" />
        </label>
        <button type="submit" class="account-dialog-submit w-full rounded-lg px-4 py-3 font-bold" :disabled="isSubmitting">
          {{ isSubmitting ? 'Changing password...' : 'Save New Password' }}
        </button>
      </form>
    </section>
  </div>
</template>

<style scoped>
.account-security-trigger {
    border-color: rgba(255, 255, 255, 0.16);
    color: #ffffff;
}

.account-security-trigger:hover {
    background: rgba(255, 255, 255, 0.1);
}

.account-dialog-backdrop {
    background: rgba(6, 26, 43, 0.62);
}

.account-dialog {
    border: 1px solid var(--ui-border);
    background: var(--ui-surface);
    box-shadow: var(--ui-shadow-elevated);
}

.account-dialog-accent {
    color: var(--ui-accent-700);
}

.account-dialog-title,
.account-dialog-label {
    color: var(--ui-text-strong);
}

.account-dialog-close {
    border-color: var(--ui-border);
    color: var(--ui-text-muted);
}

.account-dialog-close:hover {
    background: var(--ui-surface-muted);
    color: var(--ui-text);
}

.account-dialog-copy {
    color: var(--ui-text-muted);
}

.account-dialog-error {
    border-color: #fecdd3;
    background: #fff1f2;
    color: var(--ui-danger);
}

.account-dialog-success {
    border-color: #bbf7d0;
    background: #f0fdf4;
    color: var(--ui-success);
}

.account-dialog-submit {
    background: var(--ui-brand-900);
    color: #ffffff;
}

.account-dialog-submit:hover {
    background: var(--ui-brand-800);
}

.account-dialog-submit:disabled {
    cursor: not-allowed;
    opacity: 0.6;
}
</style>
