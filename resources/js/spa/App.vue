<script setup>
import { computed, onMounted, watch } from 'vue';
import { useNavigation } from './navigation';
import { resolveRoute } from './routes';
import { hydrateAuth } from './composables/auth';
import { appConfig } from './config';

const { location } = useNavigation();

const resolved = computed(() => resolveRoute(location.path));
const pageComponent = computed(() => resolved.value.component);
const pageParams = computed(() => resolved.value.params);

watch(
    () => resolved.value.title,
    (title) => {
        const appName = appConfig.name;
        document.title = title ? `${title} - ${appName}` : appName;
    },
    { immediate: true }
);

onMounted(() => {
    hydrateAuth();
});
</script>

<template>
  <component :is="pageComponent" :params="pageParams" :key="location.fullPath" />
</template>
