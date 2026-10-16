<?php

/**
 * Webhooks: verstuurde requests zijn complete berichten met hun antwoorden,
 * en de headers van een webhook houden vaak het token van het andere systeem.
 * De webhooks zelf blijven aan: zet ze op de kopie uit als ze er niet horen.
 */
return [
    'keep' => [
        'tables' => ['webhookgroups'],
    ],
    'tables' => [
        'webhookrequests' => 'empty',
        'webhooks' => ['columns' => ['headers' => 'null']],
    ],
];
