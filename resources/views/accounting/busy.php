<?php /* Centre overlay of a dialog while a job runs: spinner → tick → closes. Driven by public/assets/accounting.js (AcctBusy). Put it last inside a positioned <dialog>. */ ?>
<div data-busy hidden class="absolute inset-0 z-20 flex flex-col items-center justify-center gap-3 rounded-xl bg-white/95 text-center backdrop-blur-sm" role="status" aria-live="polite">
    <div class="relative size-24">
        <svg data-busy-spin class="absolute inset-0 size-24 animate-spin text-signal-600" viewBox="0 0 100 100" fill="none" aria-hidden="true">
            <circle cx="50" cy="50" r="40" stroke="currentColor" stroke-opacity=".15" stroke-width="7"/><path d="M50 10a40 40 0 0 1 40 40" stroke="currentColor" stroke-width="7" stroke-linecap="round"/>
        </svg>
        <svg data-busy-icon class="absolute inset-0 m-auto size-9 text-signal-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2 4 14h7l-1 8 9-12h-7l1-8Z"/></svg>
        <svg data-busy-ok style="display:none" class="absolute inset-0 size-24 text-emerald-600" viewBox="0 0 100 100" fill="none" stroke="currentColor" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle class="acme-circle" cx="50" cy="50" r="40"/><path class="acme-check" d="M30 52l14 14 27-30"/>
        </svg>
    </div>
    <p data-busy-text class="text-base font-semibold text-graphite-900"></p>
    <p data-busy-sub class="min-h-5 text-sm text-steel"></p>
</div>
<style>
@keyframes acme-draw { to { stroke-dashoffset: 0 } }
.acme-circle { stroke-dasharray: 252; stroke-dashoffset: 252; animation: acme-draw .55s ease forwards }
.acme-check { stroke-dasharray: 60; stroke-dashoffset: 60; animation: acme-draw .35s .45s ease forwards }
</style>
