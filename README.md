<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## CWM API · desarrollo local

API de Celebrate It With Me (Laravel 13, PHP 8.4, PostgreSQL 17). Los repos `cwm_api`, `cwm_app` y `cwm_admin` deben estar como carpetas hermanas: el `docker-compose.yml` de este repo levanta los tres.

```bash
cp .env.example .env            # primera vez; DB_HOST=cwm_pgsql, DB_USERNAME/DB_PASSWORD los usa también el contenedor de PostgreSQL
export WWWUSER=$(id -u) WWWGROUP=$(id -g)
docker compose up -d --build
docker exec cwm_api composer install
docker exec cwm_api php artisan key:generate
docker exec cwm_api php artisan migrate:fresh --seed
```

Hosts locales (añadir a `/etc/hosts`): `127.0.0.1 dev.api.cwm.com dev.app.cwm.com dev.admin.cwm.com`.

### Datos de demo

`migrate:fresh --seed` carga, **solo con `APP_ENV=local`**, datos de ejemplo en español. Todas las cuentas usan la contraseña `password`.

| Cuenta | Rol | Evento |
|---|---|---|
| `marisol@cwm.test` | owner | **Los XV de Valentina** (evento completo: invitados, menús, itinerario, presupuesto, mesas, música...) |
| `carlos@cwm.test` | editor | Los XV de Valentina |
| `lucia@cwm.test` | viewer | Los XV de Valentina |
| `daniel@cwm.test` | owner | **Boda de Camila y Andrés** (parcial) y **Baby Shower de Sofía** (vacío) |

- El usuario del panel admin sale de `DEFAULT_ADMIN_EMAIL` / `DEFAULT_ADMIN_PASSWORD`. Si no están definidos (y no es producción) se usa `admin@cwm.test` / `password`.
- Los datos de referencia (roles y permisos, tipos de evento, planes, categorías de presupuesto) son idempotentes y se cargan en cualquier entorno: `php artisan db:seed`.
- Para cargar el demo en otro entorno que no sea producción: `php artisan db:seed --class='Database\Seeders\Demo\DemoSeeder'`.
- Los permisos de un usuario en un evento salen de su rol (`owner`, `editor`, `viewer`); el frontend protege cada ruta con un `requiredPermission`. La matriz completa está en `database/seeders/RolesAndPermissionSeeder.php`.

### Tests

```bash
docker exec cwm_api php vendor/bin/pest
```

Usan la base `testing` (creada al iniciar PostgreSQL) y `RefreshDatabase`.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains over 2000 video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[WebReinvent](https://webreinvent.com/)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Jump24](https://jump24.co.uk)**
- **[Redberry](https://redberry.international/laravel/)**
- **[Active Logic](https://activelogic.com)**
- **[byte5](https://byte5.de)**
- **[OP.GG](https://op.gg)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
