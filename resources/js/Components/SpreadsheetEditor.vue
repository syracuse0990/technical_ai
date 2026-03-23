<template>
    <div class="flex h-full flex-col excel-wrapper">
        <!-- Ribbon bar -->
        <div class="excel-ribbon flex items-center justify-between px-2 py-1">
            <div class="flex items-center gap-2">
                <div class="flex items-center gap-1 text-[11px] text-gray-500 dark:text-gray-400">
                    <svg class="w-4 h-4 text-green-600 dark:text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2" />
                    </svg>
                    <span class="font-semibold text-gray-700 dark:text-gray-200">Spreadsheet</span>
                </div>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <span v-if="saving" class="text-[11px] text-gray-400 italic">Saving...</span>
                <span v-else-if="saved" class="text-[11px] text-emerald-500 flex items-center gap-1">
                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                    Saved
                </span>
                <span v-else-if="dirty" class="text-[11px] text-amber-500">● Modified</span>
                <button v-if="canEdit" @click="save" :disabled="saving || !dirty"
                    class="excel-save-btn">
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4" />
                    </svg>
                    Save
                </button>
            </div>
        </div>

        <!-- Loading overlay -->
        <div v-if="loading" class="flex-1 flex flex-col items-center justify-center gap-3 excel-grid-area">
            <svg class="w-10 h-10 text-green-600 dark:text-green-500 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            <span class="text-xs text-gray-500 dark:text-gray-400">Opening spreadsheet…</span>
        </div>

        <!-- Spreadsheet container (v5 handles tabs natively) -->
        <div v-else class="flex-1 overflow-auto excel-grid-area">
            <div ref="sheetEl" class="spreadsheet-host"></div>
        </div>

        <!-- Status bar -->
        <div v-if="!loading" class="excel-status-bar flex items-center justify-between px-3">
            <span class="text-[10px] text-gray-500 dark:text-gray-400">{{ sheets[activeSheet]?.data?.length || 0 }} rows</span>
            <span v-if="!canEdit" class="text-[10px] text-gray-400 flex items-center gap-1">
                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                Read only
            </span>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import jspreadsheet from 'jspreadsheet-ce';
import 'jspreadsheet-ce/dist/jspreadsheet.css';
import 'jsuites/dist/jsuites.css';
import { onMounted as onMountedHook } from 'vue';

// Load Material Icons for toolbar
if (!document.querySelector('link[href*="material-icons"]')) {
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'https://fonts.googleapis.com/icon?family=Material+Icons';
    document.head.appendChild(link);
}

const props = defineProps({
    fileId: { type: Number, required: true },
    canEdit: { type: Boolean, default: true },
});

const emit = defineEmits(['saved']);

const sheetEl = ref(null);
const sheets = ref([]);
const activeSheet = ref(0);
const loading = ref(true);
const saving = ref(false);
const saved = ref(false);
const dirty = ref(false);
let spreadsheet = null;

function markDirty() {
    dirty.value = true;
    saved.value = false;
}

async function loadData() {
    loading.value = true;
    try {
        const res = await fetch(`/api/files/${props.fileId}/spreadsheet`, {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
        });
        if (!res.ok) throw new Error('Failed to load spreadsheet');
        const json = await res.json();
        sheets.value = json.sheets || [];
        activeSheet.value = 0;
        dirty.value = false;
    } catch (err) {
        console.error('[Spreadsheet] Load error:', err);
    } finally {
        loading.value = false;
        await nextTick();
        renderSpreadsheet();
    }
}

function renderSpreadsheet() {
    destroyInstance();
    if (!sheetEl.value || !sheets.value.length) return;

    const worksheetConfigs = sheets.value.map((sheet, idx) => {
        const cols = sheet.columns?.length
            ? sheet.columns.map(c => ({ title: c.title, width: c.width || 120 }))
            : [{ title: 'A', width: 120 }];

        const data = sheet.data?.length ? sheet.data : [cols.map(() => '')];

        return {
            worksheetName: sheet.name || `Sheet${idx + 1}`,
            data,
            columns: cols,
            minDimensions: [Math.max(cols.length, 5), Math.max(data.length, 20)],
            defaultColWidth: 120,
        };
    });

    spreadsheet = jspreadsheet(sheetEl.value, {
        tabs: true,
        toolbar: props.canEdit ? true : false,
        worksheets: worksheetConfigs,
        editable: props.canEdit,
        allowInsertRow: props.canEdit,
        allowInsertColumn: props.canEdit,
        allowDeleteRow: props.canEdit,
        allowDeleteColumn: props.canEdit,
        contextMenu: props.canEdit ? undefined : () => false,
        onchange: markDirty,
        oninsertrow: markDirty,
        oninsertcolumn: markDirty,
        ondeleterow: markDirty,
        ondeletecolumn: markDirty,
    });
}

function destroyInstance() {
    if (spreadsheet) {
        // v5: destroy each worksheet
        if (Array.isArray(spreadsheet)) {
            spreadsheet.forEach(ws => {
                if (ws && typeof ws.destroy === 'function') ws.destroy();
            });
        } else if (typeof spreadsheet.destroy === 'function') {
            spreadsheet.destroy();
        }
        spreadsheet = null;
    }
    if (sheetEl.value) {
        sheetEl.value.innerHTML = '';
    }
}

function getWorksheets() {
    if (!spreadsheet) return [];
    return Array.isArray(spreadsheet) ? spreadsheet : [spreadsheet];
}

async function save() {
    saving.value = true;
    saved.value = false;

    try {
        const worksheets = getWorksheets();
        const payload = {
            sheets: sheets.value.map((s, idx) => ({
                name: s.name,
                data: worksheets[idx] ? worksheets[idx].getData() : s.data,
            })),
        };

        const res = await fetch(`/api/files/${props.fileId}/spreadsheet`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify(payload),
        });

        if (!res.ok) throw new Error('Save failed');

        dirty.value = false;
        saved.value = true;
        emit('saved');
        setTimeout(() => { saved.value = false; }, 3000);
    } catch (err) {
        console.error('[Spreadsheet] Save error:', err);
        alert('Failed to save. Please try again.');
    } finally {
        saving.value = false;
    }
}

watch(() => props.fileId, () => {
    loadData();
});

onMounted(() => {
    loadData();
});

onBeforeUnmount(() => {
    destroyInstance();
});
</script>

<style>
/* ===== Excel-like Wrapper ===== */
.excel-wrapper {
    border: 1px solid #d1d5db;
    border-radius: 6px;
    overflow: hidden;
}
.dark .excel-wrapper {
    border-color: #374151;
}

/* ===== Ribbon Bar ===== */
.excel-ribbon {
    background: linear-gradient(to bottom, #f0fdf4, #ecfdf5);
    border-bottom: 1px solid #d1d5db;
    min-height: 32px;
}
.dark .excel-ribbon {
    background: linear-gradient(to bottom, #0f1d15, #111827);
    border-bottom-color: #374151;
}

.excel-save-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    font-size: 11px;
    font-weight: 500;
    color: #fff;
    background: #16a34a;
    border: 1px solid #15803d;
    border-radius: 4px;
    transition: all 0.15s;
    cursor: pointer;
}
.excel-save-btn:hover:not(:disabled) {
    background: #15803d;
}
.excel-save-btn:disabled {
    opacity: 0.35;
    cursor: default;
}
.dark .excel-save-btn {
    background: #166534;
    border-color: #14532d;
}
.dark .excel-save-btn:hover:not(:disabled) {
    background: #15803d;
}

/* ===== Grid Area ===== */
.excel-grid-area {
    background: #fff;
}
.dark .excel-grid-area {
    background: #111827;
}

/* ===== Status Bar ===== */
.excel-status-bar {
    background: #f3f4f6;
    border-top: 1px solid #d1d5db;
    min-height: 20px;
}
.dark .excel-status-bar {
    background: #0f172a;
    border-top-color: #374151;
}

/* ===== v5 jspreadsheet Excel-like Overrides ===== */

/* Spreadsheet container */
.spreadsheet-host .jss_spreadsheet {
    font-family: 'Segoe UI', Calibri, Arial, sans-serif !important;
    width: 100% !important;
}

/* Worksheet table */
.spreadsheet-host .jss_worksheet {
    font-size: 12px;
    border-collapse: separate;
    background: #fff;
}

/* Cell grid lines */
.spreadsheet-host .jss_worksheet > tbody > tr > td {
    border: 1px solid #e2e5e9;
    padding: 2px 6px;
    height: 22px;
    vertical-align: middle;
    color: #1f2937;
    background: #fff;
}

/* Column headers (A, B, C…) */
.spreadsheet-host .jss_worksheet > thead > tr > td {
    background: #f0f1f3 !important;
    border: 1px solid #d4d7dc;
    border-bottom: 2px solid #c0c4cc;
    font-weight: 600;
    font-size: 11px;
    color: #333;
    text-align: center;
    padding: 3px 6px;
    height: 24px;
    user-select: none;
}

/* Row number column */
.spreadsheet-host .jss_worksheet > tbody > tr > td:first-child {
    background: #f0f1f3 !important;
    border: 1px solid #d4d7dc;
    border-right: 2px solid #c0c4cc;
    color: #555;
    font-size: 11px;
    font-weight: 500;
    text-align: center;
    min-width: 40px;
    width: 40px;
    user-select: none;
}

/* Top-left corner cell */
.spreadsheet-host .jss_worksheet > thead > tr > td:first-child {
    background: #e8eaed !important;
    border-right: 2px solid #c0c4cc;
    border-bottom: 2px solid #c0c4cc;
}

/* Selected cell highlight */
.spreadsheet-host .jss_worksheet > tbody > tr > td.highlight {
    background: #e8f0fe !important;
}

/* Selected headers */
.spreadsheet-host .jss_worksheet > thead > tr > td.selected {
    background: #dae3f3 !important;
    color: #1a3a5c;
    font-weight: 700;
}
.spreadsheet-host .jss_worksheet > tbody > tr > td:first-child.selected {
    background: #dae3f3 !important;
    color: #1a3a5c;
    font-weight: 700;
}

/* Alternating row hint */
.spreadsheet-host .jss_worksheet > tbody > tr:nth-child(even) > td:not(:first-child) {
    background: #fafbfc;
}

/* Editor input */
.spreadsheet-host .jss_worksheet .editor input,
.spreadsheet-host .jss_worksheet .editor textarea {
    font-family: inherit;
    font-size: 12px;
    padding: 2px 6px;
    border: 2px solid #2563eb !important;
    outline: none;
    background: #fff;
    color: #111;
}

/* Content scrollbar */
.spreadsheet-host .jss_content::-webkit-scrollbar {
    width: 10px;
    height: 10px;
}
.spreadsheet-host .jss_content::-webkit-scrollbar-track {
    background: #f1f1f1;
}
.spreadsheet-host .jss_content::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 5px;
}
.spreadsheet-host .jss_content::-webkit-scrollbar-thumb:hover {
    background: #a1a1a1;
}

/* v5 built-in tabs — Excel style */
.spreadsheet-host .jtabs .jtabs-headers {
    background: #f3f4f6;
    border-top: 1px solid #d1d5db;
}
.spreadsheet-host .jtabs .jtabs-headers > div {
    font-size: 11px;
    padding: 4px 16px;
    color: #6b7280;
    border: none;
    border-right: 1px solid #d1d5db;
    background: transparent;
    cursor: pointer;
    transition: all 0.15s;
}
.spreadsheet-host .jtabs .jtabs-headers > div:hover {
    background: #e5e7eb;
    color: #374151;
}
.spreadsheet-host .jtabs .jtabs-headers > div.jtabs-selected {
    background: #fff;
    color: #111827;
    font-weight: 600;
    border-bottom: 2px solid #16a34a;
}
/* Hide the add-tab button if not editable */
.spreadsheet-host .jtabs .jtabs-headers .jtabs-add {
    display: none;
}

/* ===== DARK MODE ===== */

.dark .spreadsheet-host .jss_worksheet {
    background: #111827;
}

.dark .spreadsheet-host .jss_worksheet > tbody > tr > td {
    border-color: #2d3748;
    background: #111827;
    color: #e5e7eb;
}

.dark .spreadsheet-host .jss_worksheet > thead > tr > td {
    background: #1e2533 !important;
    border-color: #374151;
    border-bottom-color: #4b5563;
    color: #9ca3af;
}

.dark .spreadsheet-host .jss_worksheet > tbody > tr > td:first-child {
    background: #1e2533 !important;
    border-color: #374151;
    border-right-color: #4b5563;
    color: #9ca3af;
}

.dark .spreadsheet-host .jss_worksheet > thead > tr > td:first-child {
    background: #1a2030 !important;
}

.dark .spreadsheet-host .jss_worksheet > tbody > tr > td.highlight {
    background: #1e3a5f !important;
}

.dark .spreadsheet-host .jss_worksheet > thead > tr > td.selected {
    background: #1e3a5f !important;
    color: #93c5fd;
}
.dark .spreadsheet-host .jss_worksheet > tbody > tr > td:first-child.selected {
    background: #1e3a5f !important;
    color: #93c5fd;
}

.dark .spreadsheet-host .jss_worksheet > tbody > tr:nth-child(even) > td:not(:first-child) {
    background: #0f172a;
}

.dark .spreadsheet-host .jss_worksheet .editor input,
.dark .spreadsheet-host .jss_worksheet .editor textarea {
    border-color: #3b82f6 !important;
    background: #1f2937;
    color: #f3f4f6;
}

.dark .spreadsheet-host .jss_content {
    background: #111827;
}

.dark .spreadsheet-host .jss_content::-webkit-scrollbar-track {
    background: #1f2937;
}
.dark .spreadsheet-host .jss_content::-webkit-scrollbar-thumb {
    background: #4b5563;
}
.dark .spreadsheet-host .jss_content::-webkit-scrollbar-thumb:hover {
    background: #6b7280;
}

/* Dark mode tabs */
.dark .spreadsheet-host .jtabs .jtabs-headers {
    background: #1a1a2e;
    border-top-color: #374151;
}
.dark .spreadsheet-host .jtabs .jtabs-headers > div {
    color: #9ca3af;
    border-right-color: #374151;
}
.dark .spreadsheet-host .jtabs .jtabs-headers > div:hover {
    background: #1f2937;
    color: #e5e7eb;
}
.dark .spreadsheet-host .jtabs .jtabs-headers > div.jtabs-selected {
    background: #111827;
    color: #f9fafb;
    border-bottom-color: #22c55e;
}

/* Context menu dark mode */
.dark .jcontextmenu {
    background: #1f2937;
    border-color: #374151;
    color: #e5e7eb;
}
.dark .jcontextmenu > div:hover {
    background: #374151;
}

/* Toolbar dark mode */
.dark .jss_toolbar,
.dark .jtoolbar {
    background: #1f2937;
    border-color: #374151;
}

/* Selection blue border (v5 corner element) */
.spreadsheet-host .jss_corner {
    border-color: #2563eb;
    background: #2563eb;
}
.dark .spreadsheet-host .jss_corner {
    border-color: #3b82f6;
    background: #3b82f6;
}

/* ===== Toolbar (Excel-like ribbon) ===== */
.spreadsheet-host .jss_toolbar {
    background: #f8f9fa;
    border-bottom: 1px solid #d4d7dc;
    padding: 4px 8px;
    display: flex;
    align-items: center;
    gap: 2px;
    flex-wrap: wrap;
}
.spreadsheet-host .jss_toolbar .jtoolbar-item {
    padding: 4px 6px;
    border-radius: 4px;
    cursor: pointer;
    color: #444;
    min-width: 28px;
    height: 28px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background 0.15s;
}
.spreadsheet-host .jss_toolbar .jtoolbar-item:hover {
    background: #e5e7eb;
}
.spreadsheet-host .jss_toolbar .jtoolbar-item.jtoolbar-selected {
    background: #dbeafe;
    color: #1d4ed8;
}
.spreadsheet-host .jss_toolbar .jtoolbar-divisor {
    width: 1px;
    height: 20px;
    background: #d1d5db;
    margin: 0 4px;
}
.spreadsheet-host .jss_toolbar .jtoolbar-item i.material-icons {
    font-size: 18px;
}
.dark .spreadsheet-host .jss_toolbar .jtoolbar-item i.material-icons {
    color: #e0e7ff;
    filter: brightness(1.3);
}
.spreadsheet-host .jss_toolbar select {
    font-size: 11px;
    padding: 2px 4px;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    background: #fff;
    color: #333;
    height: 28px;
    cursor: pointer;
}
.spreadsheet-host .jss_toolbar select:focus {
    outline: none;
    border-color: #2563eb;
}

/* Dark mode toolbar */
.dark .spreadsheet-host .jss_toolbar {
    background: #111827;
    border-bottom-color: #1e3a5f;
}
.dark .spreadsheet-host .jss_toolbar .jtoolbar-item {
    color: #dbeafe;
    background: #1e293b;
    border: 1px solid #2563eb;
    border-radius: 4px;
    margin: 1px;
}
.dark .spreadsheet-host .jss_toolbar .jtoolbar-item:hover {
    background: #1d4ed8;
    border-color: #3b82f6;
    color: #fff;
}
.dark .spreadsheet-host .jss_toolbar .jtoolbar-item.jtoolbar-selected {
    background: #2563eb;
    border-color: #60a5fa;
    color: #fff;
}
.dark .spreadsheet-host .jss_toolbar .jtoolbar-divisor {
    background: #1e3a5f;
}
.dark .spreadsheet-host .jss_toolbar select {
    background: #1e293b;
    color: #93c5fd;
    border: 1px solid #2563eb;
    border-radius: 4px;
}
.dark .spreadsheet-host .jss_toolbar select:hover {
    border-color: #3b82f6;
    background: #1d4ed8;
    color: #fff;
}
.dark .spreadsheet-host .jss_toolbar select:focus {
    border-color: #60a5fa;
}
</style>
