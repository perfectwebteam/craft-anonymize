<?php

/** Wishlist: lijsten en items verwijzen naar elementen; de sessie en het IP-adres van de lijst gaan. */
return [
    'keep' => [
        'elements' => ['elementType:verbb\wishlist\elements\ListElement', 'elementType:verbb\wishlist\elements\Item'],
        'tables' => ['wishlist_items', 'wishlist_listtypes'],
    ],
    'tables' => [
        'wishlist_lists' => ['columns' => ['lastIp' => 'ip', 'sessionId' => 'null']],
    ],
];
