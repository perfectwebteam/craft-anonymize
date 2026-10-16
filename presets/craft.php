<?php

/**
 * Craft's own tables: altijd aan, onder elk project. Een project overschrijft
 * wat het anders wil, per tabel of per kolom.
 *
 * Gebruikers blijven alleen bewaard als het project ze aanwijst
 * (keep.users); die houden dan ook hun wachtwoord, 2FA, passkeys en
 * SSO-koppelingen. Alle andere krijgen een verzonnen naam en e-mailadres en
 * géén wachtwoord: inloggen als hen gaat via impersonation.
 */
return [
    'keep' => [
        // Structuur, instellingen en koppelingen: geen vrije tekst van personen.
        'tables' => [
            'announcements', 'bulkopevents', 'categories', 'categorygroups', 'categorygroups_sites', 'changedattributes',
            'changedfields', 'contentblocks', 'elements_bulkops', 'elements_owners', 'entries_authors', 'entrytypes',
            'fieldlayouts', 'fields', 'globalsets', 'gqlschemas', 'imagetransforms', 'info', 'migrations', 'plugins',
            'projectconfig', 'relations', 'resourcepaths', 'searchindexqueue', 'searchindexqueue_fields', 'sections',
            'sections_entrytypes', 'sections_sites', 'sequences', 'shunnedmessages', 'sitegroups', 'sites',
            'structureelements', 'structures', 'systemmessages', 'taggroups', 'tags', 'usergroups', 'usergroups_users',
            'userpermissions', 'userpermissions_usergroups', 'userpermissions_users', 'userpreferences', 'volumefolders',
            'volumes', 'widgets',
        ],
    ],

    'elements' => [
        'address' => ['title' => ['format' => 'Adres {id}']],
    ],

    'tables' => [
        'users' => [
            'columns' => [
                'username' => ['format' => '{firstName@persoon|lower}.{surname@persoon|lower}.{id}@example.invalid'],
                'email' => ['format' => '{firstName@persoon|lower}.{surname@persoon|lower}.{id}@example.invalid'],
                'fullName' => 'fullName@persoon',
                'firstName' => 'firstName@persoon',
                'lastName' => 'lastName@persoon',
                'unverifiedEmail' => 'null',
                'password' => 'null',
                'verificationCode' => 'null',
                'verificationCodeIssuedDate' => 'null',
                'lastLoginAttemptIp' => 'null',
                'photoId' => 'null',
            ],
        ],
        'addresses' => [
            'columns' => [
                'addressLine1' => 'streetAddress@adres',
                'addressLine2' => 'null',
                'addressLine3' => 'null',
                'organization' => 'company',
                'organizationTaxId' => 'null',
                'fullName' => 'fullName@persoon',
                'firstName' => 'firstName@persoon',
                'lastName' => 'lastName@persoon',
                'postalCode' => 'postcode@adres',
                'locality' => 'city@adres',
                'dependentLocality' => 'null',
                'administrativeArea' => 'null',
                'sortingCode' => 'null',
                'latitude' => 'latitude@adres',
                'longitude' => 'longitude@adres',
            ],
        ],

        // Inloggegevens: alleen die van bewaarde gebruikers gaan mee.
        'authenticator' => ['keepRows' => ['userId' => 'keptUsers'], 'dropRows' => true],
        'recoverycodes' => ['keepRows' => ['userId' => 'keptUsers'], 'dropRows' => true],
        'webauthn' => ['keepRows' => ['userId' => 'keptUsers'], 'dropRows' => true],
        'sso_identities' => ['keepRows' => ['userId' => 'keptUsers'], 'dropRows' => true],
        'craftidtokens' => ['keepRows' => ['userId' => 'keptUsers'], 'dropRows' => true],
        // GraphQL-tokens van productie werken niet op de kopie.
        'gqltokens' => ['keepRows' => ['accessToken' => ['__PUBLIC__']], 'columns' => ['accessToken' => 'token']],

        // Notities bij concepten en revisies zijn vrije tekst.
        'drafts' => ['columns' => ['notes' => 'null', 'name' => ['value' => 'Concept']]],
        'revisions' => ['columns' => ['notes' => 'null']],

        // Sessies, tokens, de wachtrij en logs: van productie, en van niemand op de kopie.
        'sessions' => 'empty',
        'tokens' => 'empty',
        'phpsessions' => 'empty',
        'cache' => 'empty',
        'queue' => 'empty',
        'deprecationerrors' => 'empty',
        'assetindexdata' => 'empty',
        'assetindexingsessions' => 'empty',
        'imagetransformindex' => 'empty',
        'elementactivity' => 'empty',

        // Van Craft 4 blijven staan na de overstap; Craft 5 leest ze niet, en ze houden oude veldwaarden.
        'content' => 'empty',
        'matrixcontent_*' => 'empty',
    ],
];
