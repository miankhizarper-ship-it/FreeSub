<?php

declare(strict_types=1);

/**
 * Public abuse report form.
 *
 * Reports are stored for human review; nothing is taken down automatically.
 */

require_once __DIR__ . '/includes/bootstrap.php';

$errors = errors();
$values = [
    'fqdn' => old('fqdn'),
    'reporter_name' => old('reporter_name'),
    'reporter_email' => old('reporter_email'),
    'category' => old('category', 'phishing'),
    'message' => old('message'),
];

$categories = [
    'phishing' => 'Phishing or credential theft',
    'malware' => 'Malware or unwanted downloads',
    'spam' => 'Spam or bulk unsolicited content',
    'copyright' => 'Copyright or trademark infringement',
    'illegal' => 'Illegal content',
    'other' => 'Something else',
];

if (is_post()) {
    csrf_check();

    if (!rate_limit_hit('abuse-report', 5, 3600)) {
        flash('error', 'Too many reports from this network. Please email us directly instead.');
        redirect('/abuse.php');
    }

    $input = [
        'fqdn' => (string) input('fqdn', ''),
        'reporter_name' => (string) input('reporter_name', ''),
        'reporter_email' => (string) input('reporter_email', ''),
        'category' => (string) input('category', ''),
        'message' => (string) ($_POST['message'] ?? ''),
    ];

    $errors = Validator::abuseReport($input);

    if ($errors === []) {
        $match = Subdomain::findByFqdn($input['fqdn']);

        AbuseReport::create(
            $input['fqdn'],
            $input['reporter_email'],
            $input['category'],
            $input['message'],
            $input['reporter_name'] !== '' ? $input['reporter_name'] : null,
            $match !== null ? (int) $match['id'] : null
        );

        audit_log('abuse.reported', null, ['fqdn' => $input['fqdn'], 'category' => $input['category']], 'abuse_report', null, 'Abuse report submitted');
        rate_limit_clear('abuse-report');
        clear_old_input();

        flash('success', 'Thank you — your report has been logged and will be reviewed by a human.');
        redirect('/abuse.php');
    }

    flash_errors($errors);
    flash_old_input($_POST);
    redirect('/abuse.php');
}

view('abuse', [
    'errors' => $errors,
    'values' => $values,
    'categories' => $categories,
    'pageTitle' => 'Report abuse',
    'metaDescription' => 'Report phishing, malware, spam or illegal content hosted on a FreeSub subdomain.',
], 'layouts/public');
