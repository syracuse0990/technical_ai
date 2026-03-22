<script setup>
import { Link } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';

const loaded = ref(false);
onMounted(() => {
    loaded.value = true;
});
</script>

<template>
    <div class="flex min-h-screen">
        <!-- LEFT SIDE — Animated Agri Illustration -->
        <div class="relative hidden w-1/2 overflow-hidden bg-gradient-to-br from-agri-800 via-agri-700 to-emerald-900 lg:flex lg:flex-col lg:items-center lg:justify-center">
            <!-- Animated background blobs -->
            <div class="pointer-events-none absolute inset-0">
                <div class="absolute -left-24 -top-24 h-96 w-96 rounded-full bg-agri-600/20 blur-3xl animate-float" />
                <div class="absolute -bottom-32 -right-20 h-80 w-80 rounded-full bg-emerald-500/15 blur-3xl animate-float-slow" />
                <div class="absolute left-1/3 top-1/4 h-64 w-64 rounded-full bg-amber-500/10 blur-3xl animate-float-slower" />
            </div>

            <!-- Dot pattern -->
            <div class="absolute inset-0 opacity-[0.06]"
                 style="background-image: radial-gradient(circle, #fff 1px, transparent 1px); background-size: 30px 30px;" />

            <!-- Rice Field Scene SVG -->
            <div class="relative z-10 w-full max-w-2xl px-6" :class="[loaded ? 'animate-fade-in-up' : 'opacity-0']">
                <svg viewBox="0 0 500 400" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-full drop-shadow-2xl">
                    <!-- Sky gradient -->
                    <defs>
                        <linearGradient id="skyGrad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#065f46" stop-opacity="0.3" />
                            <stop offset="100%" stop-color="#064e3b" stop-opacity="0.1" />
                        </linearGradient>
                        <linearGradient id="fieldGrad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#22c55e" />
                            <stop offset="100%" stop-color="#15803d" />
                        </linearGradient>
                        <linearGradient id="waterGrad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#67e8f9" stop-opacity="0.3" />
                            <stop offset="100%" stop-color="#22d3ee" stop-opacity="0.15" />
                        </linearGradient>
                    </defs>

                    <!-- Mountains in background -->
                    <path d="M0 200 L80 120 L160 180 L220 100 L300 160 L380 90 L440 150 L500 130 L500 250 L0 250Z"
                          fill="#166534" opacity="0.3" />
                    <path d="M0 220 L100 160 L180 200 L260 140 L340 190 L420 130 L500 170 L500 260 L0 260Z"
                          fill="#15803d" opacity="0.25" />

                    <!-- Sun -->
                    <circle cx="400" cy="80" r="35" fill="#fbbf24" opacity="0.6" class="animate-pulse-soft" />
                    <circle cx="400" cy="80" r="25" fill="#fcd34d" opacity="0.8" />

                    <!-- Rice paddy water terraces -->
                    <path d="M0 260 Q125 240 250 255 Q375 270 500 250 L500 280 Q375 295 250 280 Q125 265 0 285Z"
                          fill="url(#waterGrad)" class="animate-pulse-soft" />
                    <path d="M0 285 Q125 265 250 280 Q375 295 500 275 L500 310 Q375 325 250 310 Q125 295 0 315Z"
                          fill="url(#waterGrad)" style="animation-delay: -1.5s" class="animate-pulse-soft" />

                    <!-- Rice field rows -->
                    <g class="animate-sway" style="transform-origin: bottom center;">
                        <!-- Row 1 -->
                        <line x1="60" y1="260" x2="60" y2="230" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="60" cy="225" rx="6" ry="10" fill="#22c55e" transform="rotate(-15 60 225)" />
                        <line x1="90" y1="258" x2="90" y2="225" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="90" cy="220" rx="6" ry="10" fill="#16a34a" transform="rotate(10 90 220)" />
                        <line x1="120" y1="255" x2="120" y2="222" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="120" cy="217" rx="6" ry="10" fill="#22c55e" transform="rotate(-8 120 217)" />
                        <line x1="150" y1="253" x2="150" y2="220" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="150" cy="215" rx="6" ry="10" fill="#16a34a" transform="rotate(12 150 215)" />
                        <line x1="180" y1="256" x2="180" y2="223" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="180" cy="218" rx="6" ry="10" fill="#22c55e" transform="rotate(-5 180 218)" />
                        <line x1="210" y1="258" x2="210" y2="228" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="210" cy="223" rx="6" ry="10" fill="#16a34a" transform="rotate(8 210 223)" />
                        <line x1="240" y1="260" x2="240" y2="230" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="240" cy="225" rx="6" ry="10" fill="#22c55e" transform="rotate(-12 240 225)" />
                        <line x1="270" y1="258" x2="270" y2="225" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="270" cy="220" rx="6" ry="10" fill="#16a34a" transform="rotate(6 270 220)" />
                        <line x1="300" y1="255" x2="300" y2="222" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="300" cy="217" rx="6" ry="10" fill="#22c55e" transform="rotate(-10 300 217)" />
                        <line x1="330" y1="253" x2="330" y2="223" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="330" cy="218" rx="6" ry="10" fill="#16a34a" transform="rotate(15 330 218)" />
                        <line x1="360" y1="255" x2="360" y2="225" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="360" cy="220" rx="6" ry="10" fill="#22c55e" transform="rotate(-7 360 220)" />
                        <line x1="390" y1="258" x2="390" y2="228" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="390" cy="223" rx="6" ry="10" fill="#16a34a" transform="rotate(9 390 223)" />
                        <line x1="420" y1="255" x2="420" y2="222" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="420" cy="217" rx="6" ry="10" fill="#22c55e" transform="rotate(-13 420 217)" />
                        <line x1="450" y1="252" x2="450" y2="220" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="450" cy="215" rx="6" ry="10" fill="#16a34a" transform="rotate(5 450 215)" />
                    </g>

                    <!-- Row 2 rice plants -->
                    <g class="animate-sway" style="transform-origin: bottom center; animation-delay: -1s;">
                        <line x1="75" y1="290" x2="75" y2="260" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="75" cy="255" rx="5" ry="9" fill="#22c55e" transform="rotate(10 75 255)" />
                        <line x1="105" y1="287" x2="105" y2="255" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="105" cy="250" rx="5" ry="9" fill="#16a34a" transform="rotate(-8 105 250)" />
                        <line x1="135" y1="285" x2="135" y2="253" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="135" cy="248" rx="5" ry="9" fill="#22c55e" transform="rotate(12 135 248)" />
                        <line x1="165" y1="288" x2="165" y2="256" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="165" cy="251" rx="5" ry="9" fill="#16a34a" transform="rotate(-6 165 251)" />
                        <line x1="195" y1="290" x2="195" y2="258" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="195" cy="253" rx="5" ry="9" fill="#22c55e" transform="rotate(7 195 253)" />
                        <line x1="225" y1="288" x2="225" y2="256" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="225" cy="251" rx="5" ry="9" fill="#16a34a" transform="rotate(-11 225 251)" />
                        <line x1="255" y1="285" x2="255" y2="253" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="255" cy="248" rx="5" ry="9" fill="#22c55e" transform="rotate(9 255 248)" />
                        <line x1="285" y1="288" x2="285" y2="258" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="285" cy="253" rx="5" ry="9" fill="#16a34a" transform="rotate(-5 285 253)" />
                        <line x1="315" y1="290" x2="315" y2="260" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="315" cy="255" rx="5" ry="9" fill="#22c55e" transform="rotate(14 315 255)" />
                        <line x1="345" y1="288" x2="345" y2="255" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="345" cy="250" rx="5" ry="9" fill="#16a34a" transform="rotate(-8 345 250)" />
                        <line x1="375" y1="285" x2="375" y2="253" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="375" cy="248" rx="5" ry="9" fill="#22c55e" transform="rotate(6 375 248)" />
                        <line x1="405" y1="288" x2="405" y2="258" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="405" cy="253" rx="5" ry="9" fill="#16a34a" transform="rotate(-10 405 253)" />
                        <line x1="435" y1="290" x2="435" y2="260" stroke="#4ade80" stroke-width="2" />
                        <ellipse cx="435" cy="255" rx="5" ry="9" fill="#22c55e" transform="rotate(8 435 255)" />
                    </g>

                    <!-- Foreground ground -->
                    <path d="M0 310 Q125 295 250 310 Q375 325 500 305 L500 400 L0 400Z" fill="#15803d" opacity="0.5" />
                    <path d="M0 340 Q125 325 250 340 Q375 355 500 335 L500 400 L0 400Z" fill="#166534" opacity="0.4" />

                    <!-- Small agri-chemical spray bottle floating -->
                    <g class="animate-float" style="animation-delay: -2s;">
                        <rect x="65" y="135" width="24" height="35" rx="4" fill="#f0fdf4" opacity="0.9" />
                        <rect x="72" y="125" width="10" height="14" rx="2" fill="#bbf7d0" />
                        <path d="M82 132 L95 125" stroke="#86efac" stroke-width="2" stroke-linecap="round" />
                        <circle cx="95" cy="123" r="3" fill="#4ade80" opacity="0.6" />
                        <rect x="70" y="148" width="14" height="3" rx="1" fill="#22c55e" opacity="0.6" />
                        <text x="77" y="160" text-anchor="middle" fill="#15803d" font-size="6" font-weight="bold">NPK</text>
                    </g>

                    <!-- Floating fertilizer bag -->
                    <g class="animate-float-slow" style="animation-delay: -3s;">
                        <rect x="360" y="140" width="30" height="38" rx="3" fill="#fef3c7" opacity="0.85" />
                        <rect x="362" y="142" width="26" height="10" rx="1" fill="#f59e0b" opacity="0.4" />
                        <text x="375" y="168" text-anchor="middle" fill="#92400e" font-size="7" font-weight="bold">FERT</text>
                        <path d="M365 178 L370 185 M375 178 L375 187 M385 178 L380 185" stroke="#d97706" stroke-width="1" stroke-linecap="round" opacity="0.4" />
                    </g>

                    <!-- Floating leaf/plant icon -->
                    <g class="animate-float-slower">
                        <path d="M230 100 C230 100 210 120 220 140 C230 120 250 120 230 100Z" fill="#4ade80" opacity="0.7" />
                        <line x1="225" y1="135" x2="225" y2="155" stroke="#22c55e" stroke-width="2" opacity="0.5" />
                    </g>

                    <!-- Floating water droplets -->
                    <circle cx="160" cy="170" r="5" fill="#67e8f9" opacity="0.4" class="animate-float" style="animation-delay: -4s;" />
                    <circle cx="320" cy="120" r="4" fill="#67e8f9" opacity="0.3" class="animate-float-slow" style="animation-delay: -1s;" />
                    <circle cx="440" cy="180" r="3" fill="#67e8f9" opacity="0.35" class="animate-float" style="animation-delay: -5s;" />
                </svg>
            </div>

            <!-- Left panel branding text -->
            <div class="relative z-10 mt-8 max-w-lg px-6 text-center" :class="[loaded ? 'animate-fade-in-up' : 'opacity-0']" style="animation-delay: 0.3s">
                <div class="mb-4 flex items-center justify-center gap-3">
                    <img src="/images/logo.png" alt="LeadsTech Logo" class="h-14 w-14 object-contain drop-shadow-lg" />
                    <h1 class="text-4xl font-extrabold text-white tracking-tight">LeadsTech</h1>
                </div>
                <p class="text-base font-medium text-agri-200">
                    AI-Powered File Management for Agriculture
                </p>
                <p class="mt-3 text-sm text-agri-300/70 leading-relaxed">
                    Store, search, and summarize your agricultural documents — from crop reports to chemical formulations — all powered by intelligent AI.
                </p>

                <!-- Feature pills -->
                <div class="mt-6 flex flex-wrap items-center justify-center gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1.5 text-xs font-medium text-agri-100 backdrop-blur-sm">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                        AI Search
                    </span>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1.5 text-xs font-medium text-agri-100 backdrop-blur-sm">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                        Summaries
                    </span>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1.5 text-xs font-medium text-agri-100 backdrop-blur-sm">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z" /></svg>
                        Cloud Storage
                    </span>
                </div>
            </div>
        </div>

        <!-- RIGHT SIDE — Form -->
        <div class="relative flex w-full flex-col items-center justify-center bg-gradient-to-br from-gray-50 via-white to-agri-50/30 px-4 py-8 sm:px-8 lg:w-1/2">
            <!-- Subtle background pattern -->
            <div class="pointer-events-none absolute inset-0 opacity-[0.03]"
                 style="background-image: radial-gradient(circle, #166534 1px, transparent 1px); background-size: 32px 32px;" />

            <!-- Small floating accents -->
            <div class="pointer-events-none absolute -right-10 top-20 h-48 w-48 rounded-full bg-agri-100/30 blur-3xl animate-float-slow" />
            <div class="pointer-events-none absolute -left-10 bottom-20 h-40 w-40 rounded-full bg-emerald-100/20 blur-3xl animate-float" />

            <!-- Mobile logo (shown on small screens) -->
            <div class="mb-6 flex flex-col items-center lg:hidden" :class="[loaded ? 'animate-fade-in-down' : 'opacity-0']">
                <Link href="/" class="flex items-center gap-2">
                    <img src="/images/logo.png" alt="LeadsTech Logo" class="h-10 w-10 object-contain" />
                    <span class="text-xl font-bold text-agri-800">LeadsTech</span>
                </Link>
                <p class="mt-1 text-xs text-agri-600/50">LeadsTech File Management</p>
            </div>

            <!-- Form card -->
            <div class="relative z-10 w-full max-w-md" :class="[loaded ? 'animate-fade-in-up' : 'opacity-0']">
                <div class="auth-glass rounded-2xl p-8 sm:p-10">
                    <slot />
                </div>

                <!-- Footer -->
                <p class="mt-6 text-center text-xs text-gray-400">
                    &copy; {{ new Date().getFullYear() }} LeadsTech &mdash; AI-Powered Document Intelligence
                </p>
            </div>
        </div>
    </div>
</template>
