<template>
    <div class="word-viewer-host flex h-full flex-col bg-white dark:bg-gray-900">
        <!-- Toolbar -->
        <div class="flex items-center border-b border-gray-200 bg-gray-50 px-3 py-1.5 dark:border-gray-700 dark:bg-gray-800">
            <div class="flex items-center gap-2">
                <svg class="h-4 w-4 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Word Document</span>
                <span class="rounded bg-blue-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">.{{ extension }}</span>
            </div>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="flex flex-1 items-center justify-center">
            <div class="text-center">
                <div class="mx-auto h-8 w-8 border-2 border-blue-300 border-t-blue-600 rounded-full animate-spin"></div>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Opening document…</p>
            </div>
        </div>

        <!-- Error -->
        <div v-else-if="error" class="flex flex-1 items-center justify-center p-6">
            <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
                {{ error }}
            </div>
        </div>

        <!-- docx-preview renders here -->
        <div v-show="!loading && !error" ref="viewerEl" class="docx-viewer flex-1 overflow-auto"></div>
    </div>
</template>

<script setup>
import { ref, onMounted, nextTick } from 'vue';
import axios from 'axios';

const props = defineProps({
    fileId: { type: Number, required: true },
});

const loading = ref(true);
const error = ref('');
const extension = ref('docx');
const viewerEl = ref(null);

async function loadDocxPreview() {
    loading.value = true;
    error.value = '';

    try {
        const res = await axios.get(`/api/files/${props.fileId}/preview`, {
            responseType: 'arraybuffer',
        });

        const ext = (res.headers['content-disposition'] || '').match(/\.(\w+)/)?.[1] || 'docx';
        extension.value = ext.toLowerCase();

        const { renderAsync } = await import('docx-preview');

        await nextTick();

        if (viewerEl.value) {
            viewerEl.value.innerHTML = '';
            await renderAsync(res.data, viewerEl.value, null, {
                className: 'docx-rendered',
                inWrapper: true,
                ignoreWidth: false,
                ignoreHeight: false,
                ignoreFonts: false,
                breakPages: true,
                renderHeaders: true,
                renderFooters: true,
                renderFootnotes: true,
                renderEndnotes: true,
            });
        }
    } catch (e) {
        error.value = 'Could not render document. ' + (e.message || '');
    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    loadDocxPreview();
});
</script>

<style scoped>
.docx-viewer {
    background: #525659;
    padding: 20px;
}
.docx-viewer :deep(.docx-wrapper) {
    background: #525659;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 20px;
    padding: 20px 0;
}
.docx-viewer :deep(.docx-wrapper > section.docx) {
    background: #fff;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.3);
    margin: 0 auto;
    min-height: 500px;
}
.dark .docx-viewer {
    background: #1a1a2e;
}
.dark .docx-viewer :deep(.docx-wrapper) {
    background: #1a1a2e;
}
</style>
