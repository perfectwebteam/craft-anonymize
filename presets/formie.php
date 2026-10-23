<?php

use perfectwebteam\anonymize\handlers\FormieSubmissions;

/**
 * Formie 3. Inzendingen houden hun vorm met verzonnen inhoud
 * (FormieSubmissions), verzonden notificaties verliezen hun mail, betalingen
 * hun antwoorden van de provider. De formulieren zelf blijven.
 */
return [
    'handlers' => [FormieSubmissions::class],

    'keep' => [
        'elements' => [
            'elementType:verbb\formie\elements\Form',
        ],
        'tables' => [
            'formie_emailtemplates', 'formie_fieldlayout_pages', 'formie_fieldlayout_rows', 'formie_fieldlayouts',
            'formie_fields', 'formie_forms', 'formie_formtemplates', 'formie_integrations', 'formie_notifications',
            'formie_payments_plans', 'formie_pdftemplates', 'formie_relations', 'formie_statuses', 'formie_stencils',
        ],
    ],

    'elements' => [
        // Een titel van een inzending kan uit haar velden komen.
        'elementType:verbb\formie\elements\Submission' => ['title' => ['format' => 'Inzending {id}']],
        'elementType:verbb\formie\elements\SentNotification' => ['title' => ['format' => 'Notificatie {id}']],
        // Rijen van groepen en herhalingen uit Formie 2. Hun inhoud stond in fmc_*, maar hun
        // zoekwoorden bevatten nog wat er was ingevuld.
        'elementType:verbb\formie\elements\NestedFieldRow' => ['title' => 'keep'],
    ],

    'tables' => [
        'formie_sentnotifications' => [
            'columns' => [
                'title' => ['format' => 'Notificatie {id}'],
                'subject' => 'lorem',
                'to' => 'email@ontvanger',
                'cc' => 'email@cc',
                'bcc' => 'email@bcc',
                'replyTo' => 'email@antwoord',
                'replyToName' => 'fullName@antwoord',
                'from' => 'email@afzender',
                'fromName' => 'fullName@afzender',
                'sender' => 'email@afzender',
                'body' => 'null',
                'htmlBody' => 'null',
                'info' => 'null',
                'message' => 'null',
            ],
        ],
        'formie_payments' => ['columns' => ['message' => 'null', 'note' => 'null', 'response' => 'null', 'redirectUrl' => 'null']],
        'formie_payments_subscriptions' => ['columns' => ['subscriptionData' => 'null']],
        // De inhoudstabellen van Formie 2, na de overstap blijven staan: oude inzendingen.
        'fmc_*' => 'empty',
    ],
];
