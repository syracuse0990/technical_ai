import { defineStore } from 'pinia';

export const useSettingsStore = defineStore('settings', {
    state: () => ({
        viewMode: 'tile',
        visibility: 'private',
    }),
    persist: true,
});
