import Pusher from 'pusher-js';
import { ref, onUnmounted } from 'vue';

let pusherInstance = null;

function getPusher() {
    if (!pusherInstance) {
        pusherInstance = new Pusher(import.meta.env.VITE_WEBSOCKET_APP_KEY, {
            wsHost: import.meta.env.VITE_WEBSOCKET_HOST,
            wsPort: 443,
            wssPort: 443,
            forceTLS: true,
            disableStats: true,
            enabledTransports: ['ws', 'wss'],
            cluster: 'mt1',
        });

        pusherInstance.connection.bind('connected', () => {
            console.log('[WS] Connected to', import.meta.env.VITE_WEBSOCKET_HOST);
        });
        pusherInstance.connection.bind('error', (err) => {
            console.warn('[WS] Connection error:', err);
        });
        pusherInstance.connection.bind('disconnected', () => {
            console.warn('[WS] Disconnected');
        });
    }
    return pusherInstance;
}

function parseData(data) {
    return typeof data === 'string' ? JSON.parse(data) : data;
}

/**
 * Generic channel subscription composable.
 * Auto-unsubscribes when the component unmounts.
 */
export function useWebSocket(channelName) {
    const pusher = getPusher();
    const channel = pusher.subscribe(channelName);
    const bindings = [];

    function listen(eventName, callback) {
        const handler = (data) => callback(parseData(data));
        channel.bind(eventName, handler);
        bindings.push({ event: eventName, handler });
    }

    onUnmounted(() => {
        bindings.forEach(({ event, handler }) => channel.unbind(event, handler));
        pusher.unsubscribe(channelName);
    });

    return { channel, listen };
}

// ── File Activity: real-time file/folder events ─────────
// Shared state across page navigations.
const fileActivityEvents = ref([]);
let fileActivityBound = false;
let fileActivityId = 0;

export function useFileActivity() {
    if (!fileActivityBound) {
        fileActivityBound = true;
        const pusher = getPusher();
        const channel = pusher.subscribe('file-activity');

        const events = ['file.uploaded', 'file.deleted', 'folder.created', 'folder.deleted', 'file.status'];
        events.forEach((eventName) => {
            channel.bind(eventName, (raw) => {
                const data = parseData(raw);
                const id = ++fileActivityId;
                fileActivityEvents.value = [
                    ...fileActivityEvents.value,
                    { id, event: eventName, ...data, timestamp: Date.now() },
                ];
                // Keep only last 50 events
                if (fileActivityEvents.value.length > 50) {
                    fileActivityEvents.value = fileActivityEvents.value.slice(-50);
                }
            });
        });
    }

    function dismiss(id) {
        fileActivityEvents.value = fileActivityEvents.value.filter((e) => e.id !== id);
    }

    function clearAll() {
        fileActivityEvents.value = [];
    }

    return { events: fileActivityEvents, dismiss, clearAll };
}

/**
 * Global toast notifications. Server-pushed toasts.
 */
export function useToastNotifications() {
    const toasts = ref([]);
    let toastId = 0;

    const { listen } = useWebSocket('notifications');

    listen('toast', (data) => {
        const id = ++toastId;
        toasts.value.push({ id, ...data });
        setTimeout(() => {
            toasts.value = toasts.value.filter((t) => t.id !== id);
        }, 6000);
    });

    function dismiss(id) {
        toasts.value = toasts.value.filter((t) => t.id !== id);
    }

    return { toasts, dismiss };
}
