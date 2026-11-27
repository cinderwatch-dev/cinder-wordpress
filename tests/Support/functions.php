<?php

/*
 * An unqualified call such as defined() inside a namespace looks for a function of
 * that name in the namespace first. These pass straight through to PHP, but because
 * Patchwork has loaded first, tests can redefine them with Brain Monkey to fake
 * constants, the environment and the few PHP functions that cannot run in a test.
 */
foreach ([
    'Cinderwatch\WordPress',
    'Cinderwatch\WordPress\Config',
    'Cinderwatch\WordPress\Errors',
    'Cinderwatch\WordPress\Health\Probes',
    'Cinderwatch\WordPress\Http',
    'Cinderwatch\WordPress\MuPlugin',
    'Cinderwatch\WordPress\Tracing',
] as $namespace) {
    foreach ([
        'defined', 'constant', 'function_exists', 'getenv', 'http_response_code',
        'error_get_last', 'set_error_handler', 'set_exception_handler', 'register_shutdown_function',
    ] as $function) {
        eval("namespace {$namespace}; function {$function}(...\$arguments) { return \\{$function}(...\$arguments); }");
    }
}
