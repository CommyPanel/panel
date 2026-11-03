<?php

// No page on another website may call the panel from a browser: nothing is shared with
// other origins. The token API is for scripts and servers, which do not use CORS.
return [
    'paths' => [],
    'allowed_methods' => [],
    'allowed_origins' => [],
    'allowed_origins_patterns' => [],
    'allowed_headers' => [],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
