<script setup>
import { ref, reactive, onMounted, onUnmounted, nextTick, computed, watch } from 'vue';
import axios from 'axios';
import TreeNode from './TreeNode.vue';
import FileIcon from './FileIcon.vue';
import { useSettingsStore } from '@/stores/settings';

const tree = ref([]);
const rootFiles = ref([]);
const loading = ref(true);
const expandedFolders = reactive(new Set());

const settings = useSettingsStore();
const isPublicMode = computed(() => settings.visibility === 'public');

// Context menu
const contextMenu = ref({ show: false, x: 0, y: 0, type: '', item: null });

// Inline editing
const editingItem = ref(null);
const editingName = ref('');

// New folder inline input
const creatingInFolder = ref(null); // null = not creating, 'root' = root, number = folder id
const newFolderName = ref('');
const rootNewFolderInput = ref(null);

// Upload
const fileInput = ref(null);
const uploadTarget = ref(null);
const uploading = ref(false);

// Drag state
const dragItem = ref(null);
const dragType = ref(null);
const dropTarget = ref(null);

// Delete confirmation modal
const deleteModal = ref({ show: false, type: '', item: null });
const deleteConfirmText = ref('');
const deleteLoading = ref(false);
const deleteCanMatch = computed(() => deleteConfirmText.value === 'DELETE');

const emit = defineEmits(['file-selected', 'folder-selected']);
defineExpose({ fetchTree });
const selectedFile = ref(null);
const selectedFolder = ref(null);

// ── Data loading ──
async function fetchTree() {
    loading.value = true;
    try {
        const [treeRes, filesRes] = await Promise.all([
            axios.get('/api/folders/tree', { params: { visibility: settings.visibility } }),
            axios.get('/api/files/root', { params: { visibility: settings.visibility } }),
        ]);
        tree.value = treeRes.data;
        rootFiles.value = filesRes.data;
    } catch (e) {
        console.error('Failed to load file tree', e);
    } finally {
        loading.value = false;
    }
}

// Re-fetch tree when visibility changes
watch(() => settings.visibility, () => {
    selectedFile.value = null;
    selectedFolder.value = null;
    emit('file-selected', null);
    emit('folder-selected', null);
    fetchTree();
});

// ── Selection ──
function toggleFolder(folder) {
    if (expandedFolders.has(folder.id)) {
        expandedFolders.delete(folder.id);
    } else {
        expandedFolders.add(folder.id);
    }
    selectedFolder.value = folder;
    selectedFile.value = null;
    emit('folder-selected', folder);
}

function selectFile(file) {
    selectedFile.value = file.id;
    selectedFolder.value = null;
    emit('file-selected', file);
}

// ── Context menu ──
function showContext(e, type, item) {
    e.preventDefault();
    e.stopPropagation();
    contextMenu.value = { show: true, x: e.clientX, y: e.clientY, type, item };
}

function hideContext() {
    contextMenu.value.show = false;
}

// ── Rename ──
function startRename(type, item) {
    hideContext();
    editingItem.value = { type, id: item.id };
    editingName.value = type === 'folder' ? item.name : item.original_name;
}

async function submitRename() {
    if (!editingItem.value || !editingName.value.trim()) {
        editingItem.value = null;
        return;
    }
    try {
        if (editingItem.value.type === 'folder') {
            await axios.put(`/api/folders/${editingItem.value.id}`, { name: editingName.value.trim() });
        } else {
            await axios.patch(`/api/files/${editingItem.value.id}/rename`, { original_name: editingName.value.trim() });
        }
        await fetchTree();
    } catch (e) {
        console.error('Rename failed', e);
    }
    editingItem.value = null;
}

function cancelRename() {
    editingItem.value = null;
}

// ── Create folder ──
function startCreateFolder(parentId = null) {
    hideContext();
    newFolderName.value = '';
    if (parentId === null) {
        creatingInFolder.value = 'root';
        nextTick(() => rootNewFolderInput.value?.focus());
    } else {
        creatingInFolder.value = parentId;
        expandedFolders.add(parentId);
    }
}

async function submitNewFolder() {
    const name = newFolderName.value.trim();
    if (!name) {
        creatingInFolder.value = null;
        return;
    }
    const parentId = creatingInFolder.value === 'root' ? null : creatingInFolder.value;
    try {
        await axios.post('/api/folders', { name, parent_id: parentId, visibility: settings.visibility });
        creatingInFolder.value = null;
        newFolderName.value = '';
        await fetchTree();
    } catch (e) {
        console.error('Create folder failed', e);
    }
}

function cancelNewFolder() {
    creatingInFolder.value = null;
    newFolderName.value = '';
}

// ── Delete ──
function openDeleteModal(type, item) {
    hideContext();
    deleteModal.value = { show: true, type, item };
    deleteConfirmText.value = '';
    deleteLoading.value = false;
}

async function confirmDelete() {
    if (!deleteCanMatch.value) return;
    const { type, item } = deleteModal.value;
    deleteLoading.value = true;
    try {
        if (type === 'folder') {
            await axios.delete(`/api/folders/${item.id}`);
            if (selectedFolder.value?.id === item.id) {
                selectedFolder.value = null;
                emit('folder-selected', null);
            }
        } else {
            await axios.delete(`/api/files/${item.id}`);
            if (selectedFile.value === item.id) {
                selectedFile.value = null;
                emit('file-selected', null);
            }
        }
        deleteModal.value = { show: false, type: '', item: null };
        await fetchTree();
    } catch (e) {
        console.error('Delete failed', e);
    } finally {
        deleteLoading.value = false;
    }
}

function cancelDelete() {
    deleteModal.value = { show: false, type: '', item: null };
    deleteConfirmText.value = '';
}

function deleteSelected() {
    if (selectedFolder.value) {
        openDeleteModal('folder', selectedFolder.value);
    }
}

// ── Upload ──
function triggerUpload(folderId = null) {
    hideContext();
    uploadTarget.value = folderId;
    nextTick(() => fileInput.value?.click());
}

async function handleUpload(e) {
    const files = e.target.files;
    if (!files.length) return;
    await uploadFiles(files, uploadTarget.value);
    if (fileInput.value) fileInput.value.value = '';
}

// ── Drag & Drop ──
function onDragStart(e, type, item) {
    dragItem.value = item;
    dragType.value = type;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', '');
}

// Desktop file drop
const fileDragOver = ref(false);
let fileDragCounter = 0;

function isDesktopDrag(e) {
    return e.dataTransfer && e.dataTransfer.types && e.dataTransfer.types.includes('Files');
}

function onDragOver(e, folderId) {
    e.preventDefault();
    if (isDesktopDrag(e)) {
        e.dataTransfer.dropEffect = 'copy';
    } else {
        e.dataTransfer.dropEffect = 'move';
        dropTarget.value = folderId;
    }
}

function onDragLeave() {
    dropTarget.value = null;
}

function onRootDragEnter(e) {
    if (!isDesktopDrag(e)) return;
    fileDragCounter++;
    fileDragOver.value = true;
}

function onRootDragLeave(e) {
    if (!isDesktopDrag(e)) return;
    fileDragCounter--;
    if (fileDragCounter <= 0) {
        fileDragCounter = 0;
        fileDragOver.value = false;
    }
}

async function uploadFiles(files, targetFolderId) {
    if (!files || !files.length) return;
    uploading.value = true;
    const formData = new FormData();
    for (let i = 0; i < files.length; i++) {
        formData.append('files[]', files[i]);
    }
    if (targetFolderId) {
        formData.append('folder_id', targetFolderId);
    }
    formData.append('visibility', settings.visibility);
    try {
        await axios.post('/api/files', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (targetFolderId) expandedFolders.add(targetFolderId);
        await fetchTree();
    } catch (err) {
        console.error('Upload failed', err);
    } finally {
        uploading.value = false;
    }
}

async function onDrop(e, targetFolderId) {
    e.preventDefault();
    dropTarget.value = null;
    fileDragOver.value = false;
    fileDragCounter = 0;

    // Desktop files dropped
    if (e.dataTransfer.files && e.dataTransfer.files.length > 0 && !dragItem.value) {
        await uploadFiles(e.dataTransfer.files, targetFolderId ?? selectedFolder.value?.id ?? null);
        return;
    }

    if (!dragItem.value) return;

    try {
        if (dragType.value === 'file') {
            await axios.patch(`/api/files/${dragItem.value.id}/move`, { folder_id: targetFolderId });
        } else if (dragType.value === 'folder' && dragItem.value.id !== targetFolderId) {
            await axios.put(`/api/folders/${dragItem.value.id}`, {
                name: dragItem.value.name,
                parent_id: targetFolderId,
            });
        }
        if (targetFolderId) expandedFolders.add(targetFolderId);
        await fetchTree();
    } catch (e) {
        console.error('Move failed', e);
    }
    dragItem.value = null;
    dragType.value = null;
}

function onDragEnd() {
    dragItem.value = null;
    dragType.value = null;
    dropTarget.value = null;
}

// ── Helpers ──
function fileIconType(mimeType) {
    if (!mimeType) return 'file';
    if (mimeType.startsWith('image/')) return 'image';
    if (mimeType === 'application/pdf') return 'pdf';
    if (mimeType.includes('word') || mimeType.includes('document')) return 'word';
    if (mimeType.includes('sheet') || mimeType.includes('excel')) return 'excel';
    if (mimeType.includes('presentation') || mimeType.includes('powerpoint')) return 'ppt';
    if (mimeType.startsWith('text/')) return 'text';
    if (mimeType.includes('zip') || mimeType.includes('compressed') || mimeType.includes('tar') || mimeType.includes('gzip') || mimeType.includes('rar') || mimeType.includes('7z')) return 'archive';
    if (mimeType.startsWith('video/')) return 'video';
    if (mimeType.startsWith('audio/')) return 'audio';
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

const hasSelection = computed(() => selectedFile.value || selectedFolder.value);

function handleDocClick(e) {
    if (contextMenu.value.show) {
        hideContext();
    }
}

onMounted(() => {
    fetchTree();
    document.addEventListener('click', handleDocClick);
});

onUnmounted(() => {
    document.removeEventListener('click', handleDocClick);
});
</script>

<template>
    <div class="flex h-full flex-col relative"
         @dragenter="onRootDragEnter"
         @dragleave="onRootDragLeave"
         @dragover.prevent
         @drop="onDrop($event, selectedFolder?.id ?? null)">
        <!-- Desktop file drop overlay -->
        <div v-if="fileDragOver" class="absolute inset-0 z-40 flex items-center justify-center bg-agri-600/10 backdrop-blur-[1px] border-2 border-dashed border-agri-500 rounded-lg pointer-events-none">
            <div class="text-center">
                <svg class="mx-auto h-10 w-10 text-agri-500 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg>
                <p class="text-sm font-medium text-agri-700 dark:text-agri-300">Drop files here to upload</p>
                <p class="text-xs text-agri-600/70 dark:text-agri-400/70 mt-0.5">{{ selectedFolder ? 'Into ' + selectedFolder.name : 'Into root' }}</p>
            </div>
        </div>
        <!-- Header -->
        <div class="flex items-center justify-between border-b border-gray-200 px-3 py-2 dark:border-gray-800">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Explorer</h2>
            <div class="flex items-center gap-1.5">
                <!-- Visibility toggle -->
                <div class="flex items-center gap-0.5 rounded-lg border border-gray-200 bg-white p-0.5 dark:border-gray-700 dark:bg-gray-800">
                    <button @click="settings.visibility = 'private'"
                            :class="settings.visibility === 'private' ? 'bg-agri-100 text-agri-700 dark:bg-agri-900/40 dark:text-agri-300' : 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-300'"
                            class="flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-medium transition" title="Private files">
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                        Private
                    </button>
                    <button @click="settings.visibility = 'public'"
                            :class="settings.visibility === 'public' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300' : 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-300'"
                            class="flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-medium transition" title="Public files">
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        Public
                    </button>
                </div>
                <button @click="fetchTree" title="Refresh"
                        class="rounded p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-300">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                </button>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="flex items-center gap-0.5 border-b border-gray-200 px-2 py-1 dark:border-gray-800">
            <!-- New dropdown -->
            <div class="relative" ref="newDropdownRef">
                <button @click="showNewMenu = !showNewMenu"
                        class="flex items-center gap-1 rounded px-2 py-1 text-xs text-gray-600 transition hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                    New
                    <svg class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                </button>
                <div v-if="showNewMenu" class="absolute left-0 top-full z-50 mt-1 min-w-[140px] rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-800">
                    <button @click="showNewMenu = false; startCreateFolder(selectedFolder?.id ?? null)"
                            class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                        <svg class="h-3.5 w-3.5 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z" /></svg>
                        Folder
                    </button>
                    <button @click="showNewMenu = false; triggerUpload(selectedFolder?.id ?? null)"
                            class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                        <svg class="h-3.5 w-3.5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg>
                        Upload Files
                    </button>
                </div>
            </div>

            <div class="mx-1 h-4 w-px bg-gray-200 dark:bg-gray-700"></div>

            <!-- Rename -->
            <button @click="hasSelection && startRename(selectedFolder ? 'folder' : 'file', selectedFolder || { id: selectedFile, original_name: '' })"
                    :disabled="!hasSelection"
                    title="Rename"
                    class="rounded p-1.5 text-gray-500 transition hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800 disabled:opacity-30 disabled:pointer-events-none">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
            </button>

            <!-- Delete -->
            <button @click="hasSelection && deleteSelected()"
                    :disabled="!hasSelection"
                    title="Delete"
                    class="rounded p-1.5 text-gray-500 transition hover:bg-gray-100 hover:text-red-500 dark:text-gray-400 dark:hover:bg-gray-800 disabled:opacity-30 disabled:pointer-events-none">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
            </button>

            <div class="mx-1 h-4 w-px bg-gray-200 dark:bg-gray-700"></div>

            <!-- Upload -->
            <button @click="triggerUpload(selectedFolder?.id ?? null)"
                    title="Upload"
                    class="rounded p-1.5 text-gray-500 transition hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg>
            </button>
        </div>

        <!-- Hidden file input -->
        <input ref="fileInput" type="file" multiple class="hidden" @change="handleUpload" />

        <!-- Tree content -->
        <div class="flex-1 overflow-y-auto px-1 py-1"
             @dragover.prevent="onDragOver($event, null)"
             @dragleave="onDragLeave"
             @drop="onDrop($event, null)">
            <!-- Loading -->
            <div v-if="loading" class="flex items-center justify-center py-8">
                <svg class="h-5 w-5 animate-spin text-gray-400" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                </svg>
            </div>

            <!-- Uploading overlay -->
            <div v-if="uploading" class="flex items-center gap-2 rounded bg-blue-50 px-3 py-2 text-xs text-blue-700 dark:bg-blue-900/20 dark:text-blue-300">
                <svg class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                Uploading...
            </div>

            <template v-if="!loading">
                <!-- New folder input at root -->
                <div v-if="creatingInFolder === 'root'" class="flex items-center gap-1 px-2 py-1">
                    <svg class="h-4 w-4 shrink-0 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" /></svg>
                    <input ref="rootNewFolderInput" v-model="newFolderName"
                           @keydown.enter.prevent="submitNewFolder"
                           @keydown.escape.prevent="cancelNewFolder"
                           class="flex-1 rounded border border-agri-400 bg-white px-1.5 py-0.5 text-xs text-gray-900 outline-none focus:ring-1 focus:ring-agri-500 dark:border-agri-600 dark:bg-gray-800 dark:text-white"
                           placeholder="Folder name..." />
                    <button @click="submitNewFolder" class="rounded p-0.5 text-green-500 hover:bg-green-50 dark:hover:bg-green-900/20" title="Create">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                    </button>
                    <button @click="cancelNewFolder" class="rounded p-0.5 text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20" title="Cancel">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <!-- Recursive tree -->
                <TreeNode v-for="folder in tree" :key="'f-' + folder.id"
                          :folder="folder" :depth="0"
                          :expanded-folders="expandedFolders"
                          :editing-item="editingItem"
                          :editing-name="editingName"
                          :creating-in-folder="creatingInFolder"
                          :new-folder-name="newFolderName"
                          :selected-file="selectedFile"
                          :selected-folder-id="selectedFolder?.id"
                          :drop-target="dropTarget"
                          @toggle="toggleFolder"
                          @select-file="selectFile"
                          @context-folder="(e, f) => showContext(e, 'folder', f)"
                          @context-file="(e, f) => showContext(e, 'file', f)"
                          @update:editing-name="editingName = $event"
                          @submit-rename="submitRename"
                          @cancel-rename="cancelRename"
                          @update:new-folder-name="newFolderName = $event"
                          @submit-new-folder="submitNewFolder"
                          @cancel-new-folder="cancelNewFolder"
                          @drag-start="onDragStart"
                          @drag-over="onDragOver"
                          @drag-leave="onDragLeave"
                          @drop="onDrop"
                          @drag-end="onDragEnd" />

                <!-- Root files -->
                <div v-for="file in rootFiles" :key="'rf-' + file.id"
                     draggable="true"
                     @dragstart="onDragStart($event, 'file', file)"
                     @dragend="onDragEnd"
                     @click="selectFile(file)"
                     @contextmenu="showContext($event, 'file', file)"
                     class="group flex cursor-pointer items-center gap-1.5 rounded px-2 py-1 text-xs transition"
                     :class="selectedFile === file.id ? 'bg-agri-100 text-agri-800 dark:bg-agri-900/40 dark:text-agri-200' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800/60'">
                    <template v-if="editingItem && editingItem.type === 'file' && editingItem.id === file.id">
                        <FileIcon :type="fileIconType(file.mime_type)" class="h-4 w-4 shrink-0" />
                        <input :value="editingName"
                               @input="editingName = $event.target.value"
                               @keydown.enter.prevent="submitRename"
                               @keydown.escape.prevent="cancelRename"
                               @click.stop
                               autofocus
                               class="flex-1 rounded border border-agri-400 bg-white px-1.5 py-0.5 text-xs text-gray-900 outline-none focus:ring-1 focus:ring-agri-500 dark:border-agri-600 dark:bg-gray-800 dark:text-white" />
                    </template>
                    <template v-else>
                        <FileIcon :type="fileIconType(file.mime_type)" class="h-4 w-4 shrink-0" />
                        <span class="flex-1 truncate">{{ file.original_name }}</span>
                        <span :class="fileStatusColor(file.status)" class="text-[10px]">●</span>
                    </template>
                </div>

                <!-- Empty state -->
                <div v-if="!tree.length && !rootFiles.length && creatingInFolder === null" class="px-3 py-8 text-center">
                    <div class="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-gray-100 dark:bg-gray-800">
                        <svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" /></svg>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">No files yet</p>
                    <p class="mt-1 text-[10px] text-gray-400 dark:text-gray-500">Use the toolbar to create a folder or upload files</p>
                </div>
            </template>
        </div>

        <!-- Context menu -->
        <Teleport to="body">
            <div v-if="contextMenu.show"
                 :style="{ left: contextMenu.x + 'px', top: contextMenu.y + 'px' }"
                 @click.stop @contextmenu.stop.prevent
                 class="fixed z-50 min-w-[160px] rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-800">
                <template v-if="contextMenu.type === 'folder'">
                    <button @click="startCreateFolder(contextMenu.item.id)"
                            class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                        <svg class="h-3.5 w-3.5 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z" /></svg>
                        New Folder
                    </button>
                    <button @click="triggerUpload(contextMenu.item.id)"
                            class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                        <svg class="h-3.5 w-3.5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg>
                        Upload Here
                    </button>
                    <div class="my-1 border-t border-gray-100 dark:border-gray-700"></div>
                    <button @click="startRename('folder', contextMenu.item)"
                            class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                        Rename
                    </button>
                    <button @click="openDeleteModal('folder', contextMenu.item)"
                            class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                        Delete
                    </button>
                </template>
                <template v-if="contextMenu.type === 'file'">
                    <a :href="'/api/files/' + contextMenu.item.id + '/download'"
                       class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                        Download
                    </a>
                    <button @click="startRename('file', contextMenu.item)"
                            class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                        Rename
                    </button>
                    <button @click="openDeleteModal('file', contextMenu.item)"
                            class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                        Delete
                    </button>
                </template>
            </div>
        </Teleport>

        <!-- Delete confirmation modal -->
        <Teleport to="body">
            <div v-if="deleteModal.show" class="fixed inset-0 z-[100] flex items-center justify-center">
                <div class="absolute inset-0 bg-black/50" @click="cancelDelete"></div>
                <div class="relative z-10 w-full max-w-md rounded-xl border border-gray-200 bg-white p-6 shadow-2xl dark:border-gray-700 dark:bg-gray-800">
                    <div class="mb-4 flex items-center gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30">
                            <svg class="h-5 w-5 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                                Delete {{ deleteModal.type === 'folder' ? 'Folder' : 'File' }}
                            </h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">This action cannot be undone.</p>
                        </div>
                    </div>

                    <p class="mb-1 text-xs text-gray-700 dark:text-gray-300">
                        You are about to permanently delete
                        <strong class="text-gray-900 dark:text-white">{{ deleteModal.type === 'folder' ? deleteModal.item?.name : deleteModal.item?.original_name }}</strong>
                        <span v-if="deleteModal.type === 'folder'"> and all its contents</span>.
                    </p>

                    <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
                        Type <span class="rounded bg-red-100 px-1.5 py-0.5 font-mono font-bold text-red-700 dark:bg-red-900/30 dark:text-red-400">DELETE</span> to confirm.
                    </p>

                    <input v-model="deleteConfirmText"
                           @keydown.enter="deleteCanMatch && confirmDelete()"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 outline-none focus:border-red-400 focus:ring-2 focus:ring-red-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:border-red-500 dark:focus:ring-red-900/40"
                           placeholder="Type DELETE here..." />

                    <div class="mt-4 flex justify-end gap-2">
                        <button @click="cancelDelete"
                                class="rounded-lg border border-gray-300 px-4 py-2 text-xs font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                            Cancel
                        </button>
                        <button @click="confirmDelete"
                                :disabled="!deleteCanMatch || deleteLoading"
                                class="rounded-lg bg-red-600 px-4 py-2 text-xs font-medium text-white transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-40">
                            <svg v-if="deleteLoading" class="mr-1 inline h-3 w-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                            Delete Permanently
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script>
export default {
    data() {
        return { showNewMenu: false };
    },
    mounted() {
        document.addEventListener('click', this.closeNewMenu);
    },
    unmounted() {
        document.removeEventListener('click', this.closeNewMenu);
    },
    methods: {
        closeNewMenu(e) {
            if (this.$refs.newDropdownRef && !this.$refs.newDropdownRef.contains(e.target)) {
                this.showNewMenu = false;
            }
        },
    },
};
</script>
