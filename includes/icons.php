<?php

function app_icon(string $name, string $class = 'app-icon'): string {
    $icons = [
        'brand' => '<circle cx="10.5" cy="10.5" r="5.5"/><path d="m15 15 5 5"/><path d="M10.5 8v5M8 10.5h5"/>',
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
        'sparkles' => '<path d="m12 3-1.2 3.1a3 3 0 0 1-1.7 1.7L6 9l3.1 1.2a3 3 0 0 1 1.7 1.7L12 15l1.2-3.1a3 3 0 0 1 1.7-1.7L18 9l-3.1-1.2a3 3 0 0 1-1.7-1.7L12 3Z"/><path d="m5 15-.6 1.4A2.5 2.5 0 0 1 3 17.8l-1.4.6L3 19a2.5 2.5 0 0 1 1.4 1.4L5 22l.6-1.6A2.5 2.5 0 0 1 7 19l1.6-.6-1.6-.6a2.5 2.5 0 0 1-1.4-1.4L5 15Z"/><path d="m19 2-.4 1a2 2 0 0 1-1.1 1.1l-1 .4 1 .4A2 2 0 0 1 18.6 6L19 7l.4-1a2 2 0 0 1 1.1-1.1l1-.4-1-.4A2 2 0 0 1 19.4 3L19 2Z"/>',
        'tags' => '<path d="M20.6 13.6 11 4H4v7l9.6 9.6a2 2 0 0 0 2.8 0l4.2-4.2a2 2 0 0 0 0-2.8Z"/><circle cx="7.5" cy="7.5" r="1"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'activity' => '<path d="M3 12h4l2-7 4 14 2-7h6"/>',
        'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v7c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12v7c0 1.7 3.6 3 8 3s8-1.3 8-3v-7"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.1.1l2-2a5 5 0 0 0-7.1-7.1l-1.1 1.1"/><path d="M14 11a5 5 0 0 0-7.1-.1l-2 2A5 5 0 0 0 12 20l1.1-1.1"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.6v-.2h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'lock' => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/>',
        'card' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h2"/>',
        'logout' => '<path d="M10 17l5-5-5-5M15 12H3"/><path d="M14 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"/>',
        'play' => '<circle cx="12" cy="12" r="9"/><path d="m10 8 6 4-6 4V8Z"/>',
        'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'arrow-left' => '<path d="M19 12H5M11 18l-6-6 6-6"/>',
        'download' => '<path d="M12 3v12M7 10l5 5 5-5"/><path d="M5 21h14"/>',
        'building' => '<path d="M4 21V5l8-3 8 3v16M9 21v-4h6v4M8 8h1M15 8h1M8 12h1M15 12h1"/>',
        'briefcase' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18M10 12v2h4v-2"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
        'alert' => '<path d="M10.3 4.2 2.8 17a2 2 0 0 0 1.7 3h15a2 2 0 0 0 1.7-3L13.7 4.2a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        'x' => '<path d="m6 6 12 12M18 6 6 18"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'minus' => '<path d="M5 12h14"/>',
        'pencil' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/>',
        'code' => '<path d="m8 9-4 3 4 3M16 9l4 3-4 3M14 5l-4 14"/>',
        'server' => '<rect x="3" y="4" width="18" height="6" rx="2"/><rect x="3" y="14" width="18" height="6" rx="2"/><path d="M7 7h.01M7 17h.01"/>',
        'wrench' => '<path d="M14.7 6.3a4 4 0 0 0-5-5L12 3.6 8.4 7.2 6.1 5a4 4 0 0 0 5 5L4 17.1A2.1 2.1 0 1 0 6.9 20l7.1-7.1a4 4 0 0 0 5-5L16.7 10 13 6.4l1.7-1.7Z"/>',
        'handshake' => '<path d="M11 17 7 21l-5-5 4-4M13 7l4-4 5 5-4 4M8 12l4-4 4 4-4 4-4-4Z"/>',
        'construction' => '<path d="M4 21v-7M20 21v-7M2 14h20L18 6H6l-4 8ZM7 6l3 8M17 6l-3 8"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
    ];
    $body = $icons[$name] ?? $icons['info'];
    $safeClass = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');
    return '<svg class="' . $safeClass . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $body . '</svg>';
}
