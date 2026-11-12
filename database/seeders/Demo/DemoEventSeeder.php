<?php

namespace Database\Seeders\Demo;

use App\Models\BudgetCategory;
use App\Models\EventPlan;
use App\Models\EventType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Builds three demo events with different levels of completeness:
 *  - "Los XV de Valentina": every section filled in (premium plan).
 *  - "Boda de Camila y Andrés": partially configured (basic plan).
 *  - "Baby Shower de Sofía": just created, nothing configured (empty states).
 *
 * Rows are written with the query builder on purpose: no model events/observers
 * fire (no notifications, jobs or e-mails) and JSON columns are explicit.
 */
class DemoEventSeeder extends Seeder
{
    private Carbon $now;

    public function run(): void
    {
        $this->now = now();

        $this->xv();
        $this->wedding();
        $this->babyShower();

        $this->command?->info('Demo events ready.');
    }

    // ---------------------------------------------------------------------
    // Event 1: complete
    // ---------------------------------------------------------------------

    private function xv(): void
    {
        $start = $this->now->copy()->addDays(45)->setTime(19, 0);

        $event = $this->createEvent([
            'name' => 'Los XV de Valentina',
            'description' => 'Celebra con nosotros los quince años de Valentina. Una noche de vals, música y mucha alegría en familia.',
            'slug' => 'xv-de-valentina',
            'status' => 'published',
            'visibility' => 'public',
            // Illustrative brand portrait (served by the app from public/demo), never a real client.
            'template_config' => ['sections' => [['type' => 'cover', 'settings' => ['image' => '/demo/valentina-portrait.webp']]]],
            'type' => 'quinceanera',
            'plan' => 'premium',
            'start' => $start,
            'end' => $start->copy()->addHours(6),
            'features' => [
                'save_the_date' => true, 'rsvp' => true, 'sweet_memories' => true, 'music' => true,
                'seats_accommodation' => true, 'preview' => true, 'budget' => true, 'analytics' => true,
                'background_music' => 0, 'event_comments' => 1, 'menu' => true, 'location' => true,
            ],
            'roles' => ['marisol@cwm.test' => 'owner', 'carlos@cwm.test' => 'editor', 'lucia@cwm.test' => 'viewer'],
            'active_for' => ['marisol@cwm.test', 'carlos@cwm.test', 'lucia@cwm.test'],
        ]);

        if ($event === null) {
            return;
        }

        $owner = $this->userId('marisol@cwm.test');

        $menus = $this->menus($event, [
            ['Menú adultos', 'Menú principal para los invitados adultos.', true, [
                ['starter', 'Croquetas de jamón', null],
                ['starter', 'Ensalada de aguacate y mango', 'vegetariano'],
                ['main', 'Lechón asado con moros y cristianos', null],
                ['main', 'Pollo en salsa de champiñones', null],
                ['dessert', 'Tres leches', null],
                ['dessert', 'Flan de coco', null],
            ]],
            ['Menú infantil', 'Opciones pensadas para los más pequeños.', false, [
                ['starter', 'Deditos de queso', null],
                ['main', 'Pasta con queso', null],
                ['dessert', 'Helado de vainilla', null],
            ]],
            ['Menú vegetariano', 'Sin carne ni pescado.', false, [
                ['starter', 'Ensalada Caprese', 'vegetariano'],
                ['main', 'Risotto de hongos', 'vegetariano'],
                ['dessert', 'Fruta fresca con miel', 'vegetariano'],
            ]],
        ]);

        $guestIds = $this->guests($event, 'VAL', $menus, [
            ['Rosa Fernández', 'female', 'attending', true, [['Jorge Fernández', 'male']]],
            ['Héctor Fernández', 'male', 'attending', true, [['Elena Marrero', 'female'], ['Mateo Fernández', 'male', 'kid']]],
            ['Teresa Pérez', 'female', 'attending', true, []],
            ['Ana María Pérez', 'female', 'attending', false, [['Luis Alberto Gómez', 'male']]],
            ['Pablo Fernández', 'male', 'attending', false, [['Sofía Rivas', 'female'], ['Emma Fernández', 'female', 'kid']]],
            ['Carolina Mesa', 'female', 'attending', false, []],
            ['Gabriel Ortega', 'male', 'attending', false, [['Natalia Ortega', 'female']]],
            ['Daniela Soto', 'female', 'attending', false, []],
            ['Andrés Castillo', 'male', 'not-attending', false, [['Paula Castillo', 'female']]],
            ['Lorena Duarte', 'female', 'attending', false, [['Iván Duarte', 'male', 'kid']]],
            ['Fernando Vidal', 'male', 'pending', false, []],
            ['Isabel Cabrera', 'female', 'attending', false, [['Rubén Cabrera', 'male']]],
            ['Miguel Ángel Torres', 'male', 'pending', false, [['Camila Torres', 'female']]],
            ['Patricia Herrera', 'female', 'attending', false, []],
            ['Javier Montes', 'male', 'not-attending', false, []],
            ['Verónica Salazar', 'female', 'attending', false, [['Diego Salazar', 'male', 'kid']]],
            ['Ricardo Peña', 'male', 'pending', false, [['Marta Peña', 'female']]],
            ['Alejandra Núñez', 'female', 'attending', false, []],
            ['Sebastián Guerra', 'male', 'attending', false, [['Valeria Guerra', 'female']]],
            ['Mariana Lozano', 'female', 'pending', false, []],
            ['Óscar Delgado', 'male', 'attending', false, []],
            ['Claudia Ibarra', 'female', 'attending', false, [['Tomás Ibarra', 'male', 'kid']]],
            ['Esteban Roldán', 'male', 'pending', false, []],
            ['Natalia Fonseca', 'female', 'attending', false, []],
        ]);

        $this->location($event, [
            'name' => 'Salón Cristal',
            'address' => '1200 NW 7th Street',
            'city' => 'Miami',
            'state' => 'FL',
            'zip_code' => '33125',
            'country' => 'Estados Unidos',
            'phone' => '+1 305 555 0142',
            'notes' => 'Estacionamiento gratuito. Entrada principal por la calle 7.',
            'latitude' => 25.7790,
            'longitude' => -80.2180,
        ]);

        $this->rsvp($event, 'Confirma tu asistencia', 'Por favor confírmanos si nos acompañarás en esta noche tan especial.', $start->copy()->subDays(15));
        $this->saveTheDate($event);

        $this->timeline($event, $start, [
            ['16:30', '17:30', 'Ceremonia religiosa', 'Misa de acción de gracias en la parroquia.'],
            ['19:00', '19:45', 'Recepción', 'Llegada de los invitados, cóctel de bienvenida y fotos.'],
            ['19:45', '20:30', 'Entrada de la quinceañera y vals', 'Entrada de Valentina y vals con familiares.'],
            ['20:30', '22:00', 'Cena', 'Servicio de cena para todos los invitados.'],
            ['22:00', '01:00', 'Baile', 'Música en vivo, baile sorpresa y brindis.'],
            ['01:00', null, 'Cierre', 'Entrega de recuerdos y despedida.'],
        ]);

        DB::table('dress_codes')->insert($this->row([
            'event_id' => $event,
            'dress_code_type' => 'formal',
            'description' => 'Vestimenta formal. Por favor evita el blanco y el fucsia, colores reservados para la quinceañera y su corte.',
            'reserved_colors' => json_encode(['#FFFFFF', '#C2185B']),
        ]));

        $this->budget($event, 18000, [
            ['venue', 'Salón Cristal (alquiler)', 'Salón y mobiliario para 6 horas.', 4500, 4500, true, -20],
            ['catering', 'Banquete para 100 personas', 'Cena, bebidas y torta.', 6500, null, false, 30],
            ['decoration', 'Decoración y flores', 'Centros de mesa y arco de entrada.', 1800, null, false, 35],
            ['entertainment', 'DJ y vals coreografiado', 'Música, luces y coreógrafo.', 1200, 1200, true, -10],
            ['photography', 'Foto y video', 'Cobertura completa de 8 horas.', 1500, null, false, 40],
            ['outfits', 'Vestido de XV', 'Vestido, accesorios y ajustes.', 1400, 1650, true, -30],
            ['transportation', 'Limusina', 'Traslado de la quinceañera y su corte.', 600, null, false, 44],
            ['miscellaneous', 'Recuerdos para invitados', '100 recuerdos personalizados.', 450, 380, true, -5],
        ]);

        $this->music($event, $guestIds);
        $this->seating($event, $owner, $guestIds);

        DB::table('sweet_memories_config')->insert($this->row([
            'event_id' => $event,
            'title' => 'Dulces recuerdos',
            'sub_title' => 'Comparte tus fotos favoritas de la noche',
            'background_color' => '#FCE4EC',
            'max_pictures' => 20,
        ]));

        DB::table('event_config_comment')->insert($this->row([
            'event_id' => $event,
            'title' => 'Mensajes para Valentina',
            'sub_title' => 'Déjale unas palabras en su día especial',
            'background_color' => '#FFF8E1',
            'comments_title' => 'Lo que dicen sus invitados',
            'button_color' => '#C2185B',
            'button_text' => 'Enviar mensaje',
            'max_comments' => 100,
        ]));

        $this->comments($event, $guestIds, [
            'Valentina, que este nuevo año de vida esté lleno de sueños cumplidos. ¡Te queremos mucho!',
            '¡Felicidades, mi niña! Ya quiero verte bailar el vals.',
            'Una noche inolvidable nos espera. ¡Gracias por invitarnos!',
            'Con mucho cariño desde la distancia. ¡Feliz cumpleaños número 15!',
        ]);

        DB::table('event_collaboration_invites')->insert($this->row([
            'event_id' => $event,
            'email' => 'tia.elena@example.com',
            'role' => 'viewer',
            'token' => Str::random(40),
            'status' => 'pending',
            'invited_by_user_id' => $owner,
            'resend_count' => 0,
            'sent_at' => $this->now->copy()->subDays(2),
            'expires_at' => $this->now->copy()->addDays(5),
        ]));
    }

    // ---------------------------------------------------------------------
    // Event 2: partially configured
    // ---------------------------------------------------------------------

    private function wedding(): void
    {
        $start = $this->now->copy()->addDays(120)->setTime(17, 0);

        $event = $this->createEvent([
            'name' => 'Boda de Camila y Andrés',
            'description' => 'Camila y Andrés se casan y quieren compartir ese día con las personas que más quieren.',
            'slug' => 'boda-camila-y-andres',
            'status' => 'published',
            'visibility' => 'private',
            'type' => 'wedding',
            'plan' => 'basic',
            'start' => $start,
            'end' => $start->copy()->addHours(7),
            'features' => ['save_the_date' => true, 'rsvp' => true, 'menu' => true, 'location' => true, 'budget' => false],
            'roles' => ['daniel@cwm.test' => 'owner'],
            'active_for' => [],
        ]);

        if ($event === null) {
            return;
        }

        $menus = $this->menus($event, [
            ['Cena de boda', 'Menú único de tres tiempos.', true, [
                ['starter', 'Crema de calabaza', 'vegetariano'],
                ['main', 'Filete de res con puré de papa', null],
                ['main', 'Salmón al limón', null],
                ['dessert', 'Pastel de bodas', null],
            ]],
        ]);

        $this->guests($event, 'BOD', $menus, [
            ['Laura Giménez', 'female', 'attending', true, [['Pedro Giménez', 'male']]],
            ['Hugo Salinas', 'male', 'attending', true, []],
            ['Marta Olivares', 'female', 'pending', false, [['Raúl Olivares', 'male']]],
            ['Cristian Bravo', 'male', 'pending', false, []],
            ['Silvia Montero', 'female', 'not-attending', false, []],
            ['Tomás Aguirre', 'male', 'attending', false, [['Nuria Aguirre', 'female']]],
            ['Beatriz Campos', 'female', 'pending', false, []],
            ['Manuel Robles', 'male', 'pending', false, [['Inés Robles', 'female']]],
            ['Pilar Medina', 'female', 'pending', false, []],
            ['Gonzalo Ruiz', 'male', 'pending', false, []],
        ]);

        $this->location($event, [
            'name' => 'Jardín Los Almendros',
            'address' => '8400 SW 72nd Avenue',
            'city' => 'Miami',
            'state' => 'FL',
            'zip_code' => '33143',
            'country' => 'Estados Unidos',
            'phone' => '+1 305 555 0177',
            'notes' => 'Ceremonia y recepción en el mismo lugar.',
            'latitude' => 25.7030,
            'longitude' => -80.3010,
        ]);

        $this->rsvp($event, 'Confirmación de asistencia', 'Ayúdanos a organizar el día confirmando tu asistencia.', $start->copy()->subDays(30));
        $this->saveTheDate($event);
    }

    // ---------------------------------------------------------------------
    // Event 3: empty (just created)
    // ---------------------------------------------------------------------

    private function babyShower(): void
    {
        $start = $this->now->copy()->addDays(30)->setTime(15, 0);

        $this->createEvent([
            'name' => 'Baby Shower de Sofía',
            'description' => 'Bienvenida al bebé de Sofía.',
            'slug' => 'baby-shower-sofia',
            'status' => 'draft',
            'visibility' => 'private',
            'type' => 'baby-shower',
            'plan' => 'basic',
            'start' => $start,
            'end' => $start->copy()->addHours(4),
            'features' => [],
            'roles' => ['daniel@cwm.test' => 'owner'],
            'active_for' => ['daniel@cwm.test'],
        ]);
    }

    // ---------------------------------------------------------------------
    // Builders
    // ---------------------------------------------------------------------

    /** @return int|null event id, or null if the event already exists */
    private function createEvent(array $data): ?int
    {
        if (DB::table('events')->where('custom_url_slug', $data['slug'])->exists()) {
            $this->command?->line("  · {$data['name']}: already exists, skipped.");

            return null;
        }

        $id = DB::table('events')->insertGetId($this->row([
            'event_name' => $data['name'],
            'event_description' => $data['description'],
            'start_date' => $data['start'],
            'end_date' => $data['end'],
            'status' => $data['status'],
            'custom_url_slug' => $data['slug'],
            'visibility' => $data['visibility'],
            'event_plan_id' => EventPlan::query()->where('slug', $data['plan'])->value('id'),
            'event_type_id' => EventType::query()->where('slug', $data['type'])->value('id'),
            'template_config' => isset($data['template_config']) ? json_encode($data['template_config']) : null,
        ]));

        DB::table('event_features')->insert($this->row(['event_id' => $id] + $data['features']));

        foreach ($data['roles'] as $email => $roleName) {
            DB::table('event_user_roles')->insert($this->row([
                'event_id' => $id,
                'user_id' => $this->userId($email),
                'role_id' => Role::findByName($roleName, 'web')->id,
            ]));
        }

        foreach ($data['active_for'] as $email) {
            DB::table('users')->where('id', $this->userId($email))->update(['last_active_event_id' => $id]);
        }

        $this->command?->line("  · {$data['name']} (id {$id})");

        return $id;
    }

    /**
     * @param  array<int, array{0:string,1:string,2:bool,3:array}>  $menus  title, description, default?, items[type,name,diet]
     * @return array{default:int, kids:int|null, veg:int|null}  menu ids
     */
    private function menus(int $event, array $menus): array
    {
        $ids = [];

        foreach ($menus as [$title, $description, $isDefault, $items]) {
            $menuId = DB::table('menus')->insertGetId($this->row([
                'event_id' => $event,
                'title' => $title,
                'description' => $description,
                'allow_multiple_choices' => false,
                'allow_custom_request' => true,
                'is_default' => $isDefault,
            ]));

            foreach ($items as [$type, $name, $diet]) {
                DB::table('menu_items')->insert($this->row([
                    'menu_id' => $menuId,
                    'name' => $name,
                    'type' => $type,
                    'diet_type' => $diet,
                ]));
            }

            $ids[] = ['id' => $menuId, 'title' => $title, 'default' => $isDefault];
        }

        $find = fn (string $needle) => collect($ids)->first(fn ($m) => str_contains(Str::lower($m['title']), $needle))['id'] ?? null;

        return [
            'default' => collect($ids)->firstWhere('default', true)['id'],
            'kids' => $find('infantil'),
            'veg' => $find('vegetariano'),
        ];
    }

    /**
     * Inserts main guests and their companions. Returns ids grouped for later use.
     *
     * @param  array<int, array{0:string,1:string,2:string,3:bool,4:array}>  $guests  name, gender, status, vip, companions
     * @return array<int, array{id:int, name:string, status:string, vip:bool, kid:bool, parent:int|null}>
     */
    private function guests(int $event, string $codePrefix, array $menus, array $guests): array
    {
        $created = [];
        $n = 0;

        foreach ($guests as $i => [$name, $gender, $status, $vip, $companions]) {
            $statusDate = $status === 'pending' ? null : $this->now->copy()->subDays(1 + ($i % 12));

            $mainId = DB::table('guests')->insertGetId($this->row([
                'event_id' => $event,
                'parent_id' => null,
                'name' => $name,
                'email' => Str::slug($name, '.').'@example.com',
                'phone' => sprintf('+1 305 555 %04d', 100 + $i),
                'is_vip' => $vip,
                'rsvp_status' => $status,
                'rsvp_status_date' => $statusDate,
                'code' => sprintf('%s%03d', $codePrefix, ++$n),
                'gender' => $gender,
                'assigned_menu_id' => $this->menuFor($menus, $i, false),
                'allergies' => $i % 7 === 3 ? 'Alérgico a los frutos secos' : null,
                'notes' => $vip ? 'Familiar cercano' : null,
                'tags' => json_encode($vip ? ['familia'] : []),
            ]));

            $created[] = ['id' => $mainId, 'name' => $name, 'status' => $status, 'vip' => $vip, 'kid' => false, 'parent' => null];

            foreach ($companions as $j => $companion) {
                [$cName, $cGender] = $companion;
                $kid = ($companion[2] ?? null) === 'kid';

                $companionId = DB::table('guests')->insertGetId($this->row([
                    'event_id' => $event,
                    'parent_id' => $mainId,
                    'name' => $cName,
                    'phone' => null,
                    'is_vip' => false,
                    'rsvp_status' => $status,
                    'rsvp_status_date' => $statusDate,
                    'code' => sprintf('%s%03d', $codePrefix, ++$n),
                    'gender' => $cGender,
                    'assigned_menu_id' => $this->menuFor($menus, $i + $j + 1, $kid),
                    'tags' => json_encode($kid ? ['niño'] : []),
                ]));

                $created[] = ['id' => $companionId, 'name' => $cName, 'status' => $status, 'vip' => false, 'kid' => $kid, 'parent' => $mainId];
            }
        }

        return $created;
    }

    private function menuFor(array $menus, int $index, bool $kid): int
    {
        if ($kid && $menus['kids']) {
            return $menus['kids'];
        }

        if ($menus['veg'] && $index % 6 === 5) {
            return $menus['veg'];
        }

        return $menus['default'];
    }

    private function location(int $event, array $data): void
    {
        DB::table('event_locations')->insert($this->row(['event_id' => $event, 'is_default' => true] + $data));
    }

    private function rsvp(int $event, string $title, string $description, Carbon $deadline): void
    {
        DB::table('rsvps')->insert($this->row([
            'event_id' => $event,
            'title' => $title,
            'description' => $description,
            'custom_fields' => json_encode([]),
            'confirmation_deadline' => $deadline,
        ]));
    }

    private function saveTheDate(int $event): void
    {
        DB::table('save_the_dates')->insert($this->row([
            'event_id' => $event,
            'show_countdown' => true,
            'use_add_to_calendar' => true,
            'date_source' => 'event',
            'countdown_units' => json_encode(['days', 'hours', 'minutes']),
            'countdown_finish_behavior' => 'message',
            'calendar_providers' => json_encode(['google', 'apple', 'outlook']),
            'calendar_mode' => 'modal',
        ]));
    }

    /** @param array<int, array{0:string,1:?string,2:string,3:string}> $items start, end, title, description (HH:MM) */
    private function timeline(int $event, Carbon $day, array $items): void
    {
        foreach ($items as [$from, $to, $title, $description]) {
            $startTime = $day->copy()->setTimeFromTimeString($from);
            // Items after midnight belong to the next calendar day.
            if ($startTime->lt($day->copy()->setTime(12, 0))) {
                $startTime->addDay();
            }

            $endTime = null;
            if ($to) {
                $endTime = $day->copy()->setTimeFromTimeString($to);
                if ($endTime->lte($startTime)) {
                    $endTime->addDay();
                }
            }

            DB::table('timelines')->insert($this->row([
                'event_id' => $event,
                'title' => $title,
                'description' => $description,
                'start_time' => $startTime,
                'end_time' => $endTime,
            ]));
        }
    }

    /** @param array<int, array{0:string,1:string,2:string,3:int|float,4:int|float|null,5:bool,6:int}> $items category slug, title, description, estimated, actual, paid, due in days */
    private function budget(int $event, int $cap, array $items): void
    {
        $budgetId = DB::table('event_budgets')->insertGetId($this->row(['event_id' => $event, 'budget_cap' => $cap]));

        foreach ($items as [$slug, $title, $description, $estimated, $actual, $paid, $dueIn]) {
            DB::table('budget_items')->insert($this->row([
                'event_budget_id' => $budgetId,
                'category_id' => BudgetCategory::query()->where('slug', $slug)->value('id'),
                'title' => $title,
                'description' => $description,
                'estimated_cost' => $estimated,
                'actual_cost' => $actual,
                'is_paid' => $paid,
                'due_date' => $this->now->copy()->addDays($dueIn)->startOfDay(),
            ]));
        }
    }

    private function music(int $event, array $guests): void
    {
        DB::table('suggested_music_configs')->insert($this->row([
            'event_id' => $event,
            'title' => 'Pide tu canción',
            'sub_title' => 'Ayúdanos a armar la playlist de la fiesta',
            'main_color' => '#C2185B',
            'secondary_color' => '#FCE4EC',
            'use_preview' => true,
            'use_vote_system' => true,
            'search_limit' => 10,
        ]));

        $attending = collect($guests)->where('status', 'attending')->where('parent', null)->values();

        $songs = [
            ['Bailando', 'Enrique Iglesias', 'Sex and Love'],
            ['Danza Kuduro', 'Don Omar', 'Meet the Orphans'],
            ['Vivir mi vida', 'Marc Anthony', '3.0'],
            ['La Camisa Negra', 'Juanes', 'Mi Sangre'],
            ['Hips Don\'t Lie', 'Shakira', 'Oral Fixation, Vol. 2'],
            ['La Bamba', 'Ritchie Valens', 'Ritchie Valens'],
        ];

        foreach ($songs as $i => [$title, $artist, $album]) {
            DB::table('suggested_music')->insert($this->row([
                'event_id' => $event,
                'title' => $title,
                'artist' => $artist,
                'album' => $album,
                'platform' => 'spotify',
                'platformId' => sprintf('demo-%d-track-%02d', $event, $i + 1),
                'thumbnailUrl' => 'https://placehold.co/300x300?text='.rawurlencode($title),
                'suggested_by_entity' => 'guest',
                'suggested_by_id' => $attending[$i % $attending->count()]['id'],
            ]));
        }
    }

    /** Four tables and the attending guests seated by affinity. */
    private function seating(int $event, int $ownerId, array $guests): void
    {
        $tables = [
            ['Mesa de Honor', 'vip', 10],
            ['Familia Fernández', 'family', 12],
            ['Amigos de Valentina', 'friends', 12],
            ['Mesa general', 'general', 14],
        ];

        $tableIds = [];
        foreach ($tables as $priority => [$name, $type, $capacity]) {
            $tableIds[$type] = [
                'id' => DB::table('tables')->insertGetId($this->row([
                    'event_id' => $event,
                    'name' => $name,
                    'capacity' => $capacity,
                    'type' => $type,
                    'priority' => $priority + 1,
                ])),
                'capacity' => $capacity,
                'seats' => 0,
            ];
        }

        foreach (collect($guests)->where('status', 'attending') as $guest) {
            $type = match (true) {
                $guest['vip'] => 'vip',
                str_contains($guest['name'], 'Fernández') => 'family',
                $guest['kid'] => 'family',
                default => strlen($guest['name']) % 2 === 0 ? 'friends' : 'general',
            };

            // Overflow to the general table when a table is full.
            if ($tableIds[$type]['seats'] >= $tableIds[$type]['capacity']) {
                $type = 'general';
            }

            $tableIds[$type]['seats']++;

            DB::table('table_assignments')->insert($this->row([
                'table_id' => $tableIds[$type]['id'],
                'guest_id' => $guest['id'],
                'seat_number' => $tableIds[$type]['seats'],
                'assigned_at' => $this->now,
                'assigned_by' => $ownerId,
            ]));
        }
    }

    private function comments(int $event, array $guests, array $messages): void
    {
        $attending = collect($guests)->where('status', 'attending')->where('parent', null)->values();

        foreach ($messages as $i => $message) {
            DB::table('event_comments')->insert($this->row([
                'event_id' => $event,
                'comment' => $message,
                'authorable_type' => 'guest', // morph map alias (Relation::enforceMorphMap)
                'authorable_id' => $attending[$i + 2]['id'],
                'status' => 'visible',
                'is_pinned' => $i === 0,
                'is_favorite' => $i === 1,
            ]));
        }
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function userId(string $email): int
    {
        return (int) User::query()->where('email', $email)->value('id');
    }

    /** Adds created_at / updated_at to a row. */
    private function row(array $data): array
    {
        return $data + ['created_at' => $this->now, 'updated_at' => $this->now];
    }
}
