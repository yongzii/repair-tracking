<script setup>
import { navigate } from '../navigation';

const props = defineProps({
    to: {
        type: String,
        required: true,
    },
});

const onClick = (event) => {
    if (event.defaultPrevented) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (event.button !== 0) return;

    event.preventDefault();
    navigate(props.to);
};
</script>

<template>
  <a :href="to" v-bind="$attrs" @click="onClick">
    <slot />
  </a>
</template>
