<script setup>
import { nextTick, ref, watch, computed } from 'vue';
import FileIcon from './FileIcon.vue';

const props = defineProps({
    folder: { type: Object, required: true },
    depth: { type: Number, default: 0 },
    expandedFolders: { type: Object, required: true },
    editingItem: { type: Object, default: null },
    editingName: { type: String, default: '' },
    creatingInFolder: { default: null },
    newFolderName: { type: String, default: '' },
    selectedFile: { type: [Number, null], default: null },
    selectedFolderId: { type: [Number, null], default: null },
    dropTarget: { type: [Number, null], default: null },
});

const emit = defineEmits([
    'toggle', 'select-file', 'context-folder', 'context-file',
    'update:editing-name', 'submit-rename', 'cancel-rename',
    'update:new-folder-name', 'submit-new-folder', 'cancel-new-folder',
    'drag-start', 'drag-over', 'drag-leave', 'drop', 'drag-end',
]);

const editInput = ref(null);
const newFolderInput = ref(null);

const isEditing = (type, id) => props.editingItem && props.editingItem.type === type && props.editingItem.id === id;
const isExpanded = computed(() => props.expandedFolders.has(props.folder.id));
const isSelected = computed(() => props.selectedFolderId === props.folder.id);
const isDragOver = computed(() => props.dropTarget === props.folder.id);

watch(() => props.editingItem, (val) => {
    if (val && val.type === 'folder' && val.id === props.folder.id) {
        nextTick(() => editInput.value?.focus());
    }
});

watch(() => props.creatingInFolder, (val) => {
    if (val === props.folder.id) {
        nextTick(() => {
            nextTick(() => newFolderInput.value?.focus());
        });
    }
});

function fileIcon(mimeType) {
    if (!mimeType) return 'file';
    if (mimeType.startsWith('image/')) return 'image';
    if (mimeType === 'application/pdf') return 'pdf';
    if (mimeType.includes('zip') || mimeType.includes('compressed') || mimeType.includes('archive') || mimeType.includes('rar') || mimeType.includes('tar') || mimeType.includes('7z')) return 'archive';
    if (mimeType.startsWith('video/')) return 'video';
    if (mimeType.startsWith('audio/')) return 'audio';
    if (mimeType.includes('word') || mimeType.includes('document')) return 'word';
    if (mimeType.includes('sheet') || mimeType.includes('excel')) return 'excel';
    if (mimeType.includes('presentation') || mimeType.includes('powerpoint')) return 'ppt';
    if (mimeType.startsWith('text/')) return 'text';
    return 'file';
}

function fileStatusColor(status) {
    switch (status) {
        case 'completed': return 'text-green-500';
        case 'pending': return 'text-yellow-500';
        case 'processing': return 'text-blue-500';
        case 'failed': return 'text-red-500';
        default: return 'text-gray-400';
    }
}
</script>

<template>
    <div>
        <!-- Folder row -->
        <div @click="emit('toggle', folder)"
             @contextmenu="emit('context-folder', $event, folder)"
             draggable="true"
             @dragstart="emit('drag-start', $event, 'folder', folder)"
             @dragover.stop="emit('drag-over', $event, folder.id)"
             @dragleave="emit('drag-leave')"
             @drop.stop="emit('drop', $event, folder.id)"
             @dragend="emit('drag-end')"
             class="group flex cursor-pointer items-center gap-1 rounded px-2 py-1 text-xs transition"
             :class="{
                 'bg-agri-100 dark:bg-agri-900/40': isSelected,
                 'hover:bg-gray-100 dark:hover:bg-gray-800/60': !isSelected && !isDragOver,
                 'bg-blue-100 dark:bg-blue-900/30 ring-1 ring-blue-400': isDragOver,
             }"
             :style="{ paddingLeft: (depth * 12 + 8) + 'px' }">
            <!-- Expand arrow -->
            <svg class="h-3 w-3 shrink-0 text-gray-400 transition-transform duration-150"
                 :class="{ 'rotate-90': isExpanded }"
                 fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/>
            </svg>

            <!-- Folder icon -->
            <svg class="h-4 w-4 shrink-0" :class="isExpanded ? 'text-yellow-500' : 'text-yellow-600'" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path v-if="isExpanded" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z" />
                <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
            </svg>

            <!-- Name or edit input -->
            <template v-if="isEditing('folder', folder.id)">
                <input ref="editInput" :value="editingName"
                       @input="emit('update:editing-name', $event.target.value)"
                       @keydown.enter.prevent="emit('submit-rename')"
                       @keydown.escape.prevent="emit('cancel-rename')"
                       @click.stop
                       autofocus
                       class="flex-1 rounded border border-agri-400 bg-white px-1.5 py-0.5 text-xs text-gray-900 outline-none focus:ring-1 focus:ring-agri-500 dark:border-agri-600 dark:bg-gray-800 dark:text-white" />
            </template>
            <span v-else class="flex-1 truncate" :class="isSelected ? 'text-agri-800 dark:text-agri-200' : 'text-gray-700 dark:text-gray-300'">{{ folder.name }}</span>
        </div>

        <!-- Children (when expanded) -->
        <div v-if="isExpanded">
            <!-- New folder input inside this folder -->
            <div v-if="creatingInFolder === folder.id" class="flex items-center gap-1 py-1" :style="{ paddingLeft: ((depth + 1) * 12 + 20) + 'px' }">
                <svg class="h-4 w-4 shrink-0 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" /></svg>
                <input ref="newFolderInput" :value="newFolderName"
                       @input="emit('update:new-folder-name', $event.target.value)"
                       @keydown.enter.prevent="emit('submit-new-folder')"
                       @keydown.escape.prevent="emit('cancel-new-folder')"
                       class="flex-1 rounded border border-agri-400 bg-white px-1.5 py-0.5 text-xs text-gray-900 outline-none focus:ring-1 focus:ring-agri-500 dark:border-agri-600 dark:bg-gray-800 dark:text-white"
                       placeholder="Folder name..." />
                <button @click.stop="emit('submit-new-folder')" class="rounded p-0.5 text-green-500 hover:bg-green-50 dark:hover:bg-green-900/20" title="Create">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                </button>
                <button @click.stop="emit('cancel-new-folder')" class="rounded p-0.5 text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20" title="Cancel">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>

            <!-- Child folders (recursive) -->
            <TreeNode v-for="child in folder.children" :key="'f-' + child.id"
                      :folder="child" :depth="depth + 1"
                      :expanded-folders="expandedFolders"
                      :editing-item="editingItem"
                      :editing-name="editingName"
                      :creating-in-folder="creatingInFolder"
                      :new-folder-name="newFolderName"
                      :selected-file="selectedFile"
                      :selected-folder-id="selectedFolderId"
                      :drop-target="dropTarget"
                      @toggle="emit('toggle', $event)"
                      @select-file="emit('select-file', $event)"
                      @context-folder="(e, f) => emit('context-folder', e, f)"
                      @context-file="(e, f) => emit('context-file', e, f)"
                      @update:editing-name="emit('update:editing-name', $event)"
                      @submit-rename="emit('submit-rename')"
                      @cancel-rename="emit('cancel-rename')"
                      @update:new-folder-name="emit('update:new-folder-name', $event)"
                      @submit-new-folder="emit('submit-new-folder')"
                      @cancel-new-folder="emit('cancel-new-folder')"
                      @drag-start="(e, t, i) => emit('drag-start', e, t, i)"
                      @drag-over="(e, id) => emit('drag-over', e, id)"
                      @drag-leave="emit('drag-leave')"
                      @drop="(e, id) => emit('drop', e, id)"
                      @drag-end="emit('drag-end')" />

            <!-- Files in this folder -->
            <div v-for="file in folder.files" :key="'file-' + file.id"
                 draggable="true"
                 @dragstart="emit('drag-start', $event, 'file', file)"
                 @dragend="emit('drag-end')"
                 @click="emit('select-file', file)"
                 @contextmenu="emit('context-file', $event, file)"
                 class="group flex cursor-pointer items-center gap-1.5 rounded py-1 text-xs transition"
                 :class="selectedFile === file.id ? 'bg-agri-100 text-agri-800 dark:bg-agri-900/40 dark:text-agri-200' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800/60'"
                 :style="{ paddingLeft: ((depth + 1) * 12 + 20) + 'px' }">
                <template v-if="editingItem && editingItem.type === 'file' && editingItem.id === file.id">
                    <FileIcon :type="fileIcon(file.mime_type)" class="h-4 w-4 shrink-0" />
                    <input :value="editingName"
                           @input="emit('update:editing-name', $event.target.value)"
                           @keydown.enter.prevent="emit('submit-rename')"
                           @keydown.escape.prevent="emit('cancel-rename')"
                           @click.stop
                           autofocus
                           class="flex-1 rounded border border-agri-400 bg-white px-1.5 py-0.5 text-xs text-gray-900 outline-none focus:ring-1 focus:ring-agri-500 dark:border-agri-600 dark:bg-gray-800 dark:text-white" />
                </template>
                <template v-else>
                    <FileIcon :type="fileIcon(file.mime_type)" class="h-4 w-4 shrink-0" />
                    <span class="flex-1 truncate">{{ file.original_name }}</span>
                    <span :class="fileStatusColor(file.status)" class="mr-2 text-[10px]">●</span>
                </template>
            </div>

            <!-- Empty folder -->
            <div v-if="!folder.children?.length && !folder.files?.length && creatingInFolder !== folder.id"
                 class="py-1 text-[10px] italic text-gray-400 dark:text-gray-600"
                 :style="{ paddingLeft: ((depth + 1) * 12 + 20) + 'px' }">
                Empty
            </div>
        </div>
    </div>
</template>

<script>
export default {
    name: 'TreeNode',
};
</script>
