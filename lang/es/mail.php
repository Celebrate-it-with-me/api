<?php

// Transactional mail copy (Spanish is the design language). Keep the keys identical in lang/en/mail.php:
// MailRegistryTest renders every mail in both languages and fails on a missing key.
return [
    'footer' => [
        'tagline' => 'Quinvite · invitaciones digitales para tu quinceañera o tu boda.',
        'help' => '¿Dudas? Escríbenos a :email.',
    ],

    'roles' => [
        'owner' => 'dueño',
        'editor' => 'editor',
        'viewer' => 'solo lectura',
    ],

    'confirm' => [
        'subject' => 'Confirma tu correo para empezar con tu celebración',
        'preheader' => 'Un clic y tu cuenta de Quinvite queda lista.',
        'title' => '¡Hola, :name!',
        'intro' => 'Qué alegría tenerte en **Quinvite**. Estás a un paso de empezar a planear tu quinceañera o tu boda: confirma tu correo y tu cuenta queda lista.',
        'action' => 'Confirmar mi correo',
        'outro' => 'Si no creaste esta cuenta, puedes ignorar este mensaje; no pasará nada.',
    ],

    'welcome' => [
        'subject' => 'Te damos la bienvenida a Quinvite, :name',
        'preheader' => 'Tu quinceañera o tu boda, organizada en un solo lugar.',
        'title' => 'Bienvenida a Quinvite, :name',
        'intro' => 'Gracias por unirte. Aquí vas a crear la invitación de tu quinceañera o tu boda, armar la lista de invitados, recibir sus confirmaciones y tener todos los pendientes bajo control.',
        'step' => 'Empieza por lo más bonito: ponle nombre a tu evento y elige cómo quieres que se vea tu invitación.',
        'action' => 'Crear mi evento',
    ],

    'reset' => [
        'subject' => 'Restablece tu contraseña de Quinvite',
        'preheader' => 'Te ayudamos a volver a entrar a tu cuenta.',
        'title' => 'Hola, :name',
        'intro' => 'Recibimos una solicitud para cambiar la contraseña de tu cuenta de **Quinvite**. Toca el botón para elegir una nueva.',
        'action' => 'Elegir nueva contraseña',
        'outro' => 'Si no fuiste tú, ignora este mensaje: tu contraseña actual sigue funcionando.',
    ],

    'invite_registered' => [
        'subject' => 'Te invitaron a colaborar en :event',
        'preheader' => ':event te espera en tu panel de Quinvite.',
        'title' => '¡Te invitaron a colaborar!',
        'intro' => 'Te sumaron al equipo de **:event** con el rol de **:role**. Entra a tu panel para ayudar con los preparativos.',
        'action' => 'Abrir el evento',
        'outro' => 'Si no esperabas esta invitación, puedes ignorarla.',
    ],

    'invite_unregistered' => [
        'subject' => 'Te invitaron a colaborar en :event',
        'preheader' => 'Crea tu cuenta gratis y ayuda con los preparativos de :event.',
        'title' => '¡Te invitaron a colaborar!',
        'intro' => 'Te sumaron al equipo de **:event** con el rol de **:role**. Para aceptar, crea tu cuenta gratis o inicia sesión.',
        'action' => 'Aceptar la invitación',
        'outro' => 'Este enlace vence en 7 días. Si no esperabas esta invitación, puedes ignorarla.',
    ],

    'ownership_new' => [
        'subject' => 'Ahora estás a cargo de :event',
        'preheader' => 'Nuestro equipo te asignó la titularidad del evento.',
        'title' => 'Hola, :name',
        'intro' => 'Nuestro equipo te asignó la titularidad de **:event**, como acordamos. Ya puedes administrarlo por completo: invitados, invitación, presupuesto y todo lo demás.',
        'action' => 'Abrir el evento',
        'outro' => '¿Algo no cuadra? Escríbenos y lo revisamos juntos.',
    ],

    'ownership_previous' => [
        'subject' => 'Cambio de titularidad de :event',
        'preheader' => 'Sigues con acceso al evento como editor.',
        'title' => 'Hola, :name',
        'intro' => 'A pedido, nuestro equipo movió la titularidad de **:event** a otra cuenta. Tú sigues con acceso como **editor**, así que puedes seguir ayudando con los preparativos.',
        'action' => 'Abrir el evento',
        'outro' => '¿Algo no cuadra? Escríbenos y lo revisamos juntos.',
    ],

    'impersonation_ended' => [
        'subject' => 'El equipo de Quinvite entró a tu cuenta para ayudarte',
        'preheader' => 'Fue una visita corta y quedó registrada.',
        'title' => 'Hola, :name',
        'intro' => 'El equipo de Quinvite entró a tu cuenta el :date durante :minutes :unit :why.',
        'unit_one' => 'minuto',
        'unit_other' => 'minutos',
        'why_request' => 'para ayudarte con tu solicitud de soporte',
        'why_account' => 'para ayudarte con tu cuenta',
        'details' => 'Solo lo hacemos para atender una solicitud de ayuda y toda la sesión queda registrada. Si no esperabas este acceso o tienes dudas, escríbenos a :email.',
        'action' => 'Escribir al equipo',
        'signature' => 'Equipo Quinvite',
    ],

    'budget_reminder' => [
        'subject' => 'Recordatorio de pagos: :event',
        'preheader' => '{1} Tienes 1 pago por vencer en :event.|[2,*] Tienes :count pagos por vencer en :event.',
        'title' => 'Hola, :name',
        'intro' => 'Estos pagos de **:event** se acercan:',
        'item' => 'Concepto',
        'due' => 'Vence',
        'cost' => 'Costo estimado',
        'due_today' => 'Vence hoy',
        'due_in' => '{1} Vence mañana|[2,*] Vence en :days días',
        'action' => 'Ver mi presupuesto',
        'outro' => 'Si ya los pagaste, márcalos como pagados en la app para dejar de recibir estos avisos.',
    ],

    'test' => [
        'subject' => 'Prueba de correo de Quinvite',
        'preheader' => 'Si ves este mensaje, el envío de correos funciona.',
        'title' => 'Todo en orden',
        'intro' => 'Este es un correo de prueba de **Quinvite**. Si lo estás leyendo, el envío de correos funciona.',
        'action' => 'Ir a mi panel',
    ],

    'admin_security' => [
        'title' => 'Hola, :name',
        'if_not_you' => 'Si no fuiste tú, cambia tu contraseña de inmediato y cierra las demás sesiones desde Mi cuenta, o avisa a otra persona del equipo. Dudas en :email.',
        'action' => 'Revisar mi cuenta',
        'signature' => 'Equipo Quinvite',
        'types' => [
            'password_changed' => [
                'subject' => 'Cambiaste tu contraseña del panel de Quinvite',
                'preheader' => 'Cerramos tus otras sesiones por seguridad.',
                'intro' => 'La contraseña de tu cuenta del panel de administración se cambió y cerramos tus otras sesiones abiertas.',
            ],
            'email_changed' => [
                'subject' => 'Cambió el correo de tu cuenta del panel de Quinvite',
                'preheader' => 'Tu cuenta ahora entra con otro correo.',
                'intro' => 'El correo de acceso a tu cuenta del panel de administración cambió a :email. Este aviso llega a la dirección anterior.',
            ],
            'two_factor_enabled' => [
                'subject' => 'Activaste la verificación en dos pasos en Quinvite',
                'preheader' => 'Desde ahora pediremos un código al entrar.',
                'intro' => 'La verificación en dos pasos quedó activa en tu cuenta del panel de administración. Al entrar te pediremos un código de tu aplicación de autenticación.',
            ],
            'two_factor_disabled' => [
                'subject' => 'Desactivaste la verificación en dos pasos en Quinvite',
                'preheader' => 'Tu cuenta ya no pide un segundo paso.',
                'intro' => 'La verificación en dos pasos se desactivó en tu cuenta del panel de administración y cerramos tus otras sesiones.',
            ],
            'recovery_codes_regenerated' => [
                'subject' => 'Generaste códigos de recuperación nuevos en Quinvite',
                'preheader' => 'Los códigos anteriores ya no sirven.',
                'intro' => 'Se generaron códigos de recuperación nuevos para tu cuenta del panel de administración. Los anteriores dejaron de funcionar.',
            ],
            'two_factor_reset_by_admin' => [
                'subject' => 'Reiniciaron la verificación en dos pasos de tu cuenta de Quinvite',
                'preheader' => 'Cerramos todas tus sesiones.',
                'intro' => 'El equipo (:actor) desactivó la verificación en dos pasos de tu cuenta del panel de administración y cerramos todas tus sesiones. Al entrar de nuevo puedes volver a activarla desde Mi cuenta.',
            ],
            'new_device' => [
                'subject' => 'Nuevo inicio de sesión en el panel de Quinvite',
                'preheader' => 'Entraste desde un dispositivo que no conocíamos.',
                'intro' => 'Entraron a tu cuenta del panel de administración desde un dispositivo nuevo. Dispositivo, :device. Fecha, :when. Ubicación de red, :ip.',
            ],
        ],
    ],

    'admin_email_confirm' => [
        'subject' => 'Confirma tu nuevo correo del panel de Quinvite',
        'preheader' => 'Un clic y tu cuenta entra con este correo.',
        'title' => 'Hola, :name',
        'intro' => 'Pediste usar este correo para entrar al panel de administración de Quinvite. Confírmalo con el botón; el enlace vence en :minutes minutos.',
        'action' => 'Confirmar mi nuevo correo',
        'outro' => 'Si no lo pediste tú, ignora este mensaje y tu correo no cambiará.',
        'signature' => 'Equipo Quinvite',
    ],
];
