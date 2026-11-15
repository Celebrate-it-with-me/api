<?php

// Texts a person reads in API errors. The keys here are for the text only: the `code` fields and error codes
// the front reads (already_staff, invalid_code, module_locked...) are a contract and never go through here.
return [
    'admin_login' => [
        'invalid_credentials' => 'Correo o contraseña incorrectos.',
        'challenge_expired' => 'El inicio de sesión venció. Empieza de nuevo.',
        'too_many_codes' => 'Demasiados códigos incorrectos. Empieza de nuevo.',
        'code_invalid' => 'El código no es válido.',
    ],
    'admin' => [
        'suspended' => 'Tu acceso al panel de administración está suspendido.',
        'session_required' => 'Inicia sesión en el panel de administración.',
        'session_expired' => 'Tu sesión venció. Inicia sesión de nuevo.',
        'two_factor_required' => 'Tu rol requiere verificación en dos pasos.',
        'two_factor_first' => 'Activa primero tu verificación en dos pasos antes de exigirla a todo el equipo.',
        'two_factor_reset_needs_own' => 'Activa tu propia verificación en dos pasos para restablecer la de un superadmin.',
    ],
    'two_factor' => [
        'not_active' => 'La verificación en dos pasos no está activa.',
        'already_active' => 'La verificación en dos pasos ya está activa.',
        'invalid_code' => 'El código de verificación no es válido.',
        'no_recovery_codes' => 'No hay códigos de recuperación disponibles.',
        'current_password_wrong' => 'La contraseña actual no es correcta.',
        'generate_qr_first' => 'Primero genera el código QR.',
        'code_mismatch' => 'El código no es correcto. Revisa la hora de tu teléfono e inténtalo de nuevo.',
    ],
    'too_many_attempts' => 'Demasiados intentos. Inténtalo de nuevo más tarde.',
    'auth' => [
        'invalid_credentials' => 'Correo o contraseña incorrectos.',
        'captcha_invalid' => 'No pudimos verificar que eres una persona. Inténtalo de nuevo.',
        'invalid_signature' => 'El enlace no es válido o ya venció.',
        'invalid_reset_token' => 'El enlace para restablecer la contraseña no es válido o ya venció.',
        'unauthenticated' => 'Inicia sesión para continuar.',
        'confirm_password' => 'Confirma tu contraseña para continuar.',
        'reauth_token_required' => 'Inicia sesión de nuevo para confirmar tu identidad.',
        'reauth_code_required' => 'Falta el código.',
        'reauth_code_hint' => 'Escribe el código de tu aplicación de autenticación.',
        'reauth_password_wrong' => 'La contraseña no es correcta.',
        'reauth_code_wrong' => 'El código no es válido.',
        'current_password_wrong' => 'La contraseña actual no es correcta.',
        'password_is_email' => 'La contraseña no puede ser tu correo.',
        'password_same_as_current' => 'La contraseña nueva debe ser distinta de la actual.',
    ],
    'permission' => [
        'event_access' => 'No tienes permiso para ver este evento.',
        'event_access_contact' => 'No tienes permiso para ver este evento. Habla con quien lo organiza.',
        'event_action' => 'No tienes permiso para hacer esto en este evento.',
        'module_unavailable' => 'Este módulo no está disponible para este evento.',
        'impersonation_blocked' => 'Esta acción no está permitida durante una sesión de soporte.',
    ],
    'impersonation' => [
        'not_active' => 'No hay una sesión de soporte activa.',
        'ended' => 'Sesión de soporte terminada.',
        'notify_forbidden' => 'Solo un superadmin puede entrar sin avisar a la usuaria.',
    ],
    'team' => [
        'invitation_invalid' => 'Esta invitación no es válida.',
        'invitation_expired' => 'Esta invitación venció.',
        'member_not_found' => 'Esa persona no está en el equipo.',
    ],
    'whatsapp' => [
        'daily_limit' => 'Se alcanzó el límite diario de WhatsApp.',
        'only_failed_retry' => 'Solo se pueden reintentar los mensajes que fallaron.',
        'retry_limit' => 'Este mensaje ya se reintentó demasiadas veces.',
        'template_not_approved' => 'La plantilla de WhatsApp todavía no está aprobada.',
        'event_archived' => 'Este evento está archivado.',
        'unavailable' => 'WhatsApp no está conectado.',
    ],
    'collaborators' => [
        'already_member' => 'Esa persona ya colabora en este evento.',
        'pending_invite' => 'Ya hay una invitación pendiente para este correo.',
        'owner_role_fixed' => 'El rol de quien creó el evento no se puede cambiar.',
        'owner_not_removable' => 'Quien creó el evento no se puede quitar.',
        'resend_not_allowed' => 'Solo se puede reenviar una invitación pendiente o vencida.',
        'invitation_invalid' => 'La invitación no es válida o ya venció.',
        'not_your_invitation' => 'Esta invitación no es para tu cuenta.',
        'accept_failed' => 'No pudimos aceptar la invitación. Inténtalo de nuevo.',
    ],
    'padrino' => [
        'has_payments' => 'Este padrino tiene pagos registrados.',
    ],
    'budget' => [
        'category_not_empty' => 'La categoría todavía tiene partidas.',
    ],
    'timeline' => [
        'already_has_moments' => 'El programa ya tiene momentos.',
    ],
    'announcement' => [
        'not_found' => 'No encontramos ese aviso.',
        'not_dismissible' => 'Este aviso no se puede cerrar.',
        'invalid' => 'Los datos enviados no son válidos.',
    ],
    'assistant' => [
        'task_not_found' => 'No encontramos ese paso del asistente.',
        'custom_task_not_found' => 'No encontramos esa tarea.',
        'custom_task_limit' => 'Este evento ya tiene 50 tareas propias.',
        'invalid' => 'Los datos enviados no son válidos.',
    ],
    'guest_import' => [
        'cannot_import' => 'No se pudo importar el archivo.',
    ],
    'session' => [
        'use_sign_out' => 'Para cerrar esta sesión usa «Cerrar sesión».',
    ],
    'profile' => [
        'same_email' => 'Ese ya es tu correo.',
        'email_in_use' => 'Ese correo ya está en uso.',
        'change_link_invalid' => 'El enlace no es válido o venció. Pide el cambio de nuevo.',
    ],
    'event_actions' => [
        'plan_limit' => 'La lista tiene :total invitados y el plan del evento permite :limit. Hay que quitar :excess o desbloquear el evento antes de publicarlo.',
        'same_slug' => 'Ese ya es el enlace actual.',
        'owner_not_found' => 'No existe ninguna usuaria con ese correo.',
        'owner_suspended' => 'Esa cuenta está suspendida.',
    ],
    'catalog' => [
        'full_plan_module_locked' => 'Un módulo del plan completo no se puede quitar: el plan promete todo desbloqueado.',
        'module_missing' => 'Este módulo no existe o está desactivado en el catálogo.',
    ],
    'moderation' => [
        'not_found' => 'El elemento ya no existe.',
        'failed' => 'No se pudo completar la acción.',
        'storage_failed' => 'No se pudieron borrar los archivos de la foto; no se borró nada.',
    ],
    'health' => [
        'failed_job_not_found' => 'No encontramos ese trabajo fallido.',
    ],
    'generic' => [
        'error' => 'Algo salió mal. Inténtalo de nuevo.',
        'load_failed' => 'No pudimos cargar los datos. Inténtalo de nuevo.',
        'profile_update_failed' => 'No pudimos actualizar tu perfil.',
        'password_update_failed' => 'No pudimos actualizar tu contraseña.',
    ],
    'user' => [
        'not_authenticated' => 'Inicia sesión para continuar.',
        'preferences_not_found' => 'No encontramos tus preferencias.',
    ],
    'event_location' => [
        'images_limit' => 'Un lugar puede tener como máximo :max fotos.',
    ],
    'account_deletion' => [
        'staff_account' => 'Las cuentas del equipo se quitan primero desde Equipo.',
        'pending_payment' => 'Hay un pago en curso. Espera a que termine o cancélalo primero.',
        'invalid' => 'Los datos que enviaste no son válidos.',
        'event_archived' => 'Este evento se archivó al eliminar la cuenta y no se puede restaurar.',
    ],
    'messages' => [
        'not_found' => 'No encontramos ese mensaje.',
    ],
    'spotify' => [
        'unavailable' => 'La búsqueda de música no está disponible en este momento.',
    ],
];
