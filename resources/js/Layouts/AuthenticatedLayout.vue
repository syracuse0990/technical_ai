<script setup>
import { ref, computed, onMounted, onUnmounted, provide } from 'vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useFileActivity, useToastNotifications } from '@/composables/useWebSocket.js';

const showingNavigationDropdown = ref(false);
const page = usePage();

// ── WebSocket: Toast notifications ──────────────
const { toasts, dismiss: dismissToast } = useToastNotifications();

function toastClass(type) {
    switch (type) {
        case 'success': return 'border-emerald-500/30 bg-emerald-950/80 text-emerald-200';
        case 'error': return 'border-red-500/30 bg-red-950/80 text-red-200';
        case 'warning': return 'border-amber-500/30 bg-amber-950/80 text-amber-200';
        default: return 'border-blue-500/30 bg-blue-950/80 text-blue-200';
    }
}

// ── WebSocket: File activity notifications ──────
const { events: fileEvents, dismiss: dismissEvent, clearAll: clearAllEvents } = useFileActivity();
const showNotifications = ref(false);
const notifRef = ref(null);

const unreadCount = computed(() => fileEvents.value.length);

function eventIcon(eventName) {
    if (eventName === 'file.uploaded') return '📄';
    if (eventName === 'file.deleted') return '🗑️';
    if (eventName === 'folder.created') return '📁';
    if (eventName === 'folder.deleted') return '🗑️';
    if (eventName === 'file.status') return '✅';
    return '🔔';
}

function eventLabel(evt) {
    if (evt.message) return evt.message;
    if (evt.event === 'file.status') return `"${evt.original_name}" is now ${evt.status}`;
    return evt.event;
}

function timeAgo(timestamp) {
    const diff = Math.floor((Date.now() - timestamp) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    return Math.floor(diff / 3600) + 'h ago';
}

function closeNotifOnOutside(e) {
    if (notifRef.value && !notifRef.value.contains(e.target)) {
        showNotifications.value = false;
    }
}

onMounted(() => document.addEventListener('click', closeNotifOnOutside));
onUnmounted(() => document.removeEventListener('click', closeNotifOnOutside));

// Provide file activity to child pages so they can react
provide('fileActivityEvents', fileEvents);

// Dark mode
const isDark = ref(false);
onMounted(() => {
    isDark.value = localStorage.getItem('theme') === 'dark' ||
        (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches);
    applyTheme();
});

function toggleDark() {
    isDark.value = !isDark.value;
    localStorage.setItem('theme', isDark.value ? 'dark' : 'light');
    applyTheme();
}

function applyTheme() {
    document.documentElement.classList.toggle('dark', isDark.value);
}

provide('isDark', isDark);
</script>

<template>
    <div class="min-h-screen bg-gray-50 transition-colors duration-300 dark:bg-gray-950">
        <!-- Top Navbar -->
        <nav class="sticky top-0 z-50 border-b border-gray-200/80 bg-white/80 backdrop-blur-xl dark:border-gray-800 dark:bg-gray-900/80">
            <div class="mx-auto flex h-14 items-center justify-between px-4 lg:px-6">
                <!-- Left: Logo + Nav Links -->
                <div class="flex items-center gap-1">
                    <!-- Logo -->
                    <Link :href="route('dashboard')" class="mr-4 flex items-center gap-2.5 rounded-lg px-2 py-1 transition hover:bg-gray-100 dark:hover:bg-gray-800">
                        <img src="/images/logo.png" alt="LeadsTech" class="h-8 w-8 object-contain" />
                        <span class="hidden text-base font-bold text-gray-900 dark:text-white sm:block">LeadsTech</span>
                    </Link>

                    <!-- Nav Links -->
                    <Link :href="route('dashboard')"
                          :class="[
                              'hidden sm:inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-all duration-200',
                              route().current('dashboard')
                                  ? 'bg-agri-50 text-agri-700 dark:bg-agri-900/30 dark:text-agri-400'
                                  : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-white'
                          ]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" /></svg>
                        Files
                    </Link>
                    <Link :href="route('chat.index')"
                          :class="[
                              'hidden sm:inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-all duration-200',
                              route().current('chat.*')
                                  ? 'bg-agri-50 text-agri-700 dark:bg-agri-900/30 dark:text-agri-400'
                                  : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-white'
                          ]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" /></svg>
                        Chat
                    </Link>
                </div>

                <!-- Right: Icons -->
                <div class="flex items-center gap-1">
                    <!-- Notification Bell -->
                    <div ref="notifRef" class="relative">
                        <button @click.stop="showNotifications = !showNotifications"
                                class="relative rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-white">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" /></svg>
                            <span v-if="unreadCount > 0" class="absolute right-1 top-1 flex h-4 w-4 items-center justify-center rounded-full bg-red-500 text-[9px] font-bold text-white">
                                {{ unreadCount > 9 ? '9+' : unreadCount }}
                            </span>
                        </button>

                        <!-- Notification dropdown -->
                        <Transition enter-active-class="transition duration-150 ease-out" enter-from-class="opacity-0 scale-95 -translate-y-1" enter-to-class="opacity-100 scale-100 translate-y-0"
                                    leave-active-class="transition duration-100 ease-in" leave-from-class="opacity-100 scale-100" leave-to-class="opacity-0 scale-95">
                            <div v-if="showNotifications"
                                 class="absolute right-0 top-full z-50 mt-2 w-80 rounded-xl border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-800">
                                <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5 dark:border-gray-700">
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Notifications</h3>
                                    <button v-if="unreadCount > 0" @click="clearAllEvents" class="text-[11px] text-agri-600 hover:text-agri-700 dark:text-agri-400">Clear all</button>
                                </div>
                                <div class="max-h-72 overflow-y-auto">
                                    <div v-if="!fileEvents.length" class="px-4 py-8 text-center">
                                        <svg class="mx-auto h-8 w-8 text-gray-300 dark:text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" /></svg>
                                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">No notifications yet</p>
                                    </div>
                                    <div v-for="evt in [...fileEvents].reverse()" :key="evt.id"
                                         class="flex items-start gap-3 border-b border-gray-50 px-4 py-2.5 transition hover:bg-gray-50 dark:border-gray-700/50 dark:hover:bg-gray-750">
                                        <span class="mt-0.5 text-base">{{ eventIcon(evt.event) }}</span>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-xs text-gray-800 dark:text-gray-200">{{ eventLabel(evt) }}</p>
                                            <p class="mt-0.5 text-[10px] text-gray-400">{{ timeAgo(evt.timestamp) }}</p>
                                        </div>
                                        <button @click="dismissEvent(evt.id)" class="shrink-0 rounded p-0.5 text-gray-300 transition hover:text-gray-500 dark:text-gray-600 dark:hover:text-gray-400">
                                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </Transition>
                    </div>

                    <!-- Dark/Light toggle -->
                    <button @click="toggleDark"
                            class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-white">
                        <!-- Sun (shown in dark mode) -->
                        <svg v-if="isDark" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                        </svg>
                        <!-- Moon (shown in light mode) -->
                        <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                        </svg>
                    </button>

                    <!-- Account Dropdown -->
                    <Dropdown align="right" width="48">
                        <template #trigger>
                            <button class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">
                                <div class="flex h-7 w-7 items-center justify-center rounded-full bg-agri-100 text-xs font-bold text-agri-700 dark:bg-agri-900/50 dark:text-agri-400">
                                    {{ $page.props.auth.user.name?.charAt(0)?.toUpperCase() }}
                                </div>
                                <span class="hidden sm:block">{{ $page.props.auth.user.name }}</span>
                                <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                            </button>
                        </template>
                        <template #content>
                            <div class="px-4 py-2 border-b border-gray-100 dark:border-gray-700">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $page.props.auth.user.name }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $page.props.auth.user.email }}</p>
                            </div>
                            <DropdownLink :href="route('profile.edit')">
                                Profile
                            </DropdownLink>
                            <DropdownLink :href="route('logout')" method="post" as="button">
                                Log Out
                            </DropdownLink>
                        </template>
                    </Dropdown>

                    <!-- Mobile hamburger -->
                    <button @click="showingNavigationDropdown = !showingNavigationDropdown"
                            class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 sm:hidden dark:text-gray-400 dark:hover:bg-gray-800">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path v-if="!showingNavigationDropdown" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                            <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Mobile dropdown -->
            <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0"
                        leave-active-class="transition duration-150 ease-in" leave-from-class="opacity-100 translate-y-0" leave-to-class="opacity-0 -translate-y-2">
                <div v-if="showingNavigationDropdown" class="border-t border-gray-200 bg-white px-4 py-3 sm:hidden dark:border-gray-700 dark:bg-gray-900">
                    <Link :href="route('dashboard')" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" /></svg>
                        Files
                    </Link>
                    <button class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" /></svg>
                        Chat
                    </button>
                </div>
            </Transition>
        </nav>

        <!-- Page Content -->
        <main class="h-[calc(100vh-3.5rem)]">
            <slot />
        </main>

        <!-- Toast Notifications -->
        <div class="fixed top-16 right-4 z-[60] flex flex-col gap-2 w-80">
            <TransitionGroup enter-active-class="transition duration-300 ease-out" enter-from-class="opacity-0 translate-x-8" enter-to-class="opacity-100 translate-x-0"
                             leave-active-class="transition duration-200 ease-in" leave-from-class="opacity-100 translate-x-0" leave-to-class="opacity-0 translate-x-8">
                <div v-for="toast in toasts" :key="toast.id"
                     class="rounded-lg border px-4 py-3 shadow-lg backdrop-blur-sm cursor-pointer"
                     :class="toastClass(toast.type)"
                     @click="dismissToast(toast.id)">
                    <div class="flex items-start gap-3">
                        <div class="mt-0.5">
                            <svg v-if="toast.type === 'success'" class="h-4 w-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                            <svg v-else-if="toast.type === 'error'" class="h-4 w-4 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                            <svg v-else-if="toast.type === 'warning'" class="h-4 w-4 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                            <svg v-else class="h-4 w-4 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium">{{ toast.title }}</p>
                            <p v-if="toast.body" class="text-xs opacity-75 mt-0.5">{{ toast.body }}</p>
                        </div>
                    </div>
                </div>
            </TransitionGroup>
        </div>
    </div>
</template>
