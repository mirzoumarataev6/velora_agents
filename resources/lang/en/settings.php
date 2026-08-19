<?php

return [

    'index' => [
        'title' => 'Settings · Velora',
        'heading' => 'Settings',
        'subheading' => 'Your profile, security, language and account options.',
    ],

    'tabs' => [
        'profile' => 'Profile',
        'security' => 'Security',
        'preferences' => 'Preferences',
        'account' => 'Account',
    ],

    'actions' => [
        'save' => 'Save',
        'cancel' => 'Cancel',
    ],

    'profile' => [
        'heading' => 'Profile',
        'description' => 'Your name, and the address we write to.',
        'avatar_hint' => 'Your avatar is built from the initials of your name.',
        'name' => 'Name',
        'email' => 'Email',
        'email_hint' => 'Changing your address means confirming it again.',
        'unverified' => 'Address not confirmed',
        'saved' => 'Profile saved',
        'saved_email_changed' => 'Profile saved. Please confirm your new email address.',
    ],

    'security' => [
        'heading' => 'Password',
        'description' => 'Changing your password requires the current one, so a borrowed signed-in browser cannot lock you out.',
        'google_hint' => 'You sign in with Google. If you never set a password, use password recovery on the sign-in page.',
        'current_password' => 'Current password',
        'new_password' => 'New password',
        'confirm_password' => 'Repeat new password',
        'change_password' => 'Change password',
        'current_password_wrong' => 'That password is not correct.',
        'password_saved' => 'Password changed',
        'sessions_heading' => 'Active sessions',
        'sessions_description' => 'Browsers currently signed in to your account.',
        'sessions_unavailable' => 'The device list is unavailable with the current session storage, but you can still sign out everywhere.',
        'this_device' => 'This device',
        'unknown_device' => 'Unknown device',
        'confirm_with_password' => 'Confirm with your password',
        'logout_others' => 'Sign out other devices',
        'other_sessions_ended' => 'Signed out on your other devices',
    ],

    'preferences' => [
        'heading' => 'Preferences',
        'description' => 'Interface language, appearance and notifications.',
        'language' => 'Interface language',
        'theme' => 'Appearance',
        'notifications' => 'Notifications',
        'lead_alerts' => 'New lead notifications',
        'lead_alerts_hint' => 'Turn this off to stop Telegram lead alerts across every agent at once.',
        'telegram_heading' => 'Leads in Telegram',
        'telegram_description' => 'Linked per agent — alerts are delivered through that agent’s own bot.',
        'telegram_no_agents' => 'Create an agent and connect Telegram to it first.',
        'saved' => 'Preferences saved',
    ],

    'locales' => [
        'ru' => 'Русский',
        'tg' => 'Тоҷикӣ',
        'en' => 'English',
    ],

    'themes' => [
        'system' => 'Match system',
        'light' => 'Light',
        'dark' => 'Dark',
    ],

    'account' => [
        'heading' => 'Account',
        'description' => 'Connected sign-in methods.',
        'google' => 'Google',
        'google_connected' => 'Connected',
        'google_not_connected' => 'Not connected',
        'delete_heading' => 'Delete account',
        'delete_description' => 'Your account and everything belonging to it will be permanently removed. This cannot be undone.',
        'delete_item_agents' => 'all your agents and connected channels',
        'delete_item_knowledge' => 'uploaded knowledge bases',
        'delete_item_conversations' => 'customer conversation history',
        'delete_item_leads' => 'every captured lead',
        'delete_button' => 'Delete account',
        'modal_title' => 'Delete your account?',
        'modal_description' => 'This data cannot be recovered. Confirm that you understand.',
        'modal_prompt' => 'Type :email or the word DELETE to confirm',
        'modal_confirm' => 'Delete permanently',
        'delete_confirmation_wrong' => 'That does not match. Check it and try again.',
        'deleted' => 'Your account has been deleted.',
    ],

];
