<?php

/** Retour: de 404-statistiek houdt haar URL's, niet wie ze opvroeg. */
return [
    'keep' => [
        'tables' => ['retour_redirects', 'retour_static_redirects'],
    ],
    'tables' => [
        'retour_stats' => ['columns' => ['remoteIp' => 'ip', 'referrerUrl' => 'null', 'userAgent' => 'null']],
    ],
];
