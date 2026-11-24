<?php

declare(strict_types=1);

/**
 * Account creation.
 */

require_once __DIR__ . '/includes/bootstrap.php';

Auth::requireGuest();

$errors = errors();
$values = [
    'name' => old('name'),
    'email' => old('email'),
];

if (is_post()) {
    csrf_check();

    $attempts = (int) config('security.register_max_attempts', 5);
    $window = (int) config('security.register_window', 3600);

    if (!rate_limit_hit('register', $attempts, $window)) {
        flash('error', 'Too many sign-up attempts from this network. Please try again later.');
        redirect('/register.php');
    }

    $input = [
        'name' => (string) input('name', ''),
        'email' => mb_strtolower((string) input('email', '')),
        'password' => (string) ($_POST['password'] ?? ''),
        'password_confirmation' => (string) ($_POST['password_confirmation'] ?? ''),
    ];

    $errors = Validator::registration($input);

    if ($errors === []) {
        if (User::emailExists($input['email'])) {
            // Same message as success paths elsewhere; still specific enough to act on.
            $errors['email'] = 'An account with that email address already exists. Try signing in instead.';
        } else {
            try {
                $user = User::create($input['name'], $input['email'], $input['password']);

                Auth::login($user);

                audit_log(
                    'auth.registered',
                    (int) $user['id'],
                    ['email' => $user['email']],
                    'user',
                    (int) $user['id'],
                    'Account created'
                );

                clear_old_input();
                rate_limit_clear('register');

                $_SESSION['_intended'] = '/dashboard.php?welcome=1';
                flash('success', 'Welcome to ' . (string) config('name', 'FreeSub') . '! Your account is ready.');
                redirect('/dashboard.php?welcome=1');
            } catch (RuntimeException $exception) {
                $errors['email'] = $exception->getMessage();
            }
        }
    }

    if ($errors !== []) {
        flash_errors($errors);
        flash_old_input($_POST);
        redirect('/register.php');
    }
}

$pageTitle = 'Create your free account';
$metaDescription = 'Create a free FreeSub account and reserve your subdomain in under a minute. No credit card required.';

view('auth/register', [
    'errors' => $errors,
    'values' => $values,
    'pageTitle' => $pageTitle,
    'metaDescription' => $metaDescription,
], 'layouts/public');
