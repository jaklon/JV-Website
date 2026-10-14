<?php
// Copy to chat.config.php OUTSIDE public_html; updates never replace it.
return [
    'enabled' => false,
    'service_url' => '', // Exact https://jv-chat-....run.app URL, no path.
    'credentials_file' => __DIR__ . '/chat-invoker.json', // Never included in a ZIP.
    'readiness_file' => __DIR__ . '/chat.readiness.json',
];
