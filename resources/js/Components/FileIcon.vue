<script setup>
defineProps({
    type: { type: String, default: 'file' },
});
defineOptions({ inheritAttrs: false });
</script>

<template>
    <svg v-if="type === 'pdf'" v-bind="$attrs" viewBox="0 0 24 24" fill="none" class="text-red-500">
        <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M14 2v6h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        <text x="7" y="17" font-size="5.5" font-weight="bold" fill="currentColor">PDF</text>
    </svg>
    <svg v-else-if="type === 'image'" v-bind="$attrs" viewBox="0 0 24 24" fill="none" class="text-purple-500">
        <rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="1.5"/>
        <circle cx="8.5" cy="8.5" r="1.5" fill="currentColor"/>
        <path d="M21 15l-5-5L5 21" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    <svg v-else-if="type === 'word'" v-bind="$attrs" viewBox="0 0 24 24" fill="none" class="text-blue-600">
        <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M14 2v6h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        <text x="6.5" y="17" font-size="5" font-weight="bold" fill="currentColor">W</text>
    </svg>
    <svg v-else-if="type === 'excel'" v-bind="$attrs" viewBox="0 0 24 24" fill="none" class="text-green-600">
        <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M14 2v6h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        <text x="6.5" y="17" font-size="5" font-weight="bold" fill="currentColor">X</text>
    </svg>
    <svg v-else-if="type === 'ppt'" v-bind="$attrs" viewBox="0 0 24 24" fill="none" class="text-orange-500">
        <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M14 2v6h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        <text x="6.5" y="17" font-size="5" font-weight="bold" fill="currentColor">P</text>
    </svg>
    <svg v-else-if="type === 'text'" v-bind="$attrs" viewBox="0 0 24 24" fill="none" class="text-gray-500 dark:text-gray-400">
        <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M14 2v6h6M8 13h8M8 17h5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    <svg v-else-if="type === 'archive'" v-bind="$attrs" viewBox="0 0 24 24" fill="none" class="text-amber-500">
        <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M14 2v6h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        <rect x="9" y="11" width="6" height="8" rx="1" stroke="currentColor" stroke-width="1.2"/>
        <path d="M12 11v-1M12 9V8M12 7V6" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
        <rect x="10.5" y="13" width="3" height="2" rx="0.5" fill="currentColor" opacity="0.5"/>
    </svg>
    <svg v-else-if="type === 'video'" v-bind="$attrs" viewBox="0 0 24 24" fill="none" class="text-purple-500">
        <rect x="2" y="4" width="20" height="16" rx="2" stroke="currentColor" stroke-width="1.5"/>
        <path d="M10 9l5 3-5 3V9z" fill="currentColor" opacity="0.7"/>
    </svg>
    <svg v-else-if="type === 'audio'" v-bind="$attrs" viewBox="0 0 24 24" fill="none" class="text-pink-500">
        <path d="M9 18V5l12-2v13" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        <circle cx="6" cy="18" r="3" stroke="currentColor" stroke-width="1.5"/>
        <circle cx="18" cy="16" r="3" stroke="currentColor" stroke-width="1.5"/>
    </svg>
    <svg v-else v-bind="$attrs" viewBox="0 0 24 24" fill="none" class="text-gray-400 dark:text-gray-500">
        <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M14 2v6h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
</template>
