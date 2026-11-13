Hola {{ $name }},

El equipo de Quinvite entró a tu cuenta el {{ $date }} durante {{ $minutes }} {{ $minutes === 1 ? 'minuto' : 'minutos' }} {{ $fromRequest ? 'para ayudarte con tu solicitud de soporte' : 'para ayudarte con tu cuenta' }}.

Solo lo hacemos para atender una solicitud de ayuda y toda la sesión queda registrada. Si no esperabas este acceso o tienes dudas, escríbenos a {{ $supportEmail }}.

Equipo Quinvite
