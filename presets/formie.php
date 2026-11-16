<?php

use perfectwebteam\anonymize\handlers\FormieSubmissions;

/**
 * Formie 3 en 4. Inzendingen houden hun vorm met verzonnen inhoud
 * (FormieSubmissions), verzonden notificaties verliezen hun mail, betalingen
 * hun antwoorden van de provider. De formulieren zelf blijven, maar hun
 * notificaties, geplande rapporten en de integraties staan op de kopie uit.
 * Wat Formie 4 bijhoudt terwijl het een inzending afhandelt, gaat leeg.
 */
return [
    'handlers' => [FormieSubmissions::class],

    'keep' => [
        'elements' => [
            'elementType:verbb\formie\elements\Form',
        ],
        'tables' => [
            'formie_emailtemplates', 'formie_fieldlayout_pages', 'formie_fieldlayout_rows', 'formie_fieldlayouts',
            'formie_fields', 'formie_forms', 'formie_formtemplates', 'formie_payments_plans', 'formie_pdftemplates',
            'formie_relations', 'formie_statuses', 'formie_stencils',
            // Formie 4
            'formie_captcha_providers', 'formie_encryption_settings', 'formie_field_site_overrides', 'formie_form_fields',
            'formie_form_site_overrides', 'formie_form_statuses', 'formie_formgroups', 'formie_reports',
            'formie_spam_settings', 'formie_submission_statuses',
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
        // Een kopie verstuurt geen mail en stuurt niets naar CRM's, nieuwsbrieven of webhooks.
        // De integraties staan ook in project config: `project-config/apply` zet een integratie weer aan
        // als haar config verandert, en met --force allemaal.
        'formie_notifications' => ['columns' => ['enabled' => ['value' => '0']]],
        'formie_integrations' => ['columns' => ['enabled' => ['value' => 'false']]],
        'formie_scheduled_reports' => ['columns' => ['enabled' => ['value' => '0']]],
        // Formie 4 bewaart naast de inhoud nog wat er vooraf was ingevuld (snapshot), en het request
        // (IP-adres, user agent, referrer, cookies) en de bevestigingstekst (metadata).
        'formie_submissions' => ['columns' => ['snapshot' => ['value' => '[]'], 'metadata' => 'null']],
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
        // Wat Formie 4 bijhoudt terwijl het een inzending afhandelt: halve inzendingen, uploads,
        // wat er naar integraties en providers ging en terugkwam, exports en tokens.
        'formie_delivery_attempts' => 'empty',
        'formie_delivery_diagnostics' => 'empty',
        'formie_instance_configs' => 'empty',
        'formie_integration_run_contexts' => 'empty',
        'formie_paymentcapabilities' => 'empty',
        'formie_pending_uploads' => 'empty',
        'formie_report_exports' => 'empty',
        'formie_submission_dispatches' => 'empty',
        'formie_submission_grants' => 'empty',
        'formie_submission_operations' => 'empty',
        'formie_submission_progress' => 'empty',
        'formie_submission_quiz_results' => 'empty',
        'formie_submission_workflow' => 'empty',
        'formie_subscription_diagnostics' => 'empty',
        'formie_webhookreceipts' => 'empty',
        // De inhoudstabellen van Formie 2, na de overstap blijven staan: oude inzendingen.
        'fmc_*' => 'empty',
        // De rest van Formie 2, die Formie 3 niet leest; formie_tokens hield de OAuth-tokens van de integraties.
        'formie_nested' => 'empty',
        'formie_nestedfieldrows' => 'empty',
        'formie_newlayout' => 'empty',
        'formie_newnestedlayout' => 'empty',
        'formie_pagesettings' => 'empty',
        'formie_rows' => 'empty',
        'formie_syncfields' => 'empty',
        'formie_syncs' => 'empty',
        'formie_tokens' => 'empty',
    ],
];
