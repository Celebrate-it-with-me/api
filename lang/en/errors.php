<?php

// Texts a person reads in API errors. The keys here are for the text only: the `code` fields and error codes
// the front reads (already_staff, invalid_code, module_locked...) are a contract and never go through here.
return [
    'admin_login' => [
        'invalid_credentials' => 'The email or password is incorrect.',
        'challenge_expired' => 'The sign-in attempt expired. Start again.',
        'too_many_codes' => 'Too many wrong codes. Start again.',
        'code_invalid' => 'The code is not valid.',
    ],
    'admin' => [
        'suspended' => 'Your access to the admin panel is suspended.',
        'session_required' => 'Sign in to the admin panel.',
        'session_expired' => 'Your session expired. Sign in again.',
        'two_factor_required' => 'Two-factor authentication is required for your role.',
        'two_factor_first' => 'Set up your own two-factor authentication before requiring it from everyone.',
        'two_factor_reset_needs_own' => 'Turn on your own two-factor authentication to reset a superadmin\'s.',
    ],
    'two_factor' => [
        'not_active' => 'Two-factor authentication is not active.',
        'already_active' => 'Two-factor authentication is already active.',
        'invalid_code' => 'The verification code is not valid.',
        'no_recovery_codes' => 'No recovery codes are available.',
        'current_password_wrong' => 'The current password is not correct.',
        'generate_qr_first' => 'Generate the QR code first.',
        'code_mismatch' => 'The code is not correct. Check your phone\'s clock and try again.',
    ],
    'too_many_attempts' => 'Too many attempts. Try again later.',
    'auth' => [
        'invalid_credentials' => 'The email or password is incorrect.',
        'captcha_invalid' => 'We could not verify you are human. Try again.',
        'invalid_signature' => 'The link is not valid or has expired.',
        'invalid_reset_token' => 'The password reset link is not valid or has expired.',
        'email_not_verified' => 'Verify your email to continue.',
        'unauthenticated' => 'Sign in to continue.',
        'confirm_password' => 'Confirm your password to continue.',
        'reauth_token_required' => 'Sign in again to confirm your identity.',
        'reauth_code_required' => 'The code is required.',
        'reauth_code_hint' => 'Enter the code from your authenticator app.',
        'reauth_password_wrong' => 'The password is not correct.',
        'reauth_code_wrong' => 'The code is not valid.',
        'current_password_wrong' => 'The current password is not correct.',
        'password_is_email' => 'The password cannot be your email.',
        'password_same_as_current' => 'The new password must be different from the current one.',
    ],
    'permission' => [
        'event_access' => 'You do not have permission to access this event.',
        'event_access_contact' => 'You do not have permission to access this event. Please contact the event organizer.',
        'event_action' => 'You do not have permission to do this in this event.',
        'module_unavailable' => 'This module is not available for this event.',
        'impersonation_blocked' => 'This action is not allowed during a support session.',
    ],
    'impersonation' => [
        'not_active' => 'There is no active support session.',
        'ended' => 'Support session ended.',
        'notify_forbidden' => 'Only a superadmin can sign in without notifying the user.',
    ],
    'team' => [
        'invitation_invalid' => 'This invitation is not valid.',
        'invitation_expired' => 'This invitation has expired.',
        'member_not_found' => 'This person is not on the team.',
    ],
    'whatsapp' => [
        'daily_limit' => 'The daily WhatsApp limit was reached.',
        'only_failed_retry' => 'Only failed messages can be retried.',
        'retry_limit' => 'This message was already retried too many times.',
        'template_not_approved' => 'The WhatsApp template is not approved yet.',
        'event_archived' => 'This event is archived.',
        'unavailable' => 'WhatsApp is not connected.',
    ],
    'collaborators' => [
        'already_member' => 'This person is already a collaborator.',
        'pending_invite' => 'There is already a pending invitation for this email.',
        'owner_role_fixed' => 'The owner role cannot be changed.',
        'owner_not_removable' => 'The owner cannot be removed from the event.',
        'resend_not_allowed' => 'Only a pending or expired invitation can be resent.',
        'invitation_invalid' => 'The invitation is not valid or has expired.',
        'not_your_invitation' => 'This invitation is not for your account.',
        'accept_failed' => 'We could not accept the invitation. Try again.',
    ],
    'padrino' => [
        'has_payments' => 'This padrino has payments.',
        'commitment_item_paid' => 'This item already has payments without a padrino. Say whether the padrinos covered them.',
        'commitment_exceeds_remaining' => 'The additional commitment exceeds what is left to pay on the item.',
    ],
    'budget' => [
        'category_not_empty' => 'The category still has items.',
    ],
    'timeline' => [
        'already_has_moments' => 'The program already has moments.',
    ],
    'announcement' => [
        'not_found' => 'Announcement not found.',
        'not_dismissible' => 'This announcement cannot be dismissed.',
        'invalid' => 'The given data was invalid.',
    ],
    'assistant' => [
        'task_not_found' => 'Assistant step not found.',
        'custom_task_not_found' => 'Custom task not found.',
        'custom_task_limit' => 'This event already has 50 custom tasks.',
        'invalid' => 'The given data was invalid.',
    ],
    'guest_import' => [
        'cannot_import' => 'The file cannot be imported.',
    ],
    'session' => [
        'use_sign_out' => 'To close this session use sign out.',
    ],
    'profile' => [
        'same_email' => 'That is already your email.',
        'email_in_use' => 'That email is already in use.',
        'change_link_invalid' => 'The link is not valid or has expired. Request the change again.',
    ],
    'event_actions' => [
        'plan_limit' => 'The list has :total guests and the event plan allows :limit. Remove :excess or unlock the event before publishing it.',
        'same_slug' => 'That is already the current link.',
        'owner_not_found' => 'No user exists with that email.',
        'owner_suspended' => 'That account is suspended.',
    ],
    'catalog' => [
        'full_plan_module_locked' => 'A module of the full plan cannot be removed: the plan promises everything unlocked.',
        'module_missing' => 'This module does not exist or is disabled in the catalog.',
    ],
    'moderation' => [
        'not_found' => 'The item no longer exists.',
        'failed' => 'The action could not be completed.',
        'storage_failed' => 'The photo files could not be deleted; nothing was deleted.',
    ],
    'health' => [
        'failed_job_not_found' => 'Failed job not found.',
    ],
    'generic' => [
        'error' => 'Something went wrong. Try again.',
        'load_failed' => 'The data could not be loaded.',
        'profile_update_failed' => 'Failed to update profile.',
        'password_update_failed' => 'Failed to update password.',
    ],
    'user' => [
        'not_authenticated' => 'User not authenticated.',
        'preferences_not_found' => 'User preferences not found.',
    ],
    'event_location' => [
        'images_limit' => 'A place can have at most :max pictures.',
    ],
    'account_deletion' => [
        'staff_account' => 'Staff accounts must be removed from the team first.',
        'pending_payment' => 'A payment is in progress. Wait for it or cancel it first.',
        'invalid' => 'The data you sent is not valid.',
        'event_archived' => 'This event was archived with a deleted account and cannot be restored.',
    ],
    'messages' => [
        'not_found' => 'Message not found.',
    ],
    'spotify' => [
        'unavailable' => 'Music search is not available right now.',
    ],
];
