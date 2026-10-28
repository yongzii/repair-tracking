<script>
const asyncComponentCache = new WeakMap();
</script>

<script setup>
import { computed, defineAsyncComponent, defineComponent, h, onMounted, watch } from 'vue';
import { useNavigation } from './navigation';
import { resolveRoute } from './routes';
import { hydrateAuth } from './composables/auth';
import { appConfig } from './config';

const RouteLoadingState = defineComponent({
    setup() {
        return () => h('main', {
            class: 'mx-auto flex min-h-screen max-w-3xl items-center justify-center px-6 text-center text-slate-600',
            role: 'status',
            'aria-live': 'polite',
        }, 'Loading page...');
    },
});

const RouteErrorState = defineComponent({
    setup() {
        return () => h('main', {
            class: 'mx-auto flex min-h-screen max-w-3xl flex-col items-center justify-center gap-4 px-6 text-center',
            role: 'alert',
        }, [
            h('p', { class: 'text-slate-700' }, 'This page could not be loaded. Check your connection and try again.'),
            h('button', {
                class: 'rounded-lg bg-slate-900 px-4 py-2 font-semibold text-white hover:bg-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500',
                type: 'button',
                onClick: () => window.location.reload(),
            }, 'Reload page'),
        ]);
    },
});

const { location } = useNavigation();

const resolved = computed(() => resolveRoute(location.path));
const pageComponent = computed(() => {
    const loader = resolved.value.component;
    let component = asyncComponentCache.get(loader);

    if (!component) {
        component = defineAsyncComponent({
            loader,
            loadingComponent: RouteLoadingState,
            errorComponent: RouteErrorState,
            delay: 200,
            timeout: 30_000,
        });
        asyncComponentCache.set(loader, component);
    }

    return component;
});
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
