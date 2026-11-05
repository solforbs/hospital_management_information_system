/*
 * Live updates: any element with data-live="queue,lab" inside a Livewire
 * component re-renders that component when one of its channels changes.
 * One tiny request per tab every 3 seconds (paused while the tab is hidden).
 */
const LIVE_INTERVAL_MS = 3000;
let liveVersions = {};
let liveTimer = null;

function liveSubscriptions() {
    const subs = [];
    document.querySelectorAll('[data-live]').forEach((el) => {
        const host = el.closest('[wire\\:id]');
        if (!host) return;
        const channels = el.getAttribute('data-live').split(',').map((c) => c.trim()).filter(Boolean);
        subs.push({ id: host.getAttribute('wire:id'), channels });
    });
    return subs;
}

async function livePoll() {
    if (document.hidden) return;
    const subs = liveSubscriptions();
    if (!subs.length) return;

    const channels = [...new Set(subs.flatMap((s) => s.channels))];
    let versions;
    try {
        const res = await fetch('/live?c=' + encodeURIComponent(channels.join(',')), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!res.ok) return;
        versions = await res.json();
    } catch {
        return;
    }

    const changed = new Set(channels.filter((c) => liveVersions[c] !== undefined && liveVersions[c] !== versions[c]));
    liveVersions = { ...liveVersions, ...versions };

    if (!changed.size || !window.Livewire) return;

    subs.filter((s) => s.channels.some((c) => changed.has(c))).forEach((s) => {
        const component = window.Livewire.find(s.id);
        if (component) component.$refresh();
    });
}

function startLive() {
    clearInterval(liveTimer);
    liveVersions = {};
    livePoll();
    liveTimer = setInterval(livePoll, LIVE_INTERVAL_MS);
}

// Livewire fires this on the first load and after every wire:navigate.
document.addEventListener('livewire:navigated', startLive);
