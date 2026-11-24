<?php

declare(strict_types=1);

/**
 * Sign in.
 */

require_once __DIR__ . '/includes/bootstrap.php';

Auth::requireGuest();

$errors = errors();
$values = ['email' => old('email')];

if (is_post()) {
    csrf_check();

    $input = [
        'email' => mb_strtolower((string) input('email', '')),
        'password' => (string) ($_POST['password'] ?? ''),
    ];

    $errors = Validator::login($input);

    if ($errors === []) {
        $result = Auth::attempt($input['email'], $input['password']);

        if ($result['ok'] === true) {
            clear_old_input();
            flash('success', 'Signed in successfully.');
            redirect(Auth::pullIntended('/dashboard.php'));
        }

        $errors['email'] = (string) $result['message'];
    }

    if ($errors !== []) {
        flash_errors($errors);
        flash_old_input(['email' => $input['email']]);
        redirect('/login.php');
    }
}

$pageTitle = 'Sign in';
$metaDescription = 'Sign in to your FreeSub account to manage your subdomains and reservations.';

view('auth/login', [
    'errors' => $errors,
    'values' => $values,
    'pageTitle' => $pageTitle,
    'metaDescription' => $metaDescription,
], 'layouts/public');
