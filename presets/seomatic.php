<?php

use nystudio107\seomatic\fields\SeoSettings;

/** SEOmatic: SEO-instellingen per element en de meta-bundels van de site. */
return [
    'fieldTypes' => [
        SeoSettings::class => 'keep',
    ],
    'keep' => [
        'tables' => ['seomatic_metabundles'],
    ],
];
