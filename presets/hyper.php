<?php

use verbb\hyper\fields\HyperField;

/**
 * Hyper: links in content zijn navigatie, geen persoonsgegevens. Een project
 * waarin een Hyper-veld wel iemands e-mail of telefoon houdt, geeft dat veld
 * een eigen regel. De caches gaan leeg; Hyper bouwt ze opnieuw op.
 */
return [
    'fieldTypes' => [
        HyperField::class => 'keep',
    ],
    'keep' => [
        // De veldindelingen van de linktypes.
        'elements' => ['elementType:verbb\hyper\links\*'],
    ],
    'tables' => [
        'hyper_element_cache' => 'empty',
        'hyper_field_cache' => 'empty',
    ],
];
