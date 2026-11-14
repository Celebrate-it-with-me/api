<?php

// Transactional mail copy. Keep the keys identical in lang/es/mail.php (Spanish is the design language).
return [
    'footer' => [
        'tagline' => 'Quinvite · digital invitations for your quinceañera or your wedding.',
        'help' => 'Questions? Write to us at :email.',
    ],

    'roles' => [
        'owner' => 'owner',
        'editor' => 'editor',
        'viewer' => 'view only',
    ],

    'confirm' => [
        'subject' => 'Confirm your email to start planning your celebration',
        'preheader' => 'One click and your Quinvite account is ready.',
        'title' => 'Hi, :name!',
        'intro' => 'So glad to have you on **Quinvite**. You are one step away from planning your quinceañera or your wedding: confirm your email and your account is ready.',
        'action' => 'Confirm my email',
        'outro' => 'If you did not create this account, you can ignore this message; nothing will happen.',
    ],

    'invoice' => [
        'subject' => 'Your Quinvite invoice :number',
        'preheader' => 'Your invoice is attached.',
        'title' => 'Thank you for your purchase, :name',
        'intro' => 'Attached is invoice :number for :total, for ":event". You can also download it any time from My purchases.',
        'action' => 'View my purchases',
    ],

    'welcome' => [
        'subject' => 'Welcome to Quinvite, :name',
        'preheader' => 'Your quinceañera or wedding, organized in one place.',
        'title' => 'Welcome to Quinvite, :name',
        'intro' => 'Thank you for joining. Here you will create the invitation for your quinceañera or wedding, build your guest list, collect RSVPs and keep every to-do under control.',
        'step' => 'Start with the best part: name your event and choose how you want your invitation to look.',
        'action' => 'Create my event',
    ],

    'reset' => [
        'subject' => 'Reset your Quinvite password',
        'preheader' => 'We will help you get back into your account.',
        'title' => 'Hi, :name',
        'intro' => 'We received a request to change the password of your **Quinvite** account. Tap the button to choose a new one.',
        'action' => 'Choose a new password',
        'outro' => 'If it was not you, ignore this message: your current password still works.',
    ],

    'invite_registered' => [
        'subject' => 'You are invited to collaborate on :event',
        'preheader' => ':event is waiting for you in your Quinvite dashboard.',
        'title' => 'You have been invited!',
        'intro' => 'You were added to the team of **:event** as **:role**. Open your dashboard to help with the preparations.',
        'action' => 'Open the event',
        'outro' => 'If you were not expecting this invitation, you can ignore it.',
    ],

    'invite_unregistered' => [
        'subject' => 'You are invited to collaborate on :event',
        'preheader' => 'Create your free account and help prepare :event.',
        'title' => 'You have been invited!',
        'intro' => 'You were added to the team of **:event** as **:role**. To accept, create your free account or log in.',
        'action' => 'Accept the invitation',
        'outro' => 'This link expires in 7 days. If you were not expecting this invitation, you can ignore it.',
    ],

    'ownership_new' => [
        'subject' => 'You are now in charge of :event',
        'preheader' => 'Our team made you the owner of the event.',
        'title' => 'Hi, :name',
        'intro' => 'Our team made you the owner of **:event**, as agreed. You can now manage it fully: guests, invitation, budget and everything else.',
        'action' => 'Open the event',
        'outro' => 'Something not right? Write to us and we will sort it out together.',
    ],

    'ownership_previous' => [
        'subject' => 'Ownership change of :event',
        'preheader' => 'You keep access to the event as an editor.',
        'title' => 'Hi, :name',
        'intro' => 'On request, our team moved the ownership of **:event** to another account. You keep access as an **editor**, so you can keep helping with the preparations.',
        'action' => 'Open the event',
        'outro' => 'Something not right? Write to us and we will sort it out together.',
    ],

    'impersonation_ended' => [
        'subject' => 'The Quinvite team accessed your account to help you',
        'preheader' => 'It was a short visit and it was logged.',
        'title' => 'Hi, :name',
        'intro' => 'The Quinvite team accessed your account on :date for :minutes :unit :why.',
        'unit_one' => 'minute',
        'unit_other' => 'minutes',
        'why_request' => 'to help you with your support request',
        'why_account' => 'to help you with your account',
        'details' => 'We only do this to answer a request for help, and the whole session is logged. If you were not expecting this access or have questions, write to us at :email.',
        'action' => 'Write to the team',
        'signature' => 'The Quinvite team',
    ],

    'budget_reminder' => [
        'subject' => 'Payment reminders: :event',
        'preheader' => '{1} You have 1 payment coming up for :event.|[2,*] You have :count payments coming up for :event.',
        'title' => 'Hi, :name',
        'intro' => 'These payments for **:event** are coming up:',
        'item' => 'Item',
        'due' => 'Due',
        'cost' => 'Estimated cost',
        'due_today' => 'Due today',
        'due_in' => '{1} Due tomorrow|[2,*] Due in :days days',
        'action' => 'View my budget',
        'outro' => 'If you already paid them, mark them as paid in the app to stop receiving these reminders.',
    ],

    'test' => [
        'subject' => 'Quinvite test email',
        'preheader' => 'If you see this message, mail delivery works.',
        'title' => 'All good',
        'intro' => 'This is a test email from **Quinvite**. If you are reading it, mail delivery works.',
        'action' => 'Go to my dashboard',
    ],

    'admin_security' => [
        'title' => 'Hi, :name',
        'if_not_you' => 'If this was not you, change your password right away and close your other sessions from My account, or tell another team member. Questions? Write to :email.',
        'action' => 'Review my account',
        'signature' => 'The Quinvite team',
        'types' => [
            'password_changed' => [
                'subject' => 'You changed your Quinvite admin password',
                'preheader' => 'We closed your other sessions for safety.',
                'intro' => 'The password of your admin panel account was changed and we closed your other open sessions.',
            ],
            'email_changed' => [
                'subject' => 'The email of your Quinvite admin account changed',
                'preheader' => 'Your account now signs in with another email.',
                'intro' => 'The sign-in email of your admin panel account changed to :email. This notice goes to the previous address.',
            ],
            'two_factor_enabled' => [
                'subject' => 'You turned on two-step verification on Quinvite',
                'preheader' => 'From now on we will ask for a code when you sign in.',
                'intro' => 'Two-step verification is now on for your admin panel account. When you sign in we will ask for a code from your authenticator app.',
            ],
            'two_factor_disabled' => [
                'subject' => 'You turned off two-step verification on Quinvite',
                'preheader' => 'Your account no longer asks for a second step.',
                'intro' => 'Two-step verification was turned off for your admin panel account and we closed your other sessions.',
            ],
            'recovery_codes_regenerated' => [
                'subject' => 'You generated new recovery codes on Quinvite',
                'preheader' => 'The previous codes no longer work.',
                'intro' => 'New recovery codes were generated for your admin panel account. The previous ones stopped working.',
            ],
            'two_factor_reset_by_admin' => [
                'subject' => 'Two-factor authentication on your Quinvite account was reset',
                'preheader' => 'We signed you out everywhere.',
                'intro' => 'A team member (:actor) turned off two-factor authentication on your admin panel account and we closed all your sessions. When you sign in again you can turn it back on from My account.',
            ],
            'new_device' => [
                'subject' => 'New sign-in to the Quinvite admin panel',
                'preheader' => 'You signed in from a device we did not know.',
                'intro' => 'Someone signed in to your admin panel account from a new device. Device, :device. Date, :when. Network location, :ip.',
            ],
        ],
    ],

    'admin_email_confirm' => [
        'subject' => 'Confirm your new Quinvite admin email',
        'preheader' => 'One click and your account signs in with this email.',
        'title' => 'Hi, :name',
        'intro' => 'You asked to use this email to sign in to the Quinvite admin panel. Confirm it with the button; the link expires in :minutes minutes.',
        'action' => 'Confirm my new email',
        'outro' => 'If you did not ask for this, ignore this message and your email will not change.',
        'signature' => 'The Quinvite team',
    ],
];
