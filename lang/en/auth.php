<?php

return [
    'title' => 'Sign in',
    'subtitle' => 'Use your work email and password.',
    'email' => 'Email',
    'password' => 'Password',
    'remember' => 'Keep me signed in',
    'show_password' => 'Show password',
    'hide_password' => 'Hide password',
    'submit' => 'Sign in',
    'signing_in' => 'Signing in…',
    'failed' => 'The email or password is incorrect.',
    'inactive' => 'This account is disabled. Ask your administrator.',
    'throttle' => 'Too many attempts. Try again in :seconds seconds.',
    'back_to_sign_in' => 'Back to sign in',
    'forced_change' => 'Your administrator set a temporary password. Choose your own password to continue.',

    // "Forgot password?" (App\Livewire\Auth\ForgotPassword).
    'forgot' => [
        'link' => 'Forgot password?',
        'title' => 'Reset your password',
        'subtitle' => 'Enter your work email and we will send you a link to choose a new password.',
        'submit' => 'Send reset link',
        'sending' => 'Sending…',
        'sent' => 'If this email is registered, you will receive a password reset link shortly.',
    ],

    // The page the emailed link opens (App\Livewire\Auth\ResetPassword).
    'reset' => [
        'title' => 'Choose a new password',
        'subtitle' => 'Enter your new password twice.',
        'new' => 'New password',
        'confirm' => 'Repeat new password',
        'signs_out' => 'You will be signed out on every device and can then sign in with the new password.',
        'submit' => 'Save new password',
        'done' => 'Your password was changed. Sign in with your new password.',
        'invalid' => 'This link is not valid any more. It may have been used already, replaced by a newer one, or be older than :minutes minutes.',
        'request_new' => 'Send me a new link',
    ],

    'mail' => [
        'greeting' => 'Hello :name,',
        'footer' => ':app · This is an automatic message, please do not reply.',
        'reset' => [
            'subject' => 'Reset your :app password',
            'heading' => 'Reset your password',
            'intro' => 'We received a request to reset the password of your :app account. Click the button below to choose a new one.',
            'button' => 'Reset password',
            'expires' => 'This link expires in :minutes minutes and can be used once.',
            'fallback' => 'If the button does not work, copy this link into your browser:',
            'ignore' => 'If you didn’t request this, ignore this email. Your password will not change.',
        ],
        'changed' => [
            'subject' => 'Your :app password was changed',
            'heading' => 'Your password was changed',
            'intro' => 'The password of your :app account was changed on :date.',
            'not_you' => 'If this wasn’t you, contact your administrator right away.',
        ],
    ],
];
