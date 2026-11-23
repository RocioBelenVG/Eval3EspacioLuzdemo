<?php
/**
 * Datos base del centro. Equivale a lib/data.ts.
 * La estructura (ids, slugs, imágenes, tarifas) vive aquí; los textos pueden
 * sobrescribirse desde content/overrides.json (ver lib/content.php).
 */

declare(strict_types=1);

// Tabla "Box Individuales" del catálogo real (Loica, Martín Pescador, 7 Colores).
$tarifasBoxIndividual = [
    ['precio' => '$8.000', 'label' => 'Por hora'],
    [
        'precio' => '$140.000',
        'label'  => '1 día a la semana mensual',
        'sub'    => 'Jornada mañana · de 08:00 a 13:00 h',
    ],
    [
        'precio' => '$150.000',
        'label'  => '1 día a la semana mensual',
        'sub'    => 'Jornada tarde · desde las 14:00 h',
    ],
    [
        'precio'        => '$350.000',
        'label'         => 'Exclusividad mensual',
        'sub'           => 'Media jornada mañana',
        'gastosComunes' => '$2.500',
    ],
    [
        'precio'        => '$400.000',
        'label'         => 'Exclusividad mensual',
        'sub'           => 'Media jornada tarde',
        'gastosComunes' => '$2.500',
    ],
    [
        'precio'        => '$600.000',
        'label'         => 'Jornada completa mensual',
        'gastosComunes' => '$5.000',
    ],
];

$CENTRO = [
    'nombre'               => 'Espacio Luz',
    'heroTitulo'           => 'Bienvenido a Espacio',
    'heroDescripcionExtra' => 'Un lugar pensado para acompañarte a ti y a tu familia con calidez, cercanía y profesionalismo.',
    // Bloques únicos de texto del hero y del footer (editables desde el sitio).
    'heroTexto'            => 'Centro integral de terapias y bienestar. Un lugar pensado para acompañarte a ti y a tu familia con calidez, cercanía y profesionalismo.',
    'footerTexto'          => 'Centro integral de terapias y bienestar. Un espacio cálido para tu bienestar y el de tu familia en Avenida Oriente 196, Villa Cataluña, Los Ángeles.',
    'ctaAgendar'           => 'Agenda tu hora',
    'ctaBoxes'             => 'Conoce nuestros espacios',
    'boxElEspacioTitulo'   => 'El espacio',
    'boxEquipamientoTitulo' => 'Equipamiento',
    'descripcion'          => 'Centro integral de terapias y bienestar',
    'ubicacion'            => 'Avenida Oriente 196, Villa Cataluña, Los Ángeles',
    'telefono'             => '+56 9 3403 5774',
    'email'                => 'contacto@espacioluz.cl',
    'instagram'            => '@espacioterapeuticoluz',
];

$SECCIONES = [
    'atenciones' => [
        'eyebrow'     => 'Cómo acompañamos',
        'titulo'      => 'Tipos de atención',
        'descripcion' => 'En Espacio Luz acompañamos cada proceso de forma integral y cercana. Estas son las modalidades de atención con las que trabajamos.',
        'nota'        => '¿No sabes por dónde comenzar? Escríbenos y te orientamos para encontrar la atención más adecuada para ti o tu familia.',
    ],
    'ubicacion' => [
        'eyebrow'     => 'Visítanos',
        'titulo'      => 'Cómo llegar',
        'descripcion' => 'Nos encontramos en Avenida Oriente 196, Villa Cataluña, Los Ángeles.',
    ],
    'especialidades' => [
        'eyebrow'     => 'Nuestras áreas',
        'titulo'      => 'Especialidades',
        'descripcion' => 'Tres disciplinas que trabajan en conjunto para acompañar tu proceso de comunicación, funcionalidad y bienestar emocional.',
    ],
    'profesionales' => [
        'eyebrow'     => 'Nuestro equipo',
        'titulo'      => 'Profesionales',
        'descripcion' => 'Un equipo cercano y especializado, dedicado a la atención individual de cada persona que llega a Espacio Luz.',
    ],
    'boxes' => [
        'eyebrow'     => 'Nuestros espacios',
        'titulo'      => 'Boxes',
        'descripcion' => 'Cinco espacios con identidad propia, cada uno acompañado de su ave. Desliza para conocerlos todos.',
    ],
];

// Tarjetas de "Tipos de atención". Los textos (tag/titulo/desc) son editables
// desde el sitio; 'accent' e 'icon' son presentación y no se editan.
$ATENCIONES = [
    [
        'tag' => 'Primer paso',
        'titulo' => 'Evaluación y diagnóstico',
        'desc' => 'Una valoración inicial en cada área para comprender la necesidad y orientar un plan de trabajo a la medida de cada persona.',
        'accent' => 'terracota',
        'icon' => '<path d="M9 3h6a1 1 0 0 1 1 1v1h2a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2V4a1 1 0 0 1 1-1Z"/><path d="M9 5h6"/><path d="M8.5 12.5l2 2 4-4.5"/>',
    ],
    [
        'tag' => 'Proceso',
        'titulo' => 'Intervención terapéutica',
        'desc' => 'Sesiones individuales personalizadas, con objetivos claros y seguimiento del progreso a lo largo del tratamiento.',
        'accent' => 'salvia',
        'icon' => '<path d="M12 21s-7-4.35-9.5-8.5C.9 9.8 2.3 6 5.6 6c1.9 0 3.1 1.1 3.9 2.3C10.3 7.1 11.5 6 13.4 6c3.3 0 4.7 3.8 3.1 6.5C19 16.65 12 21 12 21Z"/>',
    ],
    [
        'tag' => 'En conjunto',
        'titulo' => 'Orientación familiar',
        'desc' => 'Acompañamiento y pautas para la familia, de modo que el trabajo terapéutico se extienda también al hogar.',
        'accent' => 'terracota',
        'icon' => '<circle cx="8" cy="8.5" r="2.4"/><circle cx="16" cy="8.5" r="2.4"/><path d="M3.5 19c0-2.5 2-4 4.5-4s4.5 1.5 4.5 4"/><path d="M12.5 19c0-2.5 2-4 4.5-4s4 1.5 4 4"/>',
    ],
    [
        'tag' => 'En cada etapa',
        'titulo' => 'Atención por etapas de la vida',
        'desc' => 'Un enfoque adecuado a cada momento: niñez, adolescencia y vida adulta, respetando el ritmo de cada persona.',
        'accent' => 'salvia',
        'icon' => '<path d="M3 19c4-9 14-9 18 0"/><circle cx="7.5" cy="13.5" r="1.4"/><circle cx="12" cy="10.2" r="1.4"/><circle cx="16.5" cy="13.5" r="1.4"/>',
    ],
];

$ESPECIALIDADES = [
    [
        'id' => 1,
        'slug' => 'fonoaudiologia',
        'nombre' => 'Fonoaudiología',
        'descripcion' => 'Especialidad en comunicación, lenguaje y audición',
        'beneficios' => [
            'Evaluación del desarrollo del lenguaje',
            'Terapia de pronunciación',
            'Apoyo en audición',
        ],
        'icono' => '/images/specialties/fonoaudiologia.jpg',
    ],
    [
        'id' => 2,
        'slug' => 'terapia-ocupacional',
        'nombre' => 'Terapia Ocupacional',
        'descripcion' => 'Especialidad en funcionalidad e independencia en actividades cotidianas',
        'beneficios' => [
            'Desarrollo de habilidades motoras',
            'Independencia en actividades diarias',
            'Integración sensorial',
        ],
        'icono' => '/images/specialties/terapia-ocupacional.jpg',
    ],
    [
        'id' => 3,
        'slug' => 'psicologia',
        'nombre' => 'Psicología',
        'descripcion' => 'Especialidad en salud mental y bienestar emocional',
        'beneficios' => [
            'Apoyo emocional',
            'Terapia individual',
            'Orientación y consejería',
        ],
        'icono' => '/images/specialties/psicologia.jpg',
    ],
];

$PROFESIONALES = [
    [
        'id' => 1,
        'nombre' => 'María González',
        'especialidadSlug' => 'fonoaudiologia',
        'descripcion' => 'Especialista en desarrollo del lenguaje y comunicación',
        'enfoque' => 'Atención individual de niños, adolescentes y adultos',
        'foto' => '/images/professionals/maria-gonzalez.jpg',
        'prestaciones' => ['Evaluación', 'Terapia'],
        'duracion' => '45-60 minutos',
    ],
    [
        'id' => 2,
        'nombre' => 'Carlos López',
        'especialidadSlug' => 'terapia-ocupacional',
        'descripcion' => 'Especialista en funcionalidad e independencia en actividades cotidianas',
        'enfoque' => 'Atención infantil con equipamiento especializado',
        'foto' => '/images/professionals/carlos-lopez.jpg',
        'prestaciones' => ['Evaluación', 'Terapia', 'Asesoría'],
        'duracion' => '60 minutos',
    ],
    [
        'id' => 3,
        'nombre' => 'Ana Martínez',
        'especialidadSlug' => 'psicologia',
        'descripcion' => 'Especialista en salud mental y bienestar emocional',
        'enfoque' => 'Atención individual de adolescentes y adultos',
        'foto' => '/images/professionals/ana-martinez.jpg',
        'prestaciones' => ['Consulta', 'Terapia individual'],
        'duracion' => '45-60 minutos',
    ],
];

$BOXES = [
    [
        'id' => 1,
        'slug' => 'loica',
        'nombre' => 'Loica',
        'ave' => 'Loica',
        'aveSlug' => 'loica',
        'imagen' => '/images/boxes/preview/loica.jpg',
        'descripcion' => 'Espacio orientado a atención individual de adolescentes y adultos',
        'ubicacion' => 'Segundo piso',
        'elEspacioDescripcion' => 'Espacio privado en segundo piso.',
        'equipamiento' => [
            '2 sillones individuales',
            'Estufa',
            'Velas y aromas',
            'Almacenamiento de objetos personales',
        ],
        'publicoObjetivo' => 'Adolescentes y adultos',
        'tarifas' => $tarifasBoxIndividual,
    ],
    [
        'id' => 2,
        'slug' => 'martin-pescador',
        'nombre' => 'Martín Pescador',
        'ave' => 'Martín Pescador',
        'aveSlug' => 'martin-pescador',
        'imagen' => '/images/boxes/preview/martin-pescador.jpg',
        'descripcion' => 'Espacio orientado a atención individual de adolescentes y adultos',
        'ubicacion' => 'Segundo piso',
        'elEspacioDescripcion' => 'Espacio privado en segundo piso.',
        'equipamiento' => [
            '2 sillones individuales',
            'Estufa',
            'Velas y aromas',
            'Almacenamiento de objetos personales',
        ],
        'publicoObjetivo' => 'Adolescentes y adultos',
        'tarifas' => $tarifasBoxIndividual,
    ],
    [
        'id' => 3,
        'slug' => '7-colores',
        'nombre' => '7 Colores',
        'ave' => 'Siete Colores',
        'aveSlug' => '7-colores',
        'imagen' => '/images/boxes/preview/7-colores.jpg',
        'descripcion' => 'Espacio orientado a atención individual de niños',
        'ubicacion' => 'Primer piso',
        'elEspacioDescripcion' => 'Espacio privado en primer piso.',
        'equipamiento' => [
            '2 sillones individuales',
            'Espacio de juego',
            'Almacenamiento de objetos personales',
        ],
        'publicoObjetivo' => 'Niños',
        'tarifas' => $tarifasBoxIndividual,
    ],
    [
        'id' => 4,
        'slug' => 'colibri-gimnasio',
        'nombre' => 'Colibrí Gimnasio',
        'ave' => 'Colibrí',
        'aveSlug' => 'colibri',
        'imagen' => '/images/boxes/preview/colibri.jpg',
        'descripcion' => 'Sala especializada de terapia ocupacional para atención infantil',
        'ubicacion' => 'Primer piso',
        'elEspacioDescripcion' => 'Espacio privado en primer piso.',
        'equipamiento' => [
            'Cama saltarina individual',
            'Hamacas',
            'Piscina de pelotas',
            'Suelo acolchado',
        ],
        'publicoObjetivo' => 'Niños',
        'tarifas' => [['precio' => '$12.000', 'label' => 'Por hora']],
    ],
    [
        'id' => 5,
        'slug' => 'gimnasio-golondrina',
        'nombre' => 'Gimnasio Golondrina',
        'ave' => 'Golondrina',
        'aveSlug' => 'golondrina',
        'imagen' => '/images/boxes/preview/golondrina.jpg',
        'descripcion' => 'Sala de gimnasio para estimulación y trabajo motor infantil',
        'ubicacion' => 'Primer piso',
        'elEspacioDescripcion' => 'Espacio privado en primer piso.',
        'equipamiento' => [
            'Cama saltarina individual',
            'Hamacas',
            'Piscina de pelotas',
            'Suelo acolchado',
        ],
        'publicoObjetivo' => 'Niños',
        'tarifas' => [['precio' => '$12.000', 'label' => 'Por hora']],
    ],
];

// Páginas de contenido (Quiénes somos, Privacidad, Términos). Textos editables
// desde el sitio. Los legales son una base profesional para adaptar a Chile
// (Ley 19.628 / normativa de protección de datos); conviene revisarlos.
$PAGINAS = [
    'quienes-somos' => [
        'eyebrow'         => 'Quiénes somos',
        'titulo'          => 'Un espacio pensado para acompañarte',
        'intro'           => 'En Espacio Luz creemos que el bienestar se construye en un entorno cálido, cercano y profesional. Somos un centro integral de terapias donde cada persona y cada familia encuentra un acompañamiento a su medida, con calidez y profesionalismo.',
        'fundadorasLema'   => 'Hecho con dedicación y cariño',
        'fundadorasNombre' => 'Fernanda y Javiera',
        'fundadorasRol'    => 'Fundadoras de Espacio Luz',
        // Video de la sección (archivo en assets/videos/). Se puede cambiar subiendo un mp4.
        'video'            => 'QuienesSomos.mp4',
        // Tarjetas "Contamos con" (los chips se separan por coma).
        'c1Titulo' => 'Equipo multidisciplinario',
        'c1Desc'   => 'Profesionales de distintas áreas trabajando juntas por tu bienestar.',
        'c1Chips'  => 'Fonoaudiología, Terapia Ocupacional, Psicología, Psiquiatría, Psicopedagogía',
        'c1Cta'    => 'Conoce al equipo',
        'c2Titulo' => 'Atención integral para cada edad',
        'c2Desc'   => 'Acompañamos distintos procesos y etapas de la vida.',
        'c2Chips'  => 'Niñez, Adolescencia, Personas adultas, Familias',
        'c2Cta'    => 'Ver atenciones',
        'c3Titulo' => 'Trato cálido y humano',
        'c3Desc'   => 'Un espacio cercano donde sentirte en confianza desde el primer día.',
        'c3Chips'  => 'Atención personalizada, Ambiente acogedor, Respeto por tus tiempos',
        'c3Cta'    => 'Agenda tu hora',
        's1t' => 'Nuestra historia',
        's1b' => 'Espacio Luz nace del deseo de crear un lugar distinto: un espacio luminoso y acogedor donde la atención terapéutica se viva con calidez y respeto por los tiempos de cada persona. Reunimos a un equipo de profesionales de distintas áreas para acompañar procesos de comunicación, desarrollo, bienestar emocional y funcionalidad.',
        's2t' => 'Nuestro propósito',
        's2b' => 'Acompañar a niños, adolescentes, adultos y sus familias en su proceso de bienestar, con una atención integral, personalizada y humana, en un ambiente que invita a sentirse en confianza.',
        's3t' => 'Nuestro equipo',
        's3b' => 'Contamos con profesionales de fonoaudiología, terapia ocupacional, psicología, psiquiatría y psicopedagogía, comprometidas con una atención cercana y de calidad.',
        's4t' => 'El espacio',
        's4b' => 'Nuestros boxes están pensados para que cada sesión se sienta tranquila y segura, con ambientes cálidos y equipados para distintas edades y necesidades.',
    ],
    'privacidad' => [
        'titulo'      => 'Política de privacidad',
        'actualizado' => 'Última actualización: octubre de 2026',
        'intro'       => 'En Espacio Luz valoramos y protegemos tu privacidad. Esta Política describe cómo recopilamos, usamos y resguardamos tus datos personales cuando visitas nuestro sitio o agendas una hora, en conformidad con la Ley N° 19.628 sobre Protección de la Vida Privada y la normativa chilena vigente en materia de protección de datos personales.',
        's1t' => '1. Responsable del tratamiento',
        's1r' => 'Quién responde por tus datos y cómo contactarnos.',
        's1b' => 'El responsable del tratamiento de tus datos es Espacio Luz, con domicilio en Avenida Oriente 196, Villa Cataluña, Los Ángeles. Para cualquier consulta sobre tus datos puedes escribirnos a contacto@espacioluz.cl.',
        's2t' => '2. Qué datos recopilamos',
        's2r' => 'Los datos que nos entregas al agendar o contactarnos.',
        's2b' => 'Recopilamos los datos que nos entregas al agendar una hora o contactarnos: nombre, correo electrónico y teléfono. También podemos registrar información técnica básica de navegación, con fines de funcionamiento del sitio.',
        's3t' => '3. Para qué usamos tus datos',
        's3r' => 'Gestionar tus reservas y mejorar la atención.',
        's3b' => 'Usamos tus datos para gestionar y confirmar tus reservas, enviarte el comprobante de tu hora, contactarte en relación con la atención solicitada y mejorar nuestros servicios. No usamos tus datos para fines distintos a los aquí informados.',
        's4t' => '4. Consentimiento y base del tratamiento',
        's4r' => 'Tratamos tus datos con tu consentimiento.',
        's4b' => 'El tratamiento de tus datos se realiza sobre la base del consentimiento que otorgas al agendar o contactarnos, y para la ejecución de la atención que solicitas. Puedes revocar tu consentimiento en cualquier momento.',
        's5t' => '5. Con quién compartimos tus datos',
        's5r' => 'No vendemos tus datos; acceso solo al equipo necesario.',
        's5b' => 'No vendemos ni cedemos tus datos a terceros. Solo acceden a ellos los integrantes del equipo necesarios para gestionar tu atención y los proveedores tecnológicos que nos prestan servicios (por ejemplo, correo o alojamiento), sujetos a deberes de confidencialidad.',
        's6t' => '6. Conservación de los datos',
        's6r' => 'Guardamos tus datos solo el tiempo necesario.',
        's6b' => 'Conservamos tus datos solo durante el tiempo necesario para cumplir las finalidades descritas y las obligaciones legales aplicables. Luego son eliminados o anonimizados.',
        's7t' => '7. Seguridad',
        's7r' => 'Medidas razonables para proteger tu información.',
        's7b' => 'Adoptamos medidas razonables para proteger tus datos frente a accesos no autorizados, pérdida o alteración. Ningún sistema es completamente infalible, pero trabajamos para resguardar tu información.',
        's8t' => '8. Tus derechos',
        's8r' => 'Acceder, rectificar, eliminar u oponerte al tratamiento.',
        's8b' => 'Puedes solicitar acceder a tus datos, rectificarlos, eliminarlos u oponerte a su tratamiento, así como revocar tu consentimiento, escribiéndonos a contacto@espacioluz.cl. Responderemos tu solicitud en los plazos que establece la ley.',
        's9t' => '9. Cookies',
        's9r' => 'Uso de cookies para el funcionamiento del sitio.',
        's9b' => 'Nuestro sitio puede utilizar cookies o tecnologías similares necesarias para su correcto funcionamiento. Puedes configurar tu navegador para limitarlas o eliminarlas.',
        's10t' => '10. Cambios a esta política',
        's10r' => 'Podemos actualizarla; verás la fecha vigente.',
        's10b' => 'Podemos actualizar esta Política para reflejar cambios en nuestras prácticas o en la normativa. Publicaremos la versión vigente en esta página, indicando su fecha de actualización.',
        's11t' => '11. Contacto',
        's11r' => 'Escríbenos ante cualquier duda sobre tus datos.',
        's11b' => 'Si tienes dudas sobre esta Política o sobre el tratamiento de tus datos, escríbenos a contacto@espacioluz.cl.',
    ],
    'terminos' => [
        'titulo'      => 'Términos y condiciones',
        'actualizado' => 'Última actualización: octubre de 2026',
        'intro'       => 'Estos Términos y Condiciones regulan el uso del sitio web de Espacio Luz y el servicio de agendamiento de horas en línea. Al utilizar el sitio o reservar una hora, aceptas estos términos.',
        's1t' => '1. Sobre Espacio Luz',
        's1r' => 'Quiénes somos y qué ofrece este sitio.',
        's1b' => 'Espacio Luz es un centro integral de terapias y bienestar ubicado en Avenida Oriente 196, Villa Cataluña, Los Ángeles. A través de este sitio ofrecemos información de nuestros servicios y la posibilidad de agendar horas.',
        's2t' => '2. Reservas y agenda',
        's2r' => 'Cómo funciona el agendamiento en línea.',
        's2b' => 'El agendamiento en línea permite solicitar una hora con nuestras profesionales. La reserva queda registrada y será confirmada por nuestro equipo. Nos reservamos el derecho de reprogramar una hora por motivos de fuerza mayor, avisándote con la mayor antelación posible.',
        's3t' => '3. Pagos',
        's3r' => 'Disponibilidad del pago y valores vigentes.',
        's3b' => 'El pago en línea puede no estar disponible en todo momento; en ese caso, coordinaremos contigo la forma de pago al confirmar tu reserva. Los valores vigentes son los informados en el sitio y pueden variar.',
        's4t' => '4. Cancelaciones y reprogramación',
        's4r' => 'Avísanos con anticipación para reprogramar.',
        's4b' => 'Si no puedes asistir a tu hora, te pedimos avisarnos con anticipación para reprogramarla. Las condiciones específicas de cancelación se coordinan directamente con el equipo.',
        's5t' => '5. Naturaleza de los servicios',
        's5r' => 'La web informa; no reemplaza una evaluación profesional.',
        's5b' => 'La información del sitio tiene fines informativos y no reemplaza una evaluación profesional. La atención se brinda de forma personalizada en el centro, por profesionales del área correspondiente.',
        's6t' => '6. Uso del sitio',
        's6r' => 'Usa el sitio de forma lícita y con datos veraces.',
        's6b' => 'Te comprometes a entregar información veraz al agendar y a usar el sitio de forma lícita. No está permitido intentar vulnerar su seguridad o su funcionamiento.',
        's7t' => '7. Propiedad intelectual',
        's7r' => 'Los contenidos del sitio son de Espacio Luz.',
        's7b' => 'Los contenidos, la marca, los textos y las imágenes del sitio pertenecen a Espacio Luz o se utilizan con autorización, y no pueden reproducirse sin consentimiento.',
        's8t' => '8. Responsabilidad',
        's8r' => 'Hacemos lo posible por mantener el sitio disponible.',
        's8b' => 'Hacemos nuestro mejor esfuerzo para mantener el sitio disponible y la información actualizada, pero no garantizamos que esté libre de errores o interrupciones.',
        's9t' => '9. Modificaciones',
        's9r' => 'Podemos actualizar estos términos.',
        's9b' => 'Podemos actualizar estos Términos en cualquier momento. La versión vigente estará publicada en esta página, indicando su fecha de actualización.',
        's10t' => '10. Legislación aplicable',
        's10r' => 'Se rigen por las leyes de Chile.',
        's10b' => 'Estos Términos se rigen por las leyes de la República de Chile. Cualquier controversia se someterá a los tribunales competentes de Los Ángeles, Región del Biobío.',
        's11t' => '11. Contacto',
        's11r' => 'Escríbenos ante cualquier duda.',
        's11b' => 'Para consultas sobre estos Términos, escríbenos a contacto@espacioluz.cl.',
    ],
];

/** Precio "Por hora" de un box (equivale a getPrecioPorHora de data.ts). */
function getPrecioPorHora(array $box): string
{
    foreach ($box['tarifas'] as $t) {
        if (($t['label'] ?? '') === 'Por hora') {
            return $t['precio'];
        }
    }
    return $box['tarifas'][0]['precio'] ?? '';
}

/** Devuelve un box por slug o null. */
function getBoxBySlug(array $boxes, string $slug): ?array
{
    foreach ($boxes as $b) {
        if ($b['slug'] === $slug) {
            return $b;
        }
    }
    return null;
}
