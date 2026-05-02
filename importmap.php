<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
    '@symfony/stimulus-bundle' => [
        'path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js',
    ],
    '@tailwindplus/elements' => [
        'version' => '1.0.16',
    ],
    'canvas-confetti' => [
        'version' => '1.9.4',
    ],
    '@googlemaps/js-api-loader' => [
        'version' => '1.16.10',
    ],
    '@symfony/ux-google-map' => [
        'path' => './vendor/symfony/ux-google-map/assets/dist/map_controller.js',
    ],
    '@symfony/ux-live-component' => [
        'path' => './vendor/symfony/ux-live-component/assets/dist/live_controller.js',
    ],
    '@hotwired/turbo' => [
        'version' => '8.0.23',
    ],
    'chart.js/auto' => [
        'version' => '4.5.1',
    ],
    '@kurkle/color' => [
        'version' => '0.3.4',
    ],
    'intl-tel-input' => [
        'path' => './assets/vendor/intl-tel-input/build/js/intlTelInputWithUtils.mjs',
    ],
    'intl-tel-input/css' => [
        'path' => './assets/vendor/intl-tel-input/build/css/intlTelInput.min.css',
        'type' => 'css',
    ],
];
