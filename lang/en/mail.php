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
];
