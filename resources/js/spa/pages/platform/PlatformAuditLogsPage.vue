<script setup>
import { onMounted, ref } from 'vue';
import PlatformLayout from '../../layouts/PlatformLayout.vue';
import { ensurePlatformAdmin } from '../../composables/auth';
import { navigate } from '../../navigation';

const loading = ref(true);
const forbidden = ref(false);

const ensureAccess = async () => {
    const auth = await ensurePlatformAdmin();
    if (!auth.ok) {
        if (auth.reason === 'unauthenticated') {
            navigate('/login', { replace: true });
            return;
        }
        forbidden.value = true;
    }
    loading.value = false;
};

onMounted(ensureAccess);
</script>

<template>
  <PlatformLayout title="Audit Logs">
    <section v-if="loading" class="platform-card platform-state" role="status">
      <span class="platform-spinner" aria-hidden="true"></span>
      <span>Checking platform access...</span>
    </section>
    <section v-else-if="forbidden" class="platform-card platform-state platform-state-danger" role="alert">
      You do not have access to this page.
    </section>
    <template v-else>
    <section class="platform-page-heading">
      <div>
        <p class="platform-eyebrow">PLATFORM ADMIN</p>
        <h1>Audit Logs</h1>
        <p>Review administrative changes and important platform activity.</p>
      </div>
    </section>

    <section class="platform-card platform-state">
      <span class="platform-state-icon" aria-hidden="true"><i class="fas fa-shield-halved"></i></span>
      <h2>Platform audit logs are not available</h2>
      <p class="max-w-2xl">
        The existing audit-log API is scoped to a shop and authorizes shop administrators only.
        It does not provide a platform-admin audit feed, so this page does not request or expose shop audit records.
      </p>
    </section>
    </template>
  </PlatformLayout>
</template>
