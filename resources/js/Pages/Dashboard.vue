<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FileTree from '@/Components/FileTree.vue';
import FileIcon from '@/Components/FileIcon.vue';
import SpreadsheetEditor from '@/Components/SpreadsheetEditor.vue';
import TextEditor from '@/Components/TextEditor.vue';
import WordViewer from '@/Components/WordViewer.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed, watch, inject, onMounted, onUnmounted } from 'vue';
import axios from 'axios';
import { useSettingsStore } from '@/stores/settings';

const containerRef = ref(null);
const dividerRef = ref(null);
const leftWidth = ref(33.33);
const isDragging = ref(false);
const selectedFile = ref(null);
const selectedFolder = ref(null);
const fileTreeRef = ref(null);

// Right panel file drop
const rightDropActive = ref(false);
const rightUploading = ref(false);
const rightUploadProgress = ref(0);
const rightUploadFileCount = ref(0);
let rightDragCounter = 0;

function onRightDragEnter(e) {
    if (!e.dataTransfer?.types?.includes('Files')) return;
    rightDragCounter++;
    rightDropActive.value = true;
}

function onRightDragLeave(e) {
    if (!e.dataTransfer?.types?.includes('Files')) return;
    rightDragCounter--;
    if (rightDragCounter <= 0) {
        rightDragCounter = 0;
        rightDropActive.value = false;
    }
}

async function onRightDrop(e) {
    e.preventDefault();
    rightDropActive.value = false;
    rightDragCounter = 0;
    const files = e.dataTransfer?.files;
    if (!files || !files.length) return;
    rightUploading.value = true;
    rightUploadProgress.value = 0;
    rightUploadFileCount.value = files.length;
    const folderId = selectedFolder.value?.id ?? null;
    const formData = new FormData();
    for (let i = 0; i < files.length; i++) {
        formData.append('files[]', files[i]);
    }
    if (folderId) formData.append('folder_id', folderId);
    const settings = useSettingsStore();
    formData.append('visibility', settings.visibility);
    try {
        await axios.post('/api/files', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
            onUploadProgress(progressEvent) {
                if (progressEvent.total) {
                    rightUploadProgress.value = Math.round((progressEvent.loaded / progressEvent.total) * 100);
                }
            },
        });
        rightUploadProgress.value = 100;
        if (fileTreeRef.value?.fetchTree) {
            await fileTreeRef.value.fetchTree();
        }
    } catch (err) {
        console.error('Upload failed', err);
    } finally {
        setTimeout(() => {
            rightUploading.value = false;
            rightUploadProgress.value = 0;
            rightUploadFileCount.value = 0;
        }, 800);
    }
}

// Mobile panel switching (tree | content)
const mobilePanel = ref('tree');

// View mode persisted via Pinia
const settings = useSettingsStore();

// ── WebSocket: real-time file activity ──────────
const fileActivityEvents = inject('fileActivityEvents', ref([]));
let lastHandledEventId = 0;

watch(fileActivityEvents, (events) => {
    if (!events.length) return;
    const newEvents = events.filter((e) => e.id > lastHandledEventId);
    if (!newEvents.length) return;
    lastHandledEventId = events[events.length - 1].id;

    for (const evt of newEvents) {
        // Auto-update file status in the currently selected file
        if (evt.event === 'file.status' && selectedFile.value && selectedFile.value.id === evt.file_id) {
            selectedFile.value = { ...selectedFile.value, status: evt.status };
        }
    }
}, { deep: true });

// Delete confirmation modal
const deleteModal = ref({ show: false, type: '', item: null });
const deleteConfirmText = ref('');
const deleteLoading = ref(false);
const deleteCanMatch = computed(() => deleteConfirmText.value === 'DELETE');

// Preview state
const previewLoading = ref(false);
const previewText = ref('');
const previewError = ref('');
const mediaLoading = ref(false);

// Breadcrumb navigation
const folderHistory = ref([]);

function startDrag(e) {
    isDragging.value = true;
    e.preventDefault();
}

function onDrag(e) {
    if (!isDragging.value || !containerRef.value) return;
    const rect = containerRef.value.getBoundingClientRect();
    const clientX = e.touches ? e.touches[0].clientX : e.clientX;
    let pct = ((clientX - rect.left) / rect.width) * 100;
    pct = Math.max(20, Math.min(80, pct));
    leftWidth.value = pct;
}

function stopDrag() {
    isDragging.value = false;
}

function onFileSelected(file) {
    selectedFile.value = file;
    selectedFolder.value = null;
    mobilePanel.value = 'content';
}

function onFolderSelected(folder) {
    selectedFolder.value = folder;
    selectedFile.value = null;
    if (folder) {
        folderHistory.value = buildBreadcrumb(folder);
    }
    mobilePanel.value = 'content';
}

// Navigate into a child folder from the right panel
function openChildFolder(child) {
    selectedFolder.value = child;
    selectedFile.value = null;
    folderHistory.value = buildBreadcrumb(child);
}

function mobileBackToTree() {
    mobilePanel.value = 'tree';
    selectedFile.value = null;
    selectedFolder.value = null;
}

// Build breadcrumb from folder (uses the tree data, flat approach)
function buildBreadcrumb(folder) {
    return [folder];
}

// Items in the current folder (direct children + files, NOT recursive)
const folderItems = computed(() => {
    if (!selectedFolder.value) return [];
    const items = [];
    // child folders first
    if (selectedFolder.value.children) {
        for (const child of selectedFolder.value.children) {
            items.push({ kind: 'folder', data: child });
        }
    }
    // then files
    if (selectedFolder.value.files) {
        for (const file of selectedFolder.value.files) {
            items.push({ kind: 'file', data: file });
        }
    }
    return items;
});

const folderItemCount = computed(() => {
    if (!selectedFolder.value) return 0;
    return (selectedFolder.value.children?.length || 0) + (selectedFolder.value.files?.length || 0);
});

// File type helpers
function isPdf(file) {
    return file.mime_type && file.mime_type.includes('pdf');
}

function isImage(file) {
    return file.mime_type && file.mime_type.startsWith('image/');
}

function isVideo(file) {
    return file.mime_type && file.mime_type.startsWith('video/');
}

function isAudio(file) {
    return file.mime_type && file.mime_type.startsWith('audio/');
}

function isText(file) {
    if (!file.mime_type) return false;
    const ext = file.original_name?.split('.').pop()?.toLowerCase();
    return file.mime_type.startsWith('text/')
        || file.mime_type.includes('json')
        || ['md', 'csv', 'log', 'xml', 'yaml', 'yml'].includes(ext);
}

function isOffice(file) {
    if (!file.mime_type) return false;
    return file.mime_type.includes('word')
        || file.mime_type.includes('document')
        || file.mime_type.includes('spreadsheet')
        || file.mime_type.includes('excel')
        || file.mime_type.includes('presentation')
        || file.mime_type.includes('powerpoint');
}

function isSpreadsheetFile(file) {
    if (!file?.mime_type) return false;
    const mime = file.mime_type;
    const ext = (file.original_name || '').split('.').pop()?.toLowerCase();
    return mime.includes('spreadsheet') || mime.includes('excel') || mime.includes('csv')
        || ['xlsx', 'xls', 'csv'].includes(ext);
}

function isWordDocument(file) {
    if (!file?.mime_type) return false;
    const mime = file.mime_type;
    const ext = (file.original_name || '').split('.').pop()?.toLowerCase();
    return (mime.includes('word') || mime.includes('wordprocessingml') || mime.includes('document'))
        && ['docx'].includes(ext);
}

function isTextEditable(file) {
    if (!file?.mime_type) return false;
    const mime = file.mime_type;
    const ext = (file.original_name || '').split('.').pop()?.toLowerCase();
    if (mime.startsWith('text/') || mime.includes('json')
        || ['txt', 'md', 'log', 'xml', 'yaml', 'yml', 'json', 'env', 'ini', 'cfg', 'conf'].includes(ext)) {
        return !['csv'].includes(ext);
    }
    return false;
}

function isBinaryFile(file) {
    if (!file.mime_type) return false;
    const mime = file.mime_type;
    const ext = file.original_name?.split('.').pop()?.toLowerCase() || '';
    // Video and audio are previewable — don't treat as binary
    if (mime.startsWith('video/') || mime.startsWith('audio/')) return false;
    if (mime.startsWith('font/')) return true;
    const binaryMimes = [
        'application/zip', 'application/x-zip-compressed', 'application/x-rar-compressed',
        'application/vnd.rar', 'application/x-7z-compressed', 'application/x-tar',
        'application/gzip', 'application/x-bzip2', 'application/java-archive',
        'application/x-iso9660-image', 'application/x-msdownload', 'application/x-executable',
        'application/vnd.android.package-archive', 'application/x-apple-diskimage',
        'application/octet-stream',
    ];
    if (binaryMimes.includes(mime)) return true;
    const binaryExts = [
        'zip','rar','7z','tar','gz','bz2','xz','iso',
        'mp4','avi','mkv','mov','wmv','flv','webm','m4v',
        'mp3','wav','flac','aac','ogg','wma','m4a',
        'exe','msi','dll','dmg','apk','deb','rpm',
        'ttf','otf','woff','woff2','psd','ai','sketch','fig',
        'sql','db','sqlite','mdb',
    ];
    return binaryExts.includes(ext);
}

function binaryFileInfo(file) {
    const mime = file.mime_type || '';
    const ext = file.original_name?.split('.').pop()?.toLowerCase() || '';
    if (['zip','gz','bz2','xz','tar'].includes(ext) || mime.includes('zip') || mime.includes('compressed') || mime.includes('tar') || mime.includes('gzip'))
        return { icon: 'archive', label: 'Archive', color: 'text-amber-500', bg: 'bg-amber-50 dark:bg-amber-900/20', border: 'border-amber-200 dark:border-amber-800' };
    if (['rar','7z'].includes(ext) || mime.includes('rar') || mime.includes('7z'))
        return { icon: 'archive', label: 'Archive', color: 'text-amber-500', bg: 'bg-amber-50 dark:bg-amber-900/20', border: 'border-amber-200 dark:border-amber-800' };
    if (mime.startsWith('video/') || ['mp4','avi','mkv','mov','wmv','flv','webm','m4v'].includes(ext))
        return { icon: 'video', label: 'Video', color: 'text-purple-500', bg: 'bg-purple-50 dark:bg-purple-900/20', border: 'border-purple-200 dark:border-purple-800' };
    if (mime.startsWith('audio/') || ['mp3','wav','flac','aac','ogg','wma','m4a'].includes(ext))
        return { icon: 'audio', label: 'Audio', color: 'text-pink-500', bg: 'bg-pink-50 dark:bg-pink-900/20', border: 'border-pink-200 dark:border-pink-800' };
    if (['exe','msi','dll','dmg','apk','deb','rpm'].includes(ext) || mime.includes('executable') || mime.includes('msdownload'))
        return { icon: 'binary', label: 'Executable', color: 'text-red-500', bg: 'bg-red-50 dark:bg-red-900/20', border: 'border-red-200 dark:border-red-800' };
    if (mime.startsWith('font/') || ['ttf','otf','woff','woff2'].includes(ext))
        return { icon: 'font', label: 'Font', color: 'text-cyan-500', bg: 'bg-cyan-50 dark:bg-cyan-900/20', border: 'border-cyan-200 dark:border-cyan-800' };
    return { icon: 'binary', label: 'Binary', color: 'text-gray-500', bg: 'bg-gray-50 dark:bg-gray-900/20', border: 'border-gray-200 dark:border-gray-700' };
}

function previewUrl(file) {
    return '/api/files/' + file.id + '/preview';
}

// Load text content for text/office files
async function loadTextContent(file) {
    previewLoading.value = true;
    previewText.value = '';
    previewError.value = '';
    try {
        const res = await axios.get('/api/files/' + file.id + '/content');
        previewText.value = res.data.text || '(No extracted text available)';
    } catch (e) {
        previewError.value = 'Could not load file content.';
    } finally {
        previewLoading.value = false;
    }
}

// Auto-load content when a text/office file is selected
watch(selectedFile, (file) => {
    previewText.value = '';
    previewError.value = '';
    mediaLoading.value = false;
    if (file && (isImage(file) || isPdf(file) || isVideo(file) || isAudio(file))) {
        mediaLoading.value = true;
    }
    if (file && (isText(file) || isOffice(file))) {
        loadTextContent(file);
    }
});

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

function mimeToLabel(mimeType) {
    if (!mimeType) return 'File';
    if (mimeType.startsWith('image/')) return 'Image';
    if (mimeType === 'application/pdf') return 'PDF';
    if (mimeType.includes('word') || mimeType.includes('document')) return 'Word';
    if (mimeType.includes('sheet') || mimeType.includes('excel')) return 'Excel';
    if (mimeType.includes('presentation') || mimeType.includes('powerpoint')) return 'PowerPoint';
    if (mimeType.startsWith('text/')) return 'Text';
    return 'File';
}

function statusBadge(status) {
    switch (status) {
        case 'completed': return { class: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400', label: 'Completed' };
        case 'pending': return { class: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400', label: 'Pending' };
        case 'processing': return { class: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400', label: 'Processing' };
        case 'failed': return { class: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400', label: 'Failed' };
        default: return { class: 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400', label: status };
    }
}

function formatSize(bytes) {
    if (!bytes) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
        + ' ' + d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
}

// ── Delete with confirmation ──
function openDeleteModal(type, item) {
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
            await axios.delete('/api/folders/' + item.id);
        } else {
            await axios.delete('/api/files/' + item.id);
        }
        deleteModal.value = { show: false, type: '', item: null };
        // Refresh the tree
        if (fileTreeRef.value?.fetchTree) {
            await fileTreeRef.value.fetchTree();
        }
        // If deleted item was selected, clear selection
        if (type === 'file' && selectedFile.value?.id === item.id) {
            selectedFile.value = null;
        }
        if (type === 'folder' && selectedFolder.value?.id === item.id) {
            selectedFolder.value = null;
        }
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

onMounted(() => {
    window.addEventListener('mousemove', onDrag);
    window.addEventListener('mouseup', stopDrag);
    window.addEventListener('touchmove', onDrag);
    window.addEventListener('touchend', stopDrag);
});

onUnmounted(() => {
    window.removeEventListener('mousemove', onDrag);
    window.removeEventListener('mouseup', stopDrag);
    window.removeEventListener('touchmove', onDrag);
    window.removeEventListener('touchend', stopDrag);
});
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <div ref="containerRef" class="flex h-full flex-col md:flex-row" :class="{ 'select-none': isDragging }">
            <!-- Left Panel -->
            <div :style="{ width: leftWidth + '%' }"
                 class="h-full overflow-hidden border-r border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900"
                 :class="mobilePanel === 'tree' ? 'max-md:!w-full' : 'max-md:hidden'">
                <FileTree ref="fileTreeRef" @file-selected="onFileSelected" @folder-selected="onFolderSelected" />
            </div>

            <!-- Draggable Divider (hidden on mobile) -->
            <div ref="dividerRef"
                 @mousedown="startDrag"
                 @touchstart="startDrag"
                 class="group relative z-10 hidden w-0 cursor-col-resize md:block"
                 :class="{ 'bg-agri-500': isDragging }">
                <div class="absolute inset-y-0 -left-1 w-2 transition-colors"
                     :class="isDragging ? 'bg-agri-500' : 'bg-transparent group-hover:bg-agri-300 dark:group-hover:bg-agri-600'">
                </div>
                <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 rounded-full border border-gray-200 bg-white p-1 opacity-0 shadow-sm transition group-hover:opacity-100 dark:border-gray-700 dark:bg-gray-800"
                     :class="{ '!opacity-100': isDragging }">
                    <svg class="h-3 w-3 text-gray-400" fill="currentColor" viewBox="0 0 24 24"><circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/></svg>
                </div>
            </div>

            <!-- Right Panel -->
            <div :style="{ width: (100 - leftWidth) + '%' }"
                 class="flex h-full flex-col bg-gray-50 dark:bg-gray-950 relative"
                 :class="mobilePanel === 'content' ? 'max-md:!w-full' : 'max-md:hidden'"
                 @dragenter="onRightDragEnter"
                 @dragleave="onRightDragLeave"
                 @dragover.prevent
                 @drop="onRightDrop">
                <!-- Desktop file drop overlay -->
                <div v-if="rightDropActive" class="absolute inset-0 z-40 flex items-center justify-center bg-agri-600/10 backdrop-blur-[1px] border-2 border-dashed border-agri-500 rounded-lg pointer-events-none">
                    <div class="text-center">
                        <svg class="mx-auto h-12 w-12 text-agri-500 mb-3 animate-bounce" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg>
                        <p class="text-sm font-medium text-agri-700 dark:text-agri-300">Drop files here to upload</p>
                        <p class="text-xs text-agri-600/70 dark:text-agri-400/70 mt-1">{{ selectedFolder ? 'Into ' + selectedFolder.name : 'Into root folder' }}</p>
                    </div>
                </div>

                <!-- Upload progress overlay -->
                <div v-if="rightUploading" class="absolute inset-0 z-40 flex items-center justify-center bg-gray-900/40 backdrop-blur-sm rounded-lg">
                    <div class="text-center bg-white dark:bg-gray-800 rounded-2xl shadow-2xl px-8 py-6 border border-gray-200 dark:border-gray-700 min-w-[280px]">
                        <div class="relative mx-auto mb-4 h-16 w-16">
                            <svg class="h-16 w-16 animate-spin text-agri-500" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                            </svg>
                            <svg class="absolute inset-0 m-auto h-7 w-7 text-agri-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                            </svg>
                        </div>
                        <p class="text-sm font-semibold text-gray-900 dark:text-white mb-1">
                            {{ rightUploadProgress >= 100 ? 'Processing...' : 'Uploading...' }}
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
                            {{ rightUploadFileCount }} file{{ rightUploadFileCount > 1 ? 's' : '' }}
                        </p>
                        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2 overflow-hidden">
                            <div class="h-full rounded-full transition-all duration-300 ease-out"
                                 :class="rightUploadProgress >= 100 ? 'bg-green-500' : 'bg-agri-500'"
                                 :style="{ width: rightUploadProgress + '%' }"></div>
                        </div>
                        <p class="text-[11px] text-gray-400 mt-2">{{ rightUploadProgress }}%</p>
                    </div>
                </div>
                <!-- ============ FILE PREVIEW ============ -->
                <template v-if="selectedFile">
                    <!-- File header bar -->
                    <div class="flex shrink-0 items-center justify-between border-b border-gray-200 px-3 py-2 dark:border-gray-800 sm:px-4">
                        <div class="flex min-w-0 items-center gap-2">
                            <!-- Mobile back button -->
                            <button @click="mobileBackToTree" class="mr-1 shrink-0 rounded-md p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 md:hidden dark:hover:bg-gray-800 dark:hover:text-gray-300">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                            </button>
                            <FileIcon :type="fileIconType(selectedFile.mime_type)" class="h-5 w-5 shrink-0" />
                            <div class="min-w-0">
                                <h3 class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ selectedFile.original_name }}</h3>
                                <p class="text-[10px] text-gray-500 dark:text-gray-400">
                                    {{ selectedFile.mime_type }} &middot; {{ formatSize(selectedFile.file_size) }}
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium"
                                  :class="statusBadge(selectedFile.status).class">
                                {{ statusBadge(selectedFile.status).label }}
                            </span>
                            <a :href="'/api/files/' + selectedFile.id + '/download'"
                               class="inline-flex items-center gap-1 rounded-md bg-agri-600 px-2.5 py-1 text-[11px] font-medium text-white transition hover:bg-agri-700">
                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                                Download
                            </a>
                        </div>
                    </div>

                    <!-- Error message (only for non-binary files) -->
                    <div v-if="selectedFile.error_message && !isBinaryFile(selectedFile)" class="mx-4 mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
                        {{ selectedFile.error_message }}
                    </div>

                    <!-- Preview area -->
                    <div class="relative min-h-0 flex-1 overflow-hidden">
                        <!-- Loading overlay for media files (Wasabi fetch) -->
                        <div v-if="mediaLoading" class="absolute inset-0 z-10 flex flex-col items-center justify-center bg-gray-50/80 dark:bg-gray-950/80">
                            <svg class="h-8 w-8 animate-spin text-agri-500" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Loading preview…</p>
                        </div>

                        <!-- Binary/archive file info card -->
                        <div v-if="isBinaryFile(selectedFile)" class="flex h-full flex-col items-center justify-center p-8 text-center">
                            <div class="rounded-2xl p-6 mb-4" :class="binaryFileInfo(selectedFile).bg + ' border ' + binaryFileInfo(selectedFile).border">
                                <!-- Archive icon -->
                                <svg v-if="binaryFileInfo(selectedFile).icon === 'archive'" :class="binaryFileInfo(selectedFile).color" class="h-20 w-20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                                </svg>
                                <!-- Video icon -->
                                <svg v-else-if="binaryFileInfo(selectedFile).icon === 'video'" :class="binaryFileInfo(selectedFile).color" class="h-20 w-20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                </svg>
                                <!-- Audio icon -->
                                <svg v-else-if="binaryFileInfo(selectedFile).icon === 'audio'" :class="binaryFileInfo(selectedFile).color" class="h-20 w-20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3" />
                                </svg>
                                <!-- Font icon -->
                                <svg v-else-if="binaryFileInfo(selectedFile).icon === 'font'" :class="binaryFileInfo(selectedFile).color" class="h-20 w-20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 6v12M4 6l8 12m8-12v12m0-12L12 18" />
                                </svg>
                                <!-- Generic binary icon -->
                                <svg v-else :class="binaryFileInfo(selectedFile).color" class="h-20 w-20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">{{ binaryFileInfo(selectedFile).label }} File</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4 max-w-sm">{{ selectedFile.original_name }}</p>
                            <div class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800 p-4 w-full max-w-sm text-left space-y-2">
                                <div class="flex justify-between text-xs">
                                    <span class="text-gray-500 dark:text-gray-400">Type</span>
                                    <span class="font-medium text-gray-900 dark:text-white">{{ selectedFile.mime_type }}</span>
                                </div>
                                <div class="flex justify-between text-xs">
                                    <span class="text-gray-500 dark:text-gray-400">Extension</span>
                                    <span class="font-medium text-gray-900 dark:text-white">.{{ selectedFile.original_name?.split('.').pop()?.toLowerCase() }}</span>
                                </div>
                                <div class="flex justify-between text-xs">
                                    <span class="text-gray-500 dark:text-gray-400">Size</span>
                                    <span class="font-medium text-gray-900 dark:text-white">{{ formatSize(selectedFile.file_size) }}</span>
                                </div>
                                <div class="flex justify-between text-xs">
                                    <span class="text-gray-500 dark:text-gray-400">Status</span>
                                    <span class="font-medium" :class="selectedFile.status === 'completed' ? 'text-green-600 dark:text-green-400' : 'text-gray-900 dark:text-white'">Uploaded</span>
                                </div>
                            </div>
                            <p class="mt-4 text-xs text-gray-400 dark:text-gray-500">This file type cannot be processed by AI — download to view its contents.</p>
                        </div>

                        <iframe v-else-if="isPdf(selectedFile)" :src="previewUrl(selectedFile)" class="h-full w-full border-0" @load="mediaLoading = false" />

                        <div v-else-if="isImage(selectedFile)" class="flex h-full items-center justify-center overflow-auto p-4">
                            <img :src="previewUrl(selectedFile)" :alt="selectedFile.original_name" class="max-h-full max-w-full rounded-lg object-contain shadow-sm" @load="mediaLoading = false" @error="mediaLoading = false" />
                        </div>

                        <div v-else-if="isVideo(selectedFile)" class="flex h-full items-center justify-center overflow-auto bg-black p-4">
                            <video controls :src="previewUrl(selectedFile)" class="max-h-full max-w-full rounded-lg" @loadeddata="mediaLoading = false" @error="mediaLoading = false">
                                Your browser does not support the video tag.
                            </video>
                        </div>

                        <div v-else-if="isAudio(selectedFile)" class="flex h-full flex-col items-center justify-center p-8">
                            <div class="rounded-2xl bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800 p-6 mb-6">
                                <svg class="h-20 w-20 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3" />
                                </svg>
                            </div>
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-4">{{ selectedFile.original_name }}</p>
                            <audio controls :src="previewUrl(selectedFile)" class="w-full max-w-md" @loadeddata="mediaLoading = false" @error="mediaLoading = false">
                                Your browser does not support the audio tag.
                            </audio>
                        </div>

                        <SpreadsheetEditor v-else-if="isSpreadsheetFile(selectedFile)"
                            :file-id="selectedFile.id"
                            :can-edit="true"
                            class="h-full" />

                        <WordViewer v-else-if="isWordDocument(selectedFile)"
                            :file-id="selectedFile.id"
                            class="h-full" />

                        <TextEditor v-else-if="isTextEditable(selectedFile)"
                            :file-id="selectedFile.id"
                            :can-edit="true"
                            class="h-full" />

                        <div v-else-if="isText(selectedFile) || isOffice(selectedFile)" class="h-full overflow-auto p-4">
                            <div v-if="previewLoading" class="flex items-center justify-center py-12">
                                <svg class="h-5 w-5 animate-spin text-gray-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                            </div>
                            <div v-else-if="previewError" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">{{ previewError }}</div>
                            <pre v-else class="whitespace-pre-wrap break-words rounded-lg border border-gray-200 bg-white p-4 font-mono text-xs leading-relaxed text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">{{ previewText }}</pre>
                        </div>

                        <div v-else class="flex h-full flex-col items-center justify-center p-6 text-center">
                            <FileIcon :type="fileIconType(selectedFile.mime_type)" class="mb-3 h-16 w-16 opacity-50" />
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Preview not available</p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Download the file to view it</p>
                        </div>
                    </div>
                </template>

                <!-- ============ FOLDER BROWSER ============ -->
                <template v-else-if="selectedFolder">
                    <!-- Folder header + view toggle -->
                    <div class="flex shrink-0 items-center justify-between border-b border-gray-200 px-3 py-2 dark:border-gray-800 sm:px-4">
                        <div class="flex min-w-0 items-center gap-2">
                            <!-- Mobile back button -->
                            <button @click="mobileBackToTree" class="mr-1 shrink-0 rounded-md p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 md:hidden dark:hover:bg-gray-800 dark:hover:text-gray-300">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                            </button>
                            <div class="min-w-0">
                            <h3 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white">
                                <svg class="h-4 w-4 shrink-0 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z" /></svg>
                                <span class="truncate">{{ selectedFolder.name }}</span>
                            </h3>
                            <p class="mt-0.5 text-[10px] text-gray-500 dark:text-gray-400">{{ folderItemCount }} item(s)</p>
                            </div>
                        </div>
                        <!-- View toggle buttons -->
                        <div class="flex items-center gap-1 rounded-lg border border-gray-200 bg-white p-0.5 dark:border-gray-700 dark:bg-gray-800">
                            <button @click="settings.viewMode = 'tile'"
                                    :class="settings.viewMode === 'tile' ? 'bg-agri-100 text-agri-700 dark:bg-agri-900/40 dark:text-agri-300' : 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-300'"
                                    class="rounded-md p-1.5 transition" title="Tile view">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm10 0a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zm10 0a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z" /></svg>
                            </button>
                            <button @click="settings.viewMode = 'details'"
                                    :class="settings.viewMode === 'details' ? 'bg-agri-100 text-agri-700 dark:bg-agri-900/40 dark:text-agri-300' : 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-300'"
                                    class="rounded-md p-1.5 transition" title="Details view">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" /></svg>
                            </button>
                        </div>
                    </div>

                    <!-- Folder content -->
                    <div class="min-h-0 flex-1 overflow-y-auto">
                        <!-- Empty state -->
                        <div v-if="!folderItemCount" class="flex h-full items-center justify-center p-6">
                            <div class="text-center">
                                <p class="text-sm text-gray-500 dark:text-gray-400">This folder is empty</p>
                                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">Right-click the folder to upload files here</p>
                            </div>
                        </div>

                        <!-- ── TILE VIEW ── -->
                        <div v-else-if="settings.viewMode === 'tile'" class="grid grid-cols-[repeat(auto-fill,minmax(120px,1fr))] gap-2.5 p-3 sm:p-4">
                            <div v-for="item in folderItems" :key="item.kind + '-' + item.data.id"
                                 @click="item.kind === 'folder' ? openChildFolder(item.data) : onFileSelected(item.data)"
                                 class="group flex cursor-pointer flex-col items-center rounded-xl border border-gray-200 bg-white p-3 text-center transition hover:border-agri-300 hover:shadow-md dark:border-gray-800 dark:bg-gray-900 dark:hover:border-agri-700">

                                <!-- Icon -->
                                <div class="mb-2 flex h-12 w-12 items-center justify-center rounded-xl" :class="item.kind === 'folder' ? 'bg-yellow-50 dark:bg-yellow-900/20' : 'bg-gray-50 dark:bg-gray-800'">
                                    <template v-if="item.kind === 'folder'">
                                        <svg class="h-7 w-7 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" /></svg>
                                    </template>
                                    <template v-else>
                                        <FileIcon :type="fileIconType(item.data.mime_type)" class="h-7 w-7" />
                                    </template>
                                </div>

                                <!-- Name -->
                                <p class="w-full truncate text-xs font-medium text-gray-900 dark:text-white">
                                    {{ item.kind === 'folder' ? item.data.name : item.data.original_name }}
                                </p>

                                <!-- Sub-info -->
                                <p class="mt-0.5 text-[10px] text-gray-500 dark:text-gray-400">
                                    <template v-if="item.kind === 'folder'">
                                        {{ (item.data.children?.length || 0) + (item.data.files?.length || 0) }} item(s)
                                    </template>
                                    <template v-else>{{ formatSize(item.data.file_size) }}</template>
                                </p>

                                <!-- Status badge for files -->
                                <span v-if="item.kind === 'file'" class="mt-1.5 inline-flex items-center rounded-full px-2 py-0.5 text-[9px] font-medium"
                                      :class="statusBadge(item.data.status).class">
                                    {{ statusBadge(item.data.status).label }}
                                </span>
                            </div>
                        </div>

                        <!-- ── DETAILS VIEW (Table) ── -->
                        <div v-else class="overflow-x-auto p-3 sm:p-4">
                            <table class="w-full min-w-[540px] text-left text-xs">
                                <thead>
                                    <tr class="border-b border-gray-200 text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                        <th class="pb-2 pl-2 pr-3">Name</th>
                                        <th class="pb-2 px-3">Date Modified</th>
                                        <th class="pb-2 px-3">Type</th>
                                        <th class="pb-2 px-3">Size</th>
                                        <th class="pb-2 px-3">Status</th>
                                        <th class="pb-2 px-3 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in folderItems" :key="item.kind + '-' + item.data.id"
                                        @click="item.kind === 'folder' ? openChildFolder(item.data) : onFileSelected(item.data)"
                                        class="cursor-pointer border-b border-gray-100 transition hover:bg-gray-100 dark:border-gray-800 dark:hover:bg-gray-900">
                                        <!-- Name -->
                                        <td class="py-2 pl-2 pr-3">
                                            <div class="flex items-center gap-2">
                                                <template v-if="item.kind === 'folder'">
                                                    <svg class="h-4 w-4 shrink-0 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" /></svg>
                                                </template>
                                                <template v-else>
                                                    <FileIcon :type="fileIconType(item.data.mime_type)" class="h-4 w-4 shrink-0" />
                                                </template>
                                                <span class="truncate font-medium text-gray-900 dark:text-white">
                                                    {{ item.kind === 'folder' ? item.data.name : item.data.original_name }}
                                                </span>
                                            </div>
                                        </td>
                                        <!-- Date -->
                                        <td class="whitespace-nowrap py-2 px-3 text-gray-500 dark:text-gray-400">
                                            {{ formatDate(item.data.updated_at) }}
                                        </td>
                                        <!-- Type -->
                                        <td class="py-2 px-3 text-gray-500 dark:text-gray-400">
                                            {{ item.kind === 'folder' ? 'Folder' : mimeToLabel(item.data.mime_type) }}
                                        </td>
                                        <!-- Size -->
                                        <td class="py-2 px-3 text-gray-500 dark:text-gray-400">
                                            {{ item.kind === 'folder' ? '' : formatSize(item.data.file_size) }}
                                        </td>
                                        <!-- Status -->
                                        <td class="py-2 px-3">
                                            <template v-if="item.kind === 'file'">
                                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium"
                                                      :class="statusBadge(item.data.status).class">
                                                    {{ statusBadge(item.data.status).label }}
                                                </span>
                                            </template>
                                        </td>
                                        <!-- Action -->
                                        <td class="py-2 px-3 text-right">
                                            <button @click.stop="openDeleteModal(item.kind, item.data)"
                                                    class="rounded p-1 text-gray-400 transition hover:bg-red-50 hover:text-red-500 dark:hover:bg-red-900/20 dark:hover:text-red-400"
                                                    title="Delete">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </template>

                <!-- ============ NO SELECTION ============ -->
                <div v-else class="flex h-full items-center justify-center p-6">
                    <div class="text-center">
                        <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-agri-50 dark:bg-agri-900/30">
                            <svg class="h-8 w-8 text-agri-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                        </div>
                        <h3 class="text-sm font-medium text-gray-900 dark:text-white">Select a file or folder</h3>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Click a file to preview, or a folder to browse</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============ DELETE CONFIRMATION MODAL ============ -->
        <Teleport to="body">
            <div v-if="deleteModal.show" class="fixed inset-0 z-[100] flex items-center justify-center">
                <!-- Backdrop -->
                <div class="absolute inset-0 bg-black/50" @click="cancelDelete"></div>
                <!-- Modal -->
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
    </AuthenticatedLayout>
</template>
