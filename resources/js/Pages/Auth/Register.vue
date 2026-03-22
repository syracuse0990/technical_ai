<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, onMounted, computed } from 'vue';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const showPassword = ref(false);
const formVisible = ref(false);

onMounted(() => {
    setTimeout(() => { formVisible.value = true; }, 100);
});

const passwordStrength = computed(() => {
    const p = form.password;
    if (!p) return { level: 0, label: '', color: '' };
    let score = 0;
    if (p.length >= 8) score++;
    if (/[A-Z]/.test(p)) score++;
    if (/[0-9]/.test(p)) score++;
    if (/[^A-Za-z0-9]/.test(p)) score++;

    const levels = [
        { level: 1, label: 'Weak', color: 'bg-red-400' },
        { level: 2, label: 'Fair', color: 'bg-amber-400' },
        { level: 3, label: 'Good', color: 'bg-agri-400' },
        { level: 4, label: 'Strong', color: 'bg-agri-600' },
    ];
    return levels[score - 1] || { level: 0, label: '', color: '' };
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Create Account" />

        <!-- Header -->
        <div class="mb-8 text-center lg:text-left">
            <h2 class="text-2xl font-bold text-gray-900">Join LeadsTech</h2>
            <p class="mt-2 text-sm text-gray-500">Start organizing your agricultural documents with AI</p>
        </div>

        <form @submit.prevent="submit" class="space-y-5">
            <!-- Name -->
            <div :class="['transition-all duration-500', formVisible ? 'animate-fade-in-up' : 'opacity-0']" style="animation-delay: 0.1s">
                <label for="name" class="label-modern">
                    <span class="flex items-center gap-1.5">
                        <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                        Full name
                    </span>
                </label>
                <input
                    id="name"
                    type="text"
                    class="input-modern"
                    v-model="form.name"
                    required
                    autofocus
                    autocomplete="name"
                    placeholder="Juan Dela Cruz"
                />
                <InputError class="mt-1.5" :message="form.errors.name" />
            </div>

            <!-- Email -->
            <div :class="['transition-all duration-500', formVisible ? 'animate-fade-in-up' : 'opacity-0']" style="animation-delay: 0.15s">
                <label for="email" class="label-modern">
                    <span class="flex items-center gap-1.5">
                        <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                        Email address
                    </span>
                </label>
                <input
                    id="email"
                    type="email"
                    class="input-modern"
                    v-model="form.email"
                    required
                    autocomplete="username"
                    placeholder="you@example.com"
                />
                <InputError class="mt-1.5" :message="form.errors.email" />
            </div>

            <!-- Password -->
            <div :class="['transition-all duration-500', formVisible ? 'animate-fade-in-up' : 'opacity-0']" style="animation-delay: 0.2s">
                <label for="password" class="label-modern">
                    <span class="flex items-center gap-1.5">
                        <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                        Password
                    </span>
                </label>
                <div class="relative">
                    <input
                        id="password"
                        :type="showPassword ? 'text' : 'password'"
                        class="input-modern pr-10"
                        v-model="form.password"
                        required
                        autocomplete="new-password"
                        placeholder="Create a strong password"
                    />
                    <button type="button" @click="showPassword = !showPassword"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 transition-colors hover:text-gray-600">
                        <svg v-if="!showPassword" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                        <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.542 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                        </svg>
                    </button>
                </div>
                <!-- Password strength indicator -->
                <Transition enter-active-class="transition duration-300" enter-from-class="opacity-0" enter-to-class="opacity-100">
                    <div v-if="form.password" class="mt-2 flex items-center gap-2">
                        <div class="flex flex-1 gap-1">
                            <div v-for="i in 4" :key="i"
                                 :class="['h-1 flex-1 rounded-full transition-all duration-300',
                                          i <= passwordStrength.level ? passwordStrength.color : 'bg-gray-200']" />
                        </div>
                        <span class="text-xs font-medium" :class="{
                            'text-red-500': passwordStrength.level === 1,
                            'text-amber-500': passwordStrength.level === 2,
                            'text-agri-500': passwordStrength.level === 3,
                            'text-agri-700': passwordStrength.level === 4,
                        }">{{ passwordStrength.label }}</span>
                    </div>
                </Transition>
                <InputError class="mt-1.5" :message="form.errors.password" />
            </div>

            <!-- Confirm Password -->
            <div :class="['transition-all duration-500', formVisible ? 'animate-fade-in-up' : 'opacity-0']" style="animation-delay: 0.25s">
                <label for="password_confirmation" class="label-modern">
                    <span class="flex items-center gap-1.5">
                        <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                        Confirm password
                    </span>
                </label>
                <input
                    id="password_confirmation"
                    type="password"
                    class="input-modern"
                    v-model="form.password_confirmation"
                    required
                    autocomplete="new-password"
                    placeholder="Re-enter your password"
                />
                <InputError class="mt-1.5" :message="form.errors.password_confirmation" />
            </div>

            <!-- Submit -->
            <div :class="['pt-1 transition-all duration-500', formVisible ? 'animate-fade-in-up' : 'opacity-0']" style="animation-delay: 0.3s">
                <button
                    type="submit"
                    class="btn-primary w-full"
                    :disabled="form.processing"
                >
                    <svg v-if="form.processing" class="h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                    </svg>
                    <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                    </svg>
                    {{ form.processing ? 'Creating account...' : 'Create account' }}
                </button>
            </div>
        </form>

        <!-- Divider -->
        <div class="mt-8 flex items-center gap-3">
            <div class="h-px flex-1 bg-gray-200" />
            <span class="text-xs text-gray-400">Already have an account?</span>
            <div class="h-px flex-1 bg-gray-200" />
        </div>

        <!-- Login link -->
        <div class="mt-4 text-center">
            <Link :href="route('login')"
                  class="inline-flex items-center gap-1.5 rounded-xl border border-gray-200 bg-white/60 px-5 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition-all duration-300 hover:border-agri-300 hover:bg-agri-50 hover:text-agri-700 hover:shadow-md">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
                </svg>
                Sign in instead
            </Link>
        </div>
    </GuestLayout>
</template>
