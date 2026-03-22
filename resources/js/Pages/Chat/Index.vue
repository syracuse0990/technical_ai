<template>
    <AuthenticatedLayout>
        <div class="flex h-[calc(100vh-4rem)] overflow-hidden">
            <!-- Sidebar: Conversations -->
            <div class="w-64 shrink-0 hidden md:flex flex-col border-r border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900 overflow-hidden">
                <div class="border-b border-gray-200 p-3 dark:border-gray-800">
                    <button @click="showNewChat = true" class="w-full rounded-lg bg-agri-600 px-3 py-2 text-sm font-medium text-white hover:bg-agri-700 transition">
                        + New Chat
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto p-2 space-y-1">
                    <div v-for="conv in conversations" :key="conv.id"
                        class="group flex items-center rounded-lg text-sm transition"
                        :class="activeConversation?.id === conv.id ? 'bg-agri-50 text-agri-700 dark:bg-agri-900/30 dark:text-agri-400' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-white'">
                        <Link :href="`/chat/${conv.id}`" class="flex-1 truncate px-3 py-2 font-medium">
                            {{ conv.title }}
                        </Link>
                        <button @click.prevent="confirmDeleteConversation(conv)"
                            class="shrink-0 mr-1 rounded p-1 text-gray-400 opacity-0 group-hover:opacity-100 hover:text-red-500 transition">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                    <p v-if="conversations.length === 0" class="text-center text-xs text-gray-400 py-4">No conversations yet</p>
                </div>
            </div>

            <!-- Main Chat Area -->
            <div class="flex-1 flex flex-col bg-gray-50 dark:bg-gray-950 overflow-hidden">
                <!-- Chat header -->
                <div v-if="activeConversation" class="border-b border-gray-200 px-4 py-2 flex items-center justify-between dark:border-gray-800">
                    <div class="md:hidden">
                        <select @change="navigateConversation($event.target.value)" class="rounded-lg border border-gray-300 bg-white px-2 py-1 text-xs text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                            <option v-for="conv in conversations" :key="conv.id" :value="conv.id" :selected="conv.id === activeConversation?.id">{{ conv.title }}</option>
                        </select>
                    </div>
                    <span class="hidden md:block text-sm text-gray-500 dark:text-gray-400 truncate">{{ activeConversation.title }}</span>
                    <button @click="showSystemPrompt = !showSystemPrompt"
                        class="rounded-lg px-2 py-1 text-xs transition"
                        :class="activeConversation.system_prompt ? 'bg-agri-50 text-agri-700 dark:bg-agri-900/30 dark:text-agri-400' : 'text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 inline mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                        Persona
                    </button>
                </div>

                <!-- System Prompt Editor -->
                <div v-if="showSystemPrompt && activeConversation" class="border-b border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">System Prompt — customize AI personality</label>
                    <textarea v-model="systemPromptDraft" rows="3" placeholder="e.g. You are a senior plant pathologist. Focus on disease diagnostics and IPM."
                        class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-agri-500 focus:outline-none resize-none dark:border-gray-700 dark:bg-gray-800 dark:text-white dark:placeholder-gray-500"></textarea>
                    <div class="flex justify-end gap-2 mt-2">
                        <button @click="showSystemPrompt = false" class="rounded px-3 py-1 text-xs text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-white transition">Cancel</button>
                        <button @click="saveSystemPrompt" :disabled="savingPrompt" class="rounded bg-agri-600 px-3 py-1 text-xs font-medium text-white hover:bg-agri-700 disabled:opacity-50 transition">
                            {{ savingPrompt ? 'Saving...' : 'Save' }}
                        </button>
                    </div>
                </div>

                <!-- Messages -->
                <div ref="messagesContainer" class="flex-1 overflow-y-auto p-4 space-y-4" @click="handleTableCopy">
                    <div v-if="!activeConversation" class="flex h-full items-center justify-center">
                        <div class="text-center">
                            <img src="/images/logo.png" alt="LeadsTech" class="h-16 w-16 mx-auto mb-4 rounded-full ring-2 ring-gray-200 dark:ring-gray-700 opacity-60" />
                            <h2 class="text-xl font-semibold text-gray-500 dark:text-gray-400 mb-2">Ask me anything about your files</h2>
                            <p class="text-sm text-gray-400 dark:text-gray-600 max-w-md mx-auto">I specialize in Plant Pathology, Entomology, Banana/Rice/Vegetable Science, Soil & Nutrition. I can search your documents and find relevant files.</p>
                            <button @click="showNewChat = true" class="mt-4 md:hidden rounded-lg bg-agri-600 px-4 py-2 text-sm font-medium text-white hover:bg-agri-700 transition">+ New Chat</button>
                        </div>
                    </div>

                    <div v-for="msg in allMessages" :key="msg.id || msg.tempId" class="flex" :class="msg.role === 'user' ? 'justify-end' : 'justify-start'">
                        <div class="max-w-[80%] group/msg" :class="msg.role === 'assistant' ? 'flex gap-2.5' : ''">
                            <img v-if="msg.role === 'assistant'" src="/images/logo.png" alt="AI" class="h-7 w-7 rounded-full shrink-0 mt-1 ring-1 ring-gray-200 dark:ring-gray-700" />
                            <div class="min-w-0">
                                <div class="rounded-xl px-4 py-3 text-sm"
                                    :class="msg.role === 'user'
                                        ? 'bg-agri-600 text-white rounded-br-sm'
                                        : 'bg-white text-gray-800 dark:bg-gray-800 dark:text-gray-200 rounded-bl-sm border border-gray-200 dark:border-gray-700'">
                                    <div v-if="msg.role === 'user'" class="whitespace-pre-wrap">{{ msg.content }}</div>
                                    <div v-else class="prose prose-sm dark:prose-invert max-w-none" v-html="renderMarkdown(msg.content)"></div>
                                    <span v-if="msg.streaming" class="inline-block w-1.5 h-4 ml-0.5 bg-agri-400 animate-pulse"></span>
                                </div>

                                <!-- File cards attached to message -->
                                <div v-if="getMessageFiles(msg).length" class="mt-2 space-y-1.5">
                                    <div v-for="file in getMessageFiles(msg)" :key="file.id"
                                        class="flex items-center gap-3 rounded-lg border border-gray-200 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-800">
                                        <div class="shrink-0 flex h-8 w-8 items-center justify-center rounded-lg" :class="fileIconBg(file.mime_type)">
                                            <span class="text-xs font-bold text-white">{{ fileExt(file.original_name) }}</span>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-xs font-medium text-gray-900 dark:text-white truncate">{{ file.original_name }}</p>
                                            <p class="text-[10px] text-gray-400">{{ formatSize(file.file_size) }}</p>
                                        </div>
                                        <div class="flex shrink-0 gap-1">
                                            <button @click="openFilePreview(file)"
                                                class="rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-agri-600 dark:hover:bg-gray-700 transition" title="Preview">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                            </button>
                                            <a :href="`/api/files/${file.id}/download`"
                                                class="rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-agri-600 dark:hover:bg-gray-700 transition" title="Download">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                                            </a>
                                        </div>
                                    </div>
                                </div>

                                <!-- Actions for assistant messages -->
                                <div v-if="msg.role === 'assistant' && msg.content && !msg.streaming" class="flex items-center gap-1 mt-1 opacity-0 group-hover/msg:opacity-100 transition">
                                    <button @click="copyMessage(msg.content)" class="rounded p-1 text-gray-400 hover:text-gray-700 dark:hover:text-white transition" title="Copy">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                                    </button>
                                    <button v-if="msg.id" @click="sendFeedback(msg, 'up')"
                                        class="rounded p-1 transition" :class="msg.feedback === 'up' ? 'text-emerald-500' : 'text-gray-400 hover:text-emerald-500'" title="Good response">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5" /></svg>
                                    </button>
                                    <button v-if="msg.id" @click="sendFeedback(msg, 'down')"
                                        class="rounded p-1 transition" :class="msg.feedback === 'down' ? 'text-red-500' : 'text-gray-400 hover:text-red-500'" title="Bad response">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14H5.236a2 2 0 01-1.789-2.894l3.5-7A2 2 0 018.736 3h4.018a2 2 0 01.485.06l3.76.94m-7 10v5a2 2 0 002 2h.096c.5 0 .905-.405.905-.904 0-.715.211-1.413.608-2.008L17 13V4m-7 10h2m5-10h2a2 2 0 012 2v6a2 2 0 01-2 2h-2.5" /></svg>
                                    </button>
                                    <span v-if="copiedId === (msg.id || msg.tempId)" class="text-[10px] text-emerald-500 ml-1">Copied!</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- AI Thinking indicator -->
                    <div v-if="isLoading && !streamingMessage" class="flex justify-start">
                        <div class="flex gap-2.5">
                            <img src="/images/logo.png" alt="AI" class="h-7 w-7 rounded-full shrink-0 mt-1 ring-1 ring-gray-200 dark:ring-gray-700 animate-pulse" />
                            <div class="rounded-xl bg-white dark:bg-gray-800 rounded-bl-sm px-4 py-3 border border-gray-200 dark:border-gray-700">
                                <div class="flex items-center gap-2">
                                    <div class="flex gap-1">
                                        <span class="h-2 w-2 rounded-full bg-agri-400 animate-bounce" style="animation-delay: 0ms"></span>
                                        <span class="h-2 w-2 rounded-full bg-agri-400 animate-bounce" style="animation-delay: 150ms"></span>
                                        <span class="h-2 w-2 rounded-full bg-agri-400 animate-bounce" style="animation-delay: 300ms"></span>
                                    </div>
                                    <span class="text-xs text-gray-400 ml-1">Searching documents...</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Input -->
                <div v-if="activeConversation" class="border-t border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                    <form @submit.prevent="sendMessage" class="flex gap-3">
                        <input v-model="messageInput" type="text" placeholder="Ask about crops, pests, diseases, or say 'find me the file for...'"
                            :disabled="isLoading"
                            class="flex-1 rounded-lg border border-gray-300 bg-gray-50 px-4 py-2.5 text-gray-900 placeholder-gray-400 focus:border-agri-500 focus:outline-none disabled:opacity-50 dark:border-gray-700 dark:bg-gray-800 dark:text-white dark:placeholder-gray-500"
                            @keydown.enter.prevent="sendMessage" />
                        <button type="submit" :disabled="!messageInput.trim() || isLoading"
                            class="rounded-lg bg-agri-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-agri-700 disabled:opacity-50 transition">
                            Send
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- New Chat Modal -->
        <div v-if="showNewChat" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showNewChat = false">
            <div class="w-full max-w-md rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">New Conversation</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">Start a new chat. The AI will search across your uploaded files to find answers.</p>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="showNewChat = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:text-gray-900 dark:border-gray-700 dark:text-gray-400 dark:hover:text-white transition">Cancel</button>
                    <button @click="startChat" :disabled="newChatForm.processing" class="rounded-lg bg-agri-600 px-4 py-2 text-sm font-medium text-white hover:bg-agri-700 disabled:opacity-50 transition">
                        Start Chat
                    </button>
                </div>
            </div>
        </div>

        <!-- Confirm Delete Modal -->
        <div v-if="confirmDelete" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="confirmDelete = null">
            <div class="w-full max-w-sm rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Delete Conversation</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">Are you sure you want to delete "{{ confirmDelete.title }}"? This cannot be undone.</p>
                <div class="flex justify-end gap-3">
                    <button @click="confirmDelete = null" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:text-gray-900 dark:border-gray-700 dark:text-gray-400 dark:hover:text-white transition">Cancel</button>
                    <button @click="deleteConversation" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-500 transition">Delete</button>
                </div>
            </div>
        </div>

        <!-- File Preview Modal -->
        <div v-if="previewFile" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" @click.self="previewFile = null">
            <div class="w-full max-w-4xl max-h-[90vh] flex flex-col rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900 shadow-2xl mx-4">
                <div class="flex items-center justify-between border-b border-gray-200 px-5 py-3 dark:border-gray-700">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="shrink-0 flex h-8 w-8 items-center justify-center rounded-lg" :class="fileIconBg(previewFile.mime_type)">
                            <span class="text-xs font-bold text-white">{{ fileExt(previewFile.original_name) }}</span>
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900 dark:text-white truncate">{{ previewFile.original_name }}</p>
                            <p class="text-xs text-gray-400">{{ formatSize(previewFile.file_size) }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <a :href="`/api/files/${previewFile.id}/download`"
                            class="rounded-lg bg-agri-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-agri-700 transition">
                            Download
                        </a>
                        <button @click="previewFile = null" class="rounded-lg p-1.5 text-gray-400 hover:text-gray-700 dark:hover:text-white transition">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                </div>
                <div class="flex-1 overflow-auto p-1 bg-gray-100 dark:bg-gray-950 relative min-h-[300px]">
                    <div v-if="previewLoading" class="absolute inset-0 flex items-center justify-center bg-gray-100 dark:bg-gray-950">
                        <div class="text-center">
                            <div class="h-8 w-8 mx-auto border-2 border-agri-300 border-t-agri-600 rounded-full animate-spin"></div>
                            <p class="mt-2 text-xs text-gray-500">Loading preview...</p>
                        </div>
                    </div>
                    <img v-if="isImage(previewFile.mime_type)"
                        :src="`/api/files/${previewFile.id}/preview`"
                        class="max-w-full max-h-[75vh] mx-auto rounded"
                        @load="previewLoading = false" @error="previewLoading = false" />
                    <iframe v-else-if="isPdf(previewFile.mime_type)"
                        :src="`/api/files/${previewFile.id}/preview`"
                        class="w-full h-[75vh] rounded"
                        @load="previewLoading = false"></iframe>
                    <div v-else-if="previewText !== null" class="p-4">
                        <pre class="whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-300 font-mono">{{ previewText }}</pre>
                    </div>
                    <div v-else class="flex items-center justify-center h-64">
                        <div class="text-center">
                            <p class="text-gray-500 dark:text-gray-400 text-sm mb-3">Preview not available for this file type.</p>
                            <a :href="`/api/files/${previewFile.id}/download`"
                                class="rounded-lg bg-agri-600 px-4 py-2 text-sm font-medium text-white hover:bg-agri-700 transition">
                                Download Instead
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, nextTick, watch } from 'vue';
import { Link, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { marked } from 'marked';

marked.setOptions({
    breaks: true,
    gfm: true,
});

function renderMarkdown(text) {
    if (!text) return '';
    let html = marked.parse(text);
    html = html.replace(/<table>/g,
        '<div class="chat-table-wrapper"><button class="chat-table-copy" title="Copy table for Excel"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg></button><table>'
    );
    html = html.replace(/<\/table>/g, '</table></div>');
    return html;
}

function handleTableCopy(event) {
    const btn = event.target.closest('.chat-table-copy');
    if (!btn) return;
    const wrapper = btn.closest('.chat-table-wrapper');
    const table = wrapper?.querySelector('table');
    if (!table) return;
    const rows = table.querySelectorAll('tr');
    const tsv = Array.from(rows).map(row => {
        const cells = row.querySelectorAll('th, td');
        return Array.from(cells).map(c => c.textContent.trim()).join('\t');
    }).join('\n');
    navigator.clipboard.writeText(tsv).then(() => {
        btn.classList.add('copied');
        btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>';
        setTimeout(() => {
            btn.classList.remove('copied');
            btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>';
        }, 2000);
    });
}

const props = defineProps({
    conversations: Array,
    activeConversation: Object,
    messages: Array,
});

const messageInput = ref('');
const isLoading = ref(false);
/* tableCopiedId removed — copy is now handled via DOM event delegation */
const showNewChat = ref(false);
const streamingMessage = ref(null);
const streamingFiles = ref([]);
const messagesContainer = ref(null);
const localMessages = ref([]);
const confirmDelete = ref(null);
const copiedId = ref(null);
const showSystemPrompt = ref(false);
const systemPromptDraft = ref(props.activeConversation?.system_prompt || '');
const savingPrompt = ref(false);
const previewFile = ref(null);
const previewLoading = ref(false);
const previewText = ref(null);

const allMessages = computed(() => {
    const msgs = [...props.messages, ...localMessages.value];
    if (streamingMessage.value) {
        msgs.push(streamingMessage.value);
    }
    return msgs;
});

function getMessageFiles(msg) {
    if (msg.files && msg.files.length) return msg.files;
    if (msg.metadata?.files) return msg.metadata.files;
    return [];
}

function fileExt(name) {
    return (name || '').split('.').pop()?.toUpperCase()?.slice(0, 4) || '?';
}

function fileIconBg(mime) {
    if (!mime) return 'bg-gray-500';
    if (mime.includes('pdf')) return 'bg-red-500';
    if (mime.includes('image')) return 'bg-blue-500';
    if (mime.includes('spreadsheet') || mime.includes('excel')) return 'bg-emerald-600';
    if (mime.includes('word') || mime.includes('document')) return 'bg-blue-600';
    if (mime.includes('presentation') || mime.includes('powerpoint')) return 'bg-orange-500';
    if (mime.includes('text')) return 'bg-gray-600';
    return 'bg-gray-500';
}

function formatSize(bytes) {
    if (!bytes) return '—';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
}

function isImage(mime) {
    return mime && mime.startsWith('image/');
}

function isPdf(mime) {
    return mime && mime.includes('pdf');
}

async function openFilePreview(file) {
    previewFile.value = file;
    previewLoading.value = true;
    previewText.value = null;

    if (isImage(file.mime_type) || isPdf(file.mime_type)) {
        return;
    }

    try {
        const res = await fetch(`/api/files/${file.id}/content`, {
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        });
        if (res.ok) {
            const data = await res.json();
            previewText.value = data.content || 'No text content available.';
        } else {
            previewText.value = null;
        }
    } catch {
        previewText.value = null;
    } finally {
        previewLoading.value = false;
    }
}

const newChatForm = useForm({});

function confirmDeleteConversation(conv) {
    confirmDelete.value = conv;
}

function deleteConversation() {
    if (confirmDelete.value) {
        router.delete(`/chat/${confirmDelete.value.id}`);
        confirmDelete.value = null;
    }
}

function navigateConversation(id) {
    router.visit(`/chat/${id}`);
}

function startChat() {
    newChatForm.post('/chat', {
        onSuccess: () => {
            showNewChat.value = false;
        },
    });
}

async function copyMessage(content) {
    try {
        await navigator.clipboard.writeText(content);
        copiedId.value = Date.now();
        setTimeout(() => { copiedId.value = null; }, 2000);
    } catch {}
}

async function sendFeedback(msg, type) {
    const newFeedback = msg.feedback === type ? 'null' : type;
    msg.feedback = newFeedback === 'null' ? null : newFeedback;
    try {
        await fetch(`/messages/${msg.id}/feedback`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({ feedback: newFeedback }),
        });
    } catch {}
}

async function saveSystemPrompt() {
    if (!props.activeConversation) return;
    savingPrompt.value = true;
    try {
        await fetch(`/chat/${props.activeConversation.id}/system-prompt`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({ system_prompt: systemPromptDraft.value }),
        });
        props.activeConversation.system_prompt = systemPromptDraft.value || null;
        showSystemPrompt.value = false;
    } catch {} finally {
        savingPrompt.value = false;
    }
}

async function sendMessage() {
    const message = messageInput.value.trim();
    if (!message || isLoading.value || !props.activeConversation) return;

    messageInput.value = '';
    isLoading.value = true;
    streamingFiles.value = [];

    localMessages.value.push({
        tempId: Date.now(),
        role: 'user',
        content: message,
    });

    await nextTick();
    scrollToBottom();

    streamingMessage.value = {
        tempId: Date.now() + 1,
        role: 'assistant',
        content: '',
        streaming: true,
        files: [],
    };

    try {
        const response = await fetch(`/chat/${props.activeConversation.id}/stream`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content
                    || document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1]
                    || '',
                'Accept': 'text/event-stream',
            },
            body: JSON.stringify({ message }),
        });

        const reader = response.body.getReader();
        const decoder = new TextDecoder();

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            const text = decoder.decode(value);
            const lines = text.split('\n');

            for (const line of lines) {
                if (line.startsWith('data: ')) {
                    const data = line.slice(6);
                    if (data === '[DONE]') continue;
                    try {
                        const parsed = JSON.parse(data);
                        if (parsed.files) {
                            streamingFiles.value = parsed.files;
                            streamingMessage.value.files = parsed.files;
                            scrollToBottom();
                        }
                        if (parsed.chunk) {
                            streamingMessage.value.content += parsed.chunk;
                            scrollToBottom();
                        }
                    } catch {}
                }
            }
        }

        localMessages.value.push({
            tempId: Date.now() + 2,
            role: 'assistant',
            content: streamingMessage.value.content,
            files: streamingFiles.value,
        });
        streamingMessage.value = null;
        streamingFiles.value = [];

    } catch (err) {
        streamingMessage.value = null;
        streamingFiles.value = [];

        try {
            const formData = new FormData();
            formData.append('message', message);

            const resp = await fetch(`/chat/${props.activeConversation.id}/message`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: formData,
            });

            if (resp.redirected) {
                window.location.href = resp.url;
                return;
            }
        } catch {
            localMessages.value.push({
                tempId: Date.now() + 3,
                role: 'assistant',
                content: 'Sorry, something went wrong. Please try again.',
            });
        }
    } finally {
        isLoading.value = false;
    }
}

function scrollToBottom() {
    nextTick(() => {
        if (messagesContainer.value) {
            messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
        }
    });
}

watch(() => streamingMessage.value?.content, () => {
    scrollToBottom();
});

watch(() => props.activeConversation?.id, () => {
    localMessages.value = [];
    streamingMessage.value = null;
    streamingFiles.value = [];
    systemPromptDraft.value = props.activeConversation?.system_prompt || '';
    showSystemPrompt.value = false;
    nextTick(scrollToBottom);
});

watch(() => props.messages, () => {
    nextTick(scrollToBottom);
}, { immediate: true });
</script>
