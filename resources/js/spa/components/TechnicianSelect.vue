<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    modelValue: {
        type: [String, Number],
        default: '',
    },
    options: {
        type: Array,
        default: () => [],
    },
    placeholder: {
        type: String,
        default: 'Select an option',
    },
    required: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue']);
const open = ref(false);
const root = ref(null);

const optionValue = (option) => typeof option === 'object' ? option.value : option;
const optionLabel = (option) => typeof option === 'object' ? option.label : option;
const selectedOption = () => props.options.find((option) => String(optionValue(option)) === String(props.modelValue));

const selectOption = (option) => {
    emit('update:modelValue', optionValue(option));
    open.value = false;
};

const closeOnOutsideClick = (event) => {
    if (!root.value?.contains(event.target)) {
        open.value = false;
    }
};

onMounted(() => document.addEventListener('click', closeOnOutsideClick));
onBeforeUnmount(() => document.removeEventListener('click', closeOnOutsideClick));
</script>

<template>
  <div ref="root" class="technician-select-wrapper">
    <button
      type="button"
      class="technician-select-trigger"
      :aria-expanded="open"
      aria-haspopup="listbox"
      :aria-required="required"
      @click.stop="open = !open"
    >
      <span class="truncate">{{ selectedOption() ? optionLabel(selectedOption()) : placeholder }}</span>
      <i class="fas fa-chevron-down ml-2 shrink-0 text-[0.65rem]" aria-hidden="true"></i>
    </button>
    <div v-if="open" class="technician-select-menu" role="listbox">
      <button
        v-for="option in options"
        :key="String(optionValue(option))"
        type="button"
        class="technician-select-option"
        :class="String(optionValue(option)) === String(modelValue) ? 'technician-select-option-active' : ''"
        role="option"
        :aria-selected="String(optionValue(option)) === String(modelValue)"
        @click="selectOption(option)"
      >
        {{ optionLabel(option) }}
      </button>
    </div>
  </div>
</template>
