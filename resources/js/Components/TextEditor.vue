<template>
    <div class="text-editor-host flex h-full flex-col bg-white dark:bg-gray-900">
        <!-- Toolbar -->
        <div class="flex items-center justify-between border-b border-gray-200 bg-gray-50 px-3 py-1.5 dark:border-gray-700 dark:bg-gray-800">
            <div class="flex items-center gap-2">
                <svg class="h-4 w-4 text-gray-500 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ docType === 'word' ? 'Word Document' : 'Text File' }}</span>
                <span v-if="extension" class="rounded bg-gray-200 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-gray-600 dark:bg-gray-700 dark:text-gray-400">.{{ extension }}</span>
            </div>
            <div class="flex items-center gap-2">
                <span v-if="dirty" class="text-[10px] text-amber-600 dark:text-amber-400">Unsaved changes</span>
                <span v-else-if="saved" class="text-[10px] text-green-600 dark:text-green-400">Saved ✓</span>
                <button v-if="canEdit" @click="save" :disabled="saving || !dirty"
                    class="flex items-center gap-1 rounded-md px-3 py-1 text-xs font-medium transition"
                    :class="dirty
                        ? 'bg-agri-600 text-white hover:bg-agri-700 shadow-sm'
                        : 'bg-gray-200 text-gray-400 cursor-not-allowed dark:bg-gray-700 dark:text-gray-500'">
                    <svg v-if="saving" class="h-3 w-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                    <svg v-else class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4" /></svg>
                    {{ saving ? 'Saving…' : 'Save' }}
                </button>
            </div>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="flex flex-1 items-center justify-center">
            <div class="text-center">
                <div class="mx-auto h-8 w-8 border-2 border-agri-300 border-t-agri-600 rounded-full animate-spin"></div>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Opening document…</p>
            </div>
        </div>

        <!-- Error -->
        <div v-else-if="error" class="flex flex-1 items-center justify-center p-6">
            <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
                {{ error }}
            </div>
        </div>

        <!-- Editor -->
        <div v-else class="flex flex-1 flex-col overflow-hidden">
            <div class="flex flex-1 overflow-hidden">
                <!-- Line numbers -->
                <div ref="lineNumbersEl" class="line-numbers select-none overflow-hidden border-r border-gray-200 bg-gray-50 px-2 py-3 text-right font-mono text-[11px] leading-[1.65] text-gray-400 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-600" style="min-width: 40px;">
                    <div v-for="n in lineCount" :key="n">{{ n }}</div>
                </div>
                <!-- Textarea -->
                <textarea
                    ref="textareaEl"
                    v-model="content"
                    :readonly="!canEdit"
                    @input="markDirty"
                    @scroll="syncScroll"
                    class="flex-1 resize-none border-0 bg-white p-3 font-mono text-[13px] leading-[1.65] text-gray-800 outline-none focus:ring-0 dark:bg-gray-900 dark:text-gray-200"
                    :class="{ 'cursor-default': !canEdit }"
                    spellcheck="false"
                    wrap="off"
                ></textarea>
            </div>

            <!-- Status bar -->
            <div class="flex items-center justify-between border-t border-gray-200 bg-gray-50 px-3 py-1 text-[10px] text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">
                <span>Lines: {{ lineCount }} | Characters: {{ content.length }}</span>
                <span>{{ canEdit ? 'Editing' : 'Read only' }}</span>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue';
import axios from 'axios';

const props = defineProps({
    fileId: { type: Number, required: true },
    canEdit: { type: Boolean, default: true },
});

const emit = defineEmits(['saved']);

const content = ref('');
const originalContent = ref('');
const docType = ref('text');
const extension = ref('');
const loading = ref(true);
const error = ref('');
const dirty = ref(false);
const saving = ref(false);
const saved = ref(false);
const textareaEl = ref(null);
const lineNumbersEl = ref(null);

const lineCount = computed(() => {
    return content.value.split('\n').length;
});

function markDirty() {
    dirty.value = content.value !== originalContent.value;
    saved.value = false;
}

function syncScroll() {
    if (textareaEl.value && lineNumbersEl.value) {
        lineNumbersEl.value.scrollTop = textareaEl.value.scrollTop;
    }
}

async function loadContent() {
    loading.value = true;
    error.value = '';

    try {
        const res = await axios.get(`/api/files/${props.fileId}/document`);
        content.value = res.data.content || '';
        originalContent.value = content.value;
        docType.value = res.data.type || 'text';
        extension.value = res.data.extension || '';
    } catch (e) {
        error.value = e.response?.data?.error || 'Could not load document content.';
    } finally {
        loading.value = false;
    }
}

async function save() {
    if (!dirty.value || saving.value) return;

    saving.value = true;
    try {
        await axios.put(`/api/files/${props.fileId}/document`, {
            content: content.value,
        });
        originalContent.value = content.value;
        dirty.value = false;
        saved.value = true;
        emit('saved');
    } catch (e) {
        alert(e.response?.data?.error || 'Failed to save document.');
    } finally {
        saving.value = false;
    }
}

onMounted(() => {
    loadContent();
});
</script>

<style scoped>
.text-editor-host {
    font-family: 'Inter', system-ui, sans-serif;
}
.text-editor-host textarea {
    tab-size: 4;
    -moz-tab-size: 4;
}
.text-editor-host textarea::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}
.text-editor-host textarea::-webkit-scrollbar-track {
    background: transparent;
}
.text-editor-host textarea::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}
.dark .text-editor-host textarea::-webkit-scrollbar-thumb {
    background: #475569;
}
.line-numbers {
    overflow-y: hidden;
    white-space: nowrap;
}
</style>
