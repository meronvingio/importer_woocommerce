<?php

// =================== CONFIGURACIÓN INICIAL ===================
set_time_limit(0);
echo "DEBUG_OUT: Script iniciado.\n";
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('html_errors', 0);
ini_set('memory_limit', '2048M');
echo "DEBUG_OUT: Configuración PHP aplicada.\n";

// ========== CARGA DEL ENTORNO DE WORDPRESS ==========
$wp_load_path = __DIR__ . '/wp-load.php';
if (!file_exists($wp_load_path)) {
    die("[" . date('Y-m-d H:i:s') . "] - Error Crítico: No se encuentra 'wp-load.php' en la ruta esperada: {$wp_load_path}\n");
}
echo "DEBUG_OUT: Intentando cargar wp-load.php.\n";
require_once($wp_load_path);
log_progreso("DEBUG: wp-load.php cargado.", 'DEBUG');

if (file_exists(ABSPATH . 'wp-settings.php')) {
    echo "DEBUG_OUT: Intentando cargar wp-settings.php.\n";
    require_once ABSPATH . 'wp-settings.php';
    log_progreso("DEBUG: wp-settings.php cargado. Entorno WP inicializado.", 'DEBUG');
} else {
    die("[" . date('Y-m-d H:i:s') . "] - Error Crítico: No se pudo encontrar 'wp-settings.php' en " . ABSPATH . ". La instalación de WordPress puede estar dañada.\n");
}

// ========== CARGA Y FORZADO DE INICIALIZACIÓN DE WOOCOMMERCE EN CLI ==========
if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
}
$woocommerce_plugin_path = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
$wc_includes_path = WP_PLUGIN_DIR . '/woocommerce/includes/';

if (file_exists($woocommerce_plugin_path)) {
    echo "DEBUG_OUT: Intentando cargar woocommerce.php.\n";
    include_once($woocommerce_plugin_path);

    if (function_exists('WC')) {
        WC()->init();
        echo "DEBUG_OUT: WC()->init() ejecutado.\n";
        // Fuerza el registro de taxonomías del producto y atributos
        if (function_exists('wc_register_product_type')) wc_register_product_type();
        if (function_exists('wc_register_taxonomy')) wc_register_taxonomy();
        if (function_exists('wc_register_product_taxonomies')) wc_register_product_taxonomies();
        if (!taxonomy_exists('pa_isbn') && file_exists($wc_includes_path . 'class-wc-taxonomy.php')) {
            include_once $wc_includes_path . 'class-wc-taxonomy.php';
        }
    } else {
        die("[" . date('Y-m-d H:i:s') . "] - Error Crítico: No existe la función WC(). WooCommerce no está bien instalado.\n");
    }
    log_progreso("DEBUG: WooCommerce.php cargado. Plugin WC activo.", 'DEBUG');
} else {
    die("WooCommerce no encontrado en {$woocommerce_plugin_path}\n");
}

// Incluye helpers después de WC()->init()
if (file_exists($wc_includes_path . 'wc-attribute-functions.php')) {
    require_once $wc_includes_path . 'wc-attribute-functions.php';
    log_progreso("DEBUG: wc-attribute-functions.php cargado explícitamente.", 'DEBUG');
}
if (file_exists($wc_includes_path . 'wc-product-functions.php')) {
    require_once $wc_includes_path . 'wc-product-functions.php';
    log_progreso("DEBUG: wc-product-functions.php cargado explícitamente.", 'DEBUG');
}

// Fallback: Si la función sigue sin estar disponible, define una versión local
if (!function_exists('wc_get_attribute_id_from_name')) {
    function wc_get_attribute_id_from_name($name) {
        global $wpdb;
        $attribute = $wpdb->get_row(
            $wpdb->prepare("SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_label = %s", $name)
        );
        return $attribute ? (int)$attribute->attribute_id : 0;
    }
    error_log("Aviso: Se usará implementación local de wc_get_attribute_id_from_name().");
}

// ========== OPTIMIZACIÓN: Deshabilitar ganchos innecesarios durante la importación ==========
if (!defined('WP_IMPORTING')) {
    define('WP_IMPORTING', true);
}
if (!defined('WP_USE_THEMES')) {
    define('WP_USE_THEMES', false);
}

if (defined('WP_IMPORTING') && WP_IMPORTING) {
    // remove_action('save_post', 'woocommerce_clear_product_transients');
    // remove_action('save_post', 'woocommerce_product_data_store_cpt::clear_caches');
    // remove_action('woocommerce_new_product', 'woocommerce_email_actions');
    // remove_action('woocommerce_update_product', 'woocommerce_email_actions');
    // remove_action('add_attachment', 'wp_generate_attachment_metadata');
    // remove_action('update_attachment', 'wp_generate_attachment_metadata');
    log_progreso("Ganchos de WordPress/WooCommerce deshabilitados temporalmente para optimización de importación (si se configuraron).", 'INFO');
}


// =================================================================================
// 2. CONFIGURACIÓN DEL IMPORTADOR
// =================================================================================

$google_books_api_key = '*'; // ¡IMPORTANTE: Reemplaza con tu clave API de Google Books!
$deepl_api_key = '*';           // ¡IMPORTANTE: Reemplaza con tu clave API de DeepL!
$gemini_api_key       = '*'; // ¡IMPORTANTE: Reemplaza con tu clave API de Gemini!

$directorio_raiz_libros = WP_CONTENT_DIR . '/uploads/libros/';  // Directorio base donde están los EPUBs
$carpeta_especial_otros = '_otros';                             // Nombre de la carpeta para libros sin autor claro
$productos_por_lote = 1; // Reducido a 1 para una prueba más rápida y enfocada en los errores.
$precio_por_defecto = '9.99';                                   // Precio por defecto para los productos

// Archivos para el control de estado del cron job. Ubicados en el mismo directorio del script.
$last_processed_file_path = __DIR__ . '/last_processed_epub.txt';
$import_finished_flag = __DIR__ . '/import_finished.flag';

// Nombres de los atributos de producto y sus slugs esperados.
// Asegúrate de que estos atributos (ISBN, Número de páginas, etc.) existan en WooCommerce (Productos > Atributos).
$product_attributes_config = [
    'ISBN' => 'isbn',
    'Número de páginas' => 'numero-de-paginas',
    'Fecha de publicación' => 'fecha-de-publicacion',
    'Editor' => 'editor',
    'Idioma' => 'idioma',
    'Formato' => 'formato',
    'Autor del libro' => 'autor' // Slug original antes de la propuesta de cambio a 'autor'
];
$product_attribute_ids = [];

// Define la categoría por defecto para los libros (por nombre).
// Asegúrate de que esta categoría exista en tu WordPress (Productos > Categorías).
$default_category_name = 'eBooks';
$default_category_id = null; // Se establecerá dinámicamente.

// === NUEVO: Modo de ejecución en seco (Dry Run) ===
global $argv;
$dry_run = in_array('--dry-run', $argv);
if ($dry_run) {
    log_progreso("Modo de ejecución en seco (Dry Run) activado. No se realizarán cambios en la base de datos ni en los archivos de estado.", 'INFO');
}

// =================================================================================
// 3. FUNCIONES AUXILIARES
// =================================================================================

/**
 * Registra mensajes de progreso en un archivo de log y los imprime en la consola.
 *
 * @param string $mensaje El mensaje a loguear.
 * @param string $nivel   El nivel del mensaje (INFO, WARN, ERROR, DEBUG, FATAL).
 */
function log_progreso($mensaje, $nivel = 'INFO') {
    global $dry_run; // Acceder a la variable global dry_run
    $timestamp = date('[Y-m-d H:i:s]');
    $prefix = $dry_run ? ' [DRY RUN]' : ''; // Añadir prefijo si es dry run
    $linea_log = "$timestamp [$nivel]{$prefix} - $mensaje" . PHP_EOL;
    file_put_contents(__DIR__ . '/import_progress.log', $linea_log, FILE_APPEND);
    echo $linea_log;
    // Asegura que el output se muestre en CLI inmediatamente
    if (php_sapi_name() === 'cli') fflush(STDOUT);
}

/**
 * Normaliza una cadena para hacerla compatible con slugs de URL o nombres de archivo.
 * Convierte a minúsculas, elimina caracteres especiales y reemplaza espacios con guiones.
 *
 * @param string $string La cadena a normalizar.
 * @return string La cadena normalizada.
 */
function normalize_string($string) {
    // === CORRECCIÓN VITAL: Usar la función nativa de WordPress `remove_accents()` ===
    // Esta función está disponible después de que wp-settings.php se carga.
    $string = remove_accents($string);
    // === FIN CORRECCIÓN ===
    $string = strtolower($string);
    $string = preg_replace('/[^a-z0-9\s-]/', '', $string);
    $string = preg_replace('/[\s-]+/', '-', $string);
    $string = trim($string, '-');
    return $string;
}

/**
 * Normaliza nombres de autor para mejorar la coincidencia.
 * Ejemplo: "A. A. Attanasio" -> "A A Attanasio"
 * "Attanasio, A. A." -> "A A Attanasio"
 */
function normalize_author_name($name) {
    // Eliminar puntos y comas
    $name = str_replace(['.', ','], '', $name);
    // Reemplazar múltiples espacios con uno solo y trim
    $name = preg_replace('/\s+/', ' ', trim($name));
    return $name;
}

/**
 * Calcula la similitud entre dos cadenas usando levenshtein.
 * Retorna true si la similitud es mayor o igual a un umbral.
 */
function validar_coincidencia_api($api_author, $local_author, $threshold = 70) {
    $api_author_norm = normalize_string(normalize_author_name($api_author));
    $local_author_norm = normalize_string(normalize_author_name($local_author));

    if (empty($api_author_norm) || empty($local_author_norm)) {
        return false; // No se puede comparar si una de las cadenas está vacía.
    }

    // Calcular la similitud con similar_text (en porcentaje)
    similar_text($api_author_norm, $local_author_norm, $percent);
    log_progreso("Similitud entre autor API ('$api_author_norm') y autor local ('$local_author_norm'): " . round($percent, 2) . "%", 'DEBUG');

    return $percent >= $threshold;
}


/**
 * Traduce un texto usando la API de DeepL.
 *
 * @param string $texto           Texto a traducir.
 * @param string $idioma_destino Código del idioma de destino (ej. 'ES').
 * @return string Texto traducido o el texto original en caso de error/vacío.
 */
function traducir_texto_con_deepl($texto, $idioma_destino = 'ES') {
    global $deepl_api_key;
    $texto_limpio = wp_strip_all_tags($texto); // Limpiar etiquetas HTML
    if (empty($deepl_api_key) || empty(trim($texto_limpio))) return $texto;

    $api_url = 'https://api-free.deepl.com/v2/translate';
    $body = [
        'text'        => [$texto_limpio],
        'target_lang' => $idioma_destino
    ];
    $args = [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'DeepL-Auth-Key ' . $deepl_api_key
        ],
        'body'        => json_encode($body),
        'timeout'     => 45 // Tiempo de espera para la respuesta de la API
    ];

    $respuesta = wp_remote_post($api_url, $args);

    if (is_wp_error($respuesta)) {
        log_progreso("ERROR DeepL: " . $respuesta->get_error_message(), 'ERROR');
        return $texto;
    }

    $cuerpo_respuesta = wp_remote_retrieve_body($respuesta);
    $datos_respuesta = json_decode($cuerpo_respuesta, true);

    if (isset($datos_respuesta['translations'][0]['text'])) {
        return $datos_respuesta['translations'][0]['text'];
    }

    log_progreso("ADVERTENCIA DeepL: No se pudo traducir el texto. Respuesta: " . $cuerpo_respuesta, 'WARN');
    return $texto; // Retornar original si la traducción falla
}

/**
 * Reescribe una descripción utilizando la API de Google Gemini o la genera desde cero si es necesario,
 * incorporando metadatos adicionales como contexto.
 *
 * @param string $gemini_api_key La clave API de Gemini.
 * @param string $title El título del libro.
 * @param string $author El autor del libro.
 * @param string $original_description La descripción existente (puede ser vacía).
 * @param array $factual_metadata Metadatos fácticos como ISBN, páginas, etc.
 * @return string La descripción reescrita/generada o la descripción original si falla.
 */
function reescribir_descripcion_con_gemini($gemini_api_key, $title, $author, $original_description, $factual_metadata) {
    log_progreso("Intentando generar/reescribir descripción con Gemini API para '$title'.", 'DEBUG');

    if (empty($gemini_api_key) || $gemini_api_key === 'TU_API_KEY_DE_GEMINI') {
        log_progreso("Error: Clave API de Gemini no configurada. No se puede generar descripción.", 'ERROR');
        return $original_description; // Retorna la descripción original si no hay clave
    }

    // === CORRECCIÓN: Actualizar el modelo Gemini a 1.5-flash ===
    $api_url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$gemini_api_key}";
    // === FIN CORRECCIÓN ===

    $prompt = "";
    if (!empty($original_description) && strlen($original_description) > 50) { // Si hay una descripción original decente
        log_progreso("Gemini: Reescribiendo descripción existente.", 'INFO');
        $prompt = "Reescribe la siguiente descripción de un libro de forma creativa, única y atractiva para una tienda online, optimizada para SEO. Mantén los hechos clave pero usa un lenguaje narrativo e inmersivo. El libro se titula '{$title}' y el autor es '{$author}'. Descripción original: \n\n\"{$original_description}\"";
    } else {
        log_progreso("Gemini: Generando descripción desde cero.", 'INFO');
        // Construir un contexto con metadatos fácticos disponibles
        $contextual_info = "";
        if (!empty($factual_metadata['page_count'])) {
            $contextual_info .= "Tiene {$factual_metadata['page_count']} páginas. ";
        }
        if (!empty($factual_metadata['published_date'])) {
            $contextual_info .= "Fue publicado en {$factual_metadata['published_date']}. ";
        }
        if (!empty($factual_metadata['publisher'])) {
            $contextual_info .= "Publicado por {$factual_metadata['publisher']}. ";
        }
        if (!empty($factual_metadata['language'])) {
            $contextual_info .= "Idioma: {$factual_metadata['language']}. ";
        }
        if (!empty($factual_metadata['format'])) {
            $contextual_info .= "Formato: {$factual_metadata['format']}. ";
        }
        if (!empty($factual_metadata['isbn'])) {
            $contextual_info .= "ISBN: {$factual_metadata['isbn']}. ";
        }

        $prompt = "Genera una descripción única, creativa y atractiva para una tienda online de libros, optimizada para SEO, para un libro titulado '{$title}' del autor '{$author}'. Enfócate en captar la atención del lector y en describir la esencia de la obra. {$contextual_info} No inventes datos fácticos precisos que no estén en el contexto dado (como personajes, tramas detalladas, etc.), ya que no puedes garantizar su exactitud. Esos datos se obtendrán solo de las APIs si están presentes. La descripción debe ser de al menos 150 palabras.";
    }

    $request_body = [
        'contents' => [
            'parts' => [['text' => $prompt]]
        ],
        'safetySettings' => [
            ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_NONE'],
            ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE'],
            ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
            ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE']
        ]
    ];

    $response = wp_remote_post($api_url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'        => json_encode($request_body),
        'timeout'     => 60, // Aumentar el tiempo de espera para Gemini
        'sslverify'   => false // Puedes querer habilitar esto en producción
    ]);

    if (is_wp_error($response)) {
        log_progreso("Error al conectar con Gemini API para '$title': " . $response->get_error_message(), 'ERROR');
        return $original_description;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (isset($data['error'])) {
        log_progreso("ERROR Gemini API Response para '$title': " . $data['error']['message'], 'ERROR');
        return $original_description;
    }

    if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
    $generated_text = $data['candidates'][0]['content']['parts'][0]['text'];
    log_progreso("DEBUG: Descripción GENERADA por Gemini (primeras 100 chars): " . substr($generated_text, 0, 100) . "...", 'DEBUG'); // <-- AÑADIR ESTA LÍNEA
    // Limpiar el texto de posibles caracteres indeseados al inicio/final
    $generated_text = trim($generated_text);
    return $generated_text;
} else {
    log_progreso("ERROR: No se pudo obtener la descripción de Gemini API para '$title'. Respuesta: " . $body, 'ERROR');
    return $original_description;
}
}

/**
 * Consulta la API de Google Books para obtener metadatos de un libro.
 */
function ejecutar_consulta_google_api($title, $author, $api_key) {
    $query = urlencode("$title inauthor:\"$author\"");
    $api_url = "https://www.googleapis.com/books/v1/volumes?q=$query&key=$api_key&maxResults=1";
    log_progreso("Consultando Google Books: $api_url", 'DEBUG');

    $response = wp_remote_get($api_url, ['timeout' => 15]);

    if (is_wp_error($response)) {
        log_progreso("Error al consultar Google Books: " . $response->get_error_message(), 'ERROR');
        return null;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (empty($data['items'])) {
        return null;
    }

    $item = $data['items'][0]['volumeInfo'];
    $metadata = [
        'title' => isset($item['title']) ? $item['title'] : '',
        'author' => isset($item['authors'][0]) ? $item['authors'][0] : '',
        'description' => isset($item['description']) ? $item['description'] : '',
        'isbn' => [],
        'page_count' => isset($item['pageCount']) ? (int)$item['pageCount'] : null,
        'published_date' => isset($item['publishedDate']) ? $item['publishedDate'] : '',
        'publisher' => isset($item['publisher']) ? $item['publisher'] : '',
        'language' => isset($item['language']) ? $item['language'] : '',
        'categories' => isset($item['categories']) ? $item['categories'] : [],
        'image_url' => isset($item['imageLinks']['thumbnail']) ? $item['imageLinks']['thumbnail'] : ''
    ];

    if (isset($item['industryIdentifiers'])) {
        foreach ($item['industryIdentifiers'] as $identifier) {
            $metadata['isbn'][] = $identifier['identifier'];
        }
    }

    return $metadata;
}

/**
 * Consulta la API de Open Library para obtener metadatos de un libro.
 */
function buscar_en_open_library($title, $author) {
    $query = http_build_query(['q' => $title . ' ' . $author]); // Open Library es más flexible con la consulta combinada
    $api_url = "https://openlibrary.org/search.json?" . $query . "&limit=5";
    log_progreso("Consultando Open Library: $api_url", 'DEBUG');

    $response = wp_remote_get($api_url, ['timeout' => 15]);

    if (is_wp_error($response)) {
        log_progreso("Error al consultar Open Library: " . $response->get_error_message(), 'ERROR');
        return null;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (empty($data['docs'])) {
        return null;
    }

    // Intentar encontrar la mejor coincidencia. Open Library devuelve varios resultados.
    // Buscamos el primero que tenga descripción o una buena coincidencia de título/autor.
    $best_match = null;
    foreach ($data['docs'] as $doc) {
        if (isset($doc['description']) && !empty($doc['description'])) {
            $best_match = $doc;
            break; // Encontramos uno con descripción, lo usamos.
        }
        // Si no hay descripción, al menos que el título sea una buena coincidencia
        if (isset($doc['title']) && validar_coincidencia_api($doc['title'], $title, 85)) {
            $best_match = $doc; // Usamos el primero que coincida bien en título
            break;
        }
    }

    if (!$best_match) {
        // Si no encontramos un buen match con descripción, usamos el primer resultado si existe
        if (!empty($data['docs'])) {
            $best_match = $data['docs'][0];
        } else {
            return null;
        }
    }

    $metadata = [
        'title' => isset($best_match['title']) ? $best_match['title'] : '',
        'author' => isset($best_match['author_name']) ? (is_array($best_match['author_name']) ? implode(', ', $best_match['author_name']) : $best_match['author_name']) : '',
        'description' => '',
        'isbn' => [],
        'page_count' => isset($best_match['number_of_pages_median']) ? (int)$best_match['number_of_pages_median'] : null,
        'published_date' => isset($best_match['first_publish_year']) ? (string)$best_match['first_publish_year'] : '',
        'publisher' => isset($best_match['publisher']) ? (is_array($best_match['publisher']) ? implode(', ', $best_match['publisher']) : $best_match['publisher']) : '',
        'language' => isset($best_match['language']) ? (is_array($best_match['language']) ? implode(', ', $best_match['language']) : $best_match['language']) : '',
        'format' => isset($best_match['type']) ? $best_match['type'] : '',
        'image_url' => '' // Open Library usa IDs para las portadas, no URLs directas en la búsqueda inicial.
    ];

    // Manejar la descripción que puede ser un objeto
    if (isset($best_match['description'])) {
        if (is_array($best_match['description']) && isset($best_match['description']['value'])) {
            $metadata['description'] = $best_match['description']['value'];
        } elseif (is_string($best_match['description'])) {
            $metadata['description'] = $best_match['description'];
        }
    }

    // Manejar ISBNs
    if (isset($best_match['isbn'])) {
        $metadata['isbn'] = array_unique(array_merge($metadata['isbn'], (array)$best_match['isbn']));
    }

    return $metadata;
}

/**
 * Extrae datos iniciales (título y autor) de la ruta del archivo EPUB.
 *
 * @param string $ruta_archivo_epub La ruta completa al archivo EPUB.
 * @return array|null Un array asociativo con 'titulo_inicial' y 'autor_inicial', o null si no se pueden extraer datos.
 */
function extraer_datos_iniciales_desde_ruta($ruta_archivo_epub) {
    global $carpeta_especial_otros;
    $nombre_archivo = basename($ruta_archivo_epub);
    $directorio_padre = basename(dirname($ruta_archivo_epub));
    $titulo_inicial = '';
    $autor_inicial = '';

    if ($directorio_padre === $carpeta_especial_otros) {
        $nombre_sin_extension = pathinfo($nombre_archivo, PATHINFO_FILENAME);
        // Espera un formato como "Autor_Titulo" o "Titulo_Autor" en _otros
        $partes = explode('-', $nombre_sin_extension, 2); // Divide solo en dos partes
        if (count($partes) === 2 && !empty(trim($partes[1]))) { // Si hay dos partes y la segunda no está vacía
            $titulo_inicial = trim(str_replace('_', ' ', $partes[1])); // Asume la segunda parte es el título
            $autor_inicial = ucwords(trim(str_replace('_', ' ', $partes[0]))); // Asume la primera parte es el autor
        } else {
            $titulo_inicial = trim(str_replace('_', ' ', $nombre_sin_extension));
            $autor_inicial = ''; // No se pudo determinar autor claro
        }
    } else {
        $autor_inicial = ucwords(str_replace('_', ' ', $directorio_padre));
        $titulo_inicial = trim(str_replace('_', ' ', pathinfo($nombre_archivo, PATHINFO_FILENAME)));
    }

    if (empty($titulo_inicial)) return null;
    return ['titulo_inicial' => $titulo_inicial, 'autor_inicial' => $autor_inicial];
}

/**
 * Consolida metadatos de múltiples fuentes, priorizando y complementando.
 */
function obtener_metadatos_consolidados($title, $author) {
    global $google_books_api_key; // Asegura que la clave API esté disponible aquí
    $google_metadata = ejecutar_consulta_google_api($title, $author, $google_books_api_key);
    $open_library_metadata = buscar_en_open_library($title, $author);

    $consolidated_metadata = [
        'title' => $title, // Por defecto usamos el del archivo
        'author' => $author, // Por defecto usamos el del archivo
        'description' => '',
        'isbn' => [],
        'page_count' => null,
        'published_date' => '',
        'publisher' => '',
        'language' => '',
        'format' => '',
        'image_url' => '' // Portada de fallback
    ];

    $google_author_match = false;
    $open_library_author_match = false;

    // 1. Procesar Google Books
    if ($google_metadata) {
        log_progreso("Metadatos base obtenidos de Google Books.", 'INFO');
        $google_author_match = validar_coincidencia_api($google_metadata['author'] ?? '', $author);

        if ($google_author_match) {
            $consolidated_metadata['title'] = !empty($google_metadata['title']) ? $google_metadata['title'] : $consolidated_metadata['title'];
            $consolidated_metadata['author'] = !empty($google_metadata['author']) ? $google_metadata['author'] : $consolidated_metadata['author'];
            $consolidated_metadata['description'] = !empty($google_metadata['description']) ? $google_metadata['description'] : $consolidated_metadata['description'];
        } else {
            log_progreso("Autor de Google Books ('{$google_metadata['author']}') no coincide suficientemente con el autor local ('$author'). No se usará título, autor o descripción de Google Books.", 'INFO');
        }

        // Siempre consolidar ISBNs, páginas, editor, fecha, idioma, incluso si el autor no coincide.
        if (!empty($google_metadata['isbn'])) {
            // Si el ISBN de Google Books es una cadena, conviértelo a array antes de merge.
            $isbn_from_google = is_string($google_metadata['isbn']) ? explode(', ', $google_metadata['isbn']) : $google_metadata['isbn'];
            $consolidated_metadata['isbn'] = array_unique(array_merge($consolidated_metadata['isbn'], (array)$isbn_from_google));
        }
        $consolidated_metadata['page_count'] = $consolidated_metadata['page_count'] ?? $google_metadata['page_count'];
        $consolidated_metadata['published_date'] = $consolidated_metadata['published_date'] ?? $google_metadata['published_date'];
        $consolidated_metadata['publisher'] = $consolidated_metadata['publisher'] ?? $google_metadata['publisher'];
        $consolidated_metadata['language'] = $consolidated_metadata['language'] ?? $google_metadata['language'];
        $consolidated_metadata['format'] = $consolidated_metadata['format'] ?? ($google_metadata['print_type'] ?? ''); // Google usa 'print_type'
        $consolidated_metadata['image_url'] = $consolidated_metadata['image_url'] ?? $google_metadata['image_url'];
    }

    // 2. Complementar/Priorizar con Open Library
    if ($open_library_metadata) {
        log_progreso("Metadatos base obtenidos de Open Library.", 'INFO');
        $open_library_author_match = validar_coincidencia_api($open_library_metadata['author'] ?? '', $author);

        if ($open_library_author_match) {
            // Priorizar descripción más larga de Open Library si Google no dio una o si es mejor
            if (!empty($open_library_metadata['description']) &&
                (empty($consolidated_metadata['description']) || strlen($open_library_metadata['description']) > strlen($consolidated_metadata['description']) * 0.8)) {
                $consolidated_metadata['description'] = $open_library_metadata['description'];
                log_progreso("Priorizando descripción de Open Library (más larga/disponible).", 'DEBUG');
            }

            // Si Google Books no dio un título o el de OL es mejor
            if (empty($consolidated_metadata['title']) || strlen($open_library_metadata['title']) > strlen($consolidated_metadata['title']) * 0.8) {
                $consolidated_metadata['title'] = !empty($open_library_metadata['title']) ? $open_library_metadata['title'] : $consolidated_metadata['title'];
            }
            $consolidated_metadata['author'] = !empty($open_library_metadata['author']) ? $open_library_metadata['author'] : $consolidated_metadata['author'];
        } else {
            log_progreso("Autor de Open Library ('{$open_library_metadata['author']}') no coincide suficientemente con el autor local ('$author'). No se usará título, autor o descripción de Open Library.", 'INFO');
        }


        // Siempre consolidar ISBNs, páginas, editor, fecha, idioma, formato incluso si el autor no coincide.
        if (!empty($open_library_metadata['isbn'])) {
            // Si el ISBN de Open Library es una cadena, conviértelo a array antes de merge.
            $isbn_from_ol = is_string($open_library_metadata['isbn']) ? explode(', ', $open_library_metadata['isbn']) : $open_library_metadata['isbn'];
            $consolidated_metadata['isbn'] = array_unique(array_merge($consolidated_metadata['isbn'], (array)$isbn_from_ol));
        }
        $consolidated_metadata['page_count'] = $consolidated_metadata['page_count'] ?? $open_library_metadata['page_count'];
        $consolidated_metadata['published_date'] = $consolidated_metadata['published_date'] ?? $open_library_metadata['published_date'];
        $consolidated_metadata['publisher'] = $consolidated_metadata['publisher'] ?? $open_library_metadata['publisher'];
        $consolidated_metadata['language'] = $consolidated_metadata['language'] ?? $open_library_metadata['language'];
        $consolidated_metadata['format'] = $consolidated_metadata['format'] ?? $open_library_metadata['format'];
        $consolidated_metadata['image_url'] = $consolidated_metadata['image_url'] ?? $open_library_metadata['image_url']; // Usar OL image si Google no dio una
    }

    // Convertir array de ISBNs a una cadena separada por comas
    $consolidated_metadata['isbn'] = implode(', ', array_unique($consolidated_metadata['isbn']));

    log_progreso("Metadatos consolidados listos para usar.", 'INFO');
    return $consolidated_metadata;
}
/**
 * Obtiene el ID del término para un valor de atributo dado, creándolo si no existe.
 *
 * @param string $attribute_display_name El nombre de visualización del atributo global (ej. 'ISBN').
 * @param string $term_value El valor del término (ej. '8445501402').
 * @return int El ID del término, o 0 en caso de fallo.
 */
function get_or_create_attribute_term_id( $attribute_display_name, $term_value ) {
    // Obtener el slug de la taxonomía para el atributo global (ej. 'pa_isbn')
    // wc_attribute_taxonomy_name() convierte 'Nombre de atributo' a 'pa_nombre-de-atributo'
    $taxonomy = wc_attribute_taxonomy_name( $attribute_display_name );

    // Verificar si el término ya existe
    $term = get_term_by( 'name', (string)$term_value, $taxonomy ); // Asegurar que $term_value sea string

    // Si el término no existe o hubo un error al recuperarlo, intentamos crearlo.
    if ( ! $term || is_wp_error( $term ) ) {
        // La función wp_insert_term() devuelve un array con 'term_id' y 'term_taxonomy_id' en éxito,
        // o un WP_Error en caso de fallo.
        $inserted_term = wp_insert_term( (string)$term_value, $taxonomy ); // Asegurar que el valor sea string

        if ( ! is_wp_error( $inserted_term ) ) {
            // Término creado con éxito
            return $inserted_term['term_id'];
        } else {
            // Registrar error si la creación del término falló
            error_log( "Error al crear el término '{$term_value}' para la taxonomía '{$taxonomy}': " . $inserted_term->get_error_message() );
            return 0; // Devolver 0 para indicar fallo
        }
    }
    // El término ya existe, devolver su ID
    return $term->term_id;
}
/**
 * Asigna atributos de producto de WooCommerce.
 * @param WC_Product $product El objeto producto de WooCommerce.
 * @param array $attributes_data Un array asociativo de nombre_atributo_visible => valor.
 * @param array $attribute_ids_map Un array asociativo de nombre_atributo_visible => id_atributo_global.
 */
function assign_product_attributes_to_product_object($product, $attributes_data, $attribute_ids_map) {
    $product_attributes = []; // Empezar con un array vacío para reconstruir

    // Verificar si la clase WC_Product_Attribute existe en el entorno.
    $wc_product_attribute_class_exists = class_exists('WC_Product_Attribute');
    log_progreso("DEBUG: class_exists('WC_Product_Attribute') = " . ($wc_product_attribute_class_exists ? 'true' : 'false'), 'DEBUG');

    // Almacena los IDs de los términos globales que deben ser asignados al producto
    $terms_to_assign = []; 

    foreach ($attributes_data as $attr_name_visible => $attr_value) {
        // Asegúrate de que el valor no esté vacío o nulo antes de intentar asignarlo
        if (empty($attr_value) && $attr_value !== 0 && $attr_value !== '0') {
            log_progreso("Atributo '$attr_name_visible' tiene valor vacío o nulo. No se asignará.", 'DEBUG');
            continue;
        }

        $attribute_slug = sanitize_title($attr_name_visible);
        $is_global_attribute_processed = false;

        // Lógica para atributos GLOBALES
        // Inicializar $attribute_id_global aquí para evitar la advertencia "Undefined variable"
        $attribute_id_global = 0; 
        if ($wc_product_attribute_class_exists && isset($attribute_ids_map[$attr_name_visible])) {
            $attribute_id_global = $attribute_ids_map[$attr_name_visible];
            
            // Usamos wc_attribute_taxonomy_name() para obtener el nombre de la taxonomía (ej. 'pa_isbn')
            $taxonomy_name = wc_attribute_taxonomy_name( $attr_name_visible );

            if (taxonomy_exists($taxonomy_name)) {
                $current_attribute_term_ids = [];
                // Manejar valores múltiples (ej. ISBNs separados por coma)
                $values = array_map('trim', explode(',', (string)$attr_value));

                foreach ($values as $single_value) {
                    if (empty($single_value)) continue;

                    // Usar la función get_or_create_attribute_term_id()
                    $term_id = get_or_create_attribute_term_id( $attr_name_visible, $single_value );

                    if ( $term_id ) {
                        $current_attribute_term_ids[] = $term_id;
                        log_progreso("Término de atributo global '{$single_value}' encontrado/creado para '{$attr_name_visible}' (ID: {$term_id}).", 'DEBUG');
                    } else {
                        log_progreso("ERROR: No se pudo obtener/crear término para el valor '{$single_value}' del atributo '{$attr_name_visible}'.", 'ERROR');
                    }
                }

                if (!empty($current_attribute_term_ids)) {
                    $attribute_object = new WC_Product_Attribute();
                    $attribute_object->set_id($attribute_global_id); // CRUCIAL: ID del atributo global
                    $attribute_object->set_name($attr_name_visible); // Nombre de visualización

                    // === CAMBIO CRÍTICO AQUÍ: Usar NOMBRES de términos para set_options() ===
                    $term_names_for_options = [];
                    foreach ($current_attribute_term_ids as $term_id) {
                        $term_obj = get_term_by('id', $term_id, $taxonomy_name);
                        if ($term_obj && !is_wp_error($term_obj)) {
                            $term_names_for_options[] = $term_obj->name; // ¡USAR EL NOMBRE DEL TÉRMINO!
                        }
                    }
                    $attribute_object->set_options($term_names_for_options); // MUY IMPORTANTE: Array de NOMBRES de términos para el backend
                    // === FIN CAMBIO CRÍTICO ===

                    $attribute_object->set_position(count($product_attributes));
                    $attribute_object->set_visible(true);
                    $attribute_object->set_variation(false);

                    // La clave del array para atributos globales debe ser el nombre de la taxonomía
                    $product_attributes[ $taxonomy_name ] = $attribute_object;

                    // Añadir los IDs del término a la lista para wp_set_object_terms
                    // Nota: wp_set_object_terms necesita los IDs, por eso mantenemos $current_attribute_term_ids para esto
                    $terms_to_assign[$taxonomy_name] = array_merge($terms_to_assign[$taxonomy_name] ?? [], array_map('intval', $current_attribute_term_ids));

                    log_progreso("Atributo GLOBAL '{$attr_name_visible}' asignado con términos NOMBRES: " . implode(', ', $term_names_for_options) . " (IDs: " . implode(', ', $current_attribute_term_ids) . ") y valor original: '{$attr_value}'.", 'INFO');
                    $is_global_attribute_processed = true;

                } else {
                    log_progreso("ADVERTENCIA: No se pudo asignar ningún término válido para el atributo global '{$attr_name_visible}'. Se asignará como personalizado si es necesario.", 'WARN');
                    $is_global_attribute_processed = false; // Forzar a que se trate como personalizado
                }

            } else {
                log_progreso("ADVERTENCIA: La taxonomía '{$taxonomy_name}' para el atributo global '{$attr_name_visible}' no existe. Se asignará como atributo personalizado.", 'WARN');
            }
        }

        // Lógica para atributos PERSONALIZADOS (o si falló el procesamiento como global)
        if (!$is_global_attribute_processed) {
            $attribute_object = new WC_Product_Attribute();
            $attribute_object->set_name($attr_name_visible);
            $attribute_object->set_options([(string)$attr_value]); // Los atributos personalizados usan el valor directamente
            $attribute_object->set_position(count($product_attributes));
            $attribute_object->set_visible(true);
            $attribute_object->set_variation(false);
            $attribute_object->set_id(0); // Para atributos personalizados, ID es 0
            $product_attributes[$attribute_slug] = $attribute_object; // Usar slug como clave para personalizados
            log_progreso("Atributo PERSONALIZADO '{$attr_name_visible}' asignado con valor '{$attr_value}'.", 'INFO');
        }
    } // Fin del foreach ($attributes_data)

    // Establecer todos los atributos en el objeto del producto
    $product->set_attributes($product_attributes);

    // Finalizar asignación de términos para taxonomías globales
    // Esto es crucial para vincular los términos creados a la entrada del producto
    foreach ($terms_to_assign as $taxonomy => $term_ids) {
        wp_set_object_terms($product->get_id(), array_map('intval', array_unique($term_ids)), $taxonomy, false); // false = no append, replace
        log_progreso("DEBUG: wp_set_object_terms ejecutado para producto {$product->get_id()} en taxonomía '{$taxonomy}' con IDs: " . implode(', ', array_unique($term_ids)), 'DEBUG');
    }
}
function asignar_imagen_desde_local($ruta_imagen_local, $id_producto, $titulo_libro, $dry_run = false) {
    if ($dry_run) { // [2]
        log_progreso("DRY RUN: Se simula la asignación de imagen local para '{$titulo_libro}'.", 'INFO');
        return true; // Simular éxito en dry run
    }

    if (!file_exists($ruta_imagen_local)) {
        log_progreso("ERROR: No se encontró la imagen local en: {$ruta_imagen_local}", 'ERROR');
        return false;
    }

    log_progreso("Intentando asignar imagen local para '{$titulo_libro}' desde: {$ruta_imagen_local}", 'DEBUG');

    if (!function_exists('media_handle_upload')) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
    }

    $file_name = sanitize_file_name(pathinfo($ruta_imagen_local, PATHINFO_BASENAME));

    // Usamos wp_upload_bits para mover/copiar el archivo a la carpeta de uploads de WP
    // y file_get_contents es necesario para que wp_upload_bits lea el contenido.
    $upload_file = wp_upload_bits($file_name, null, file_get_contents($ruta_imagen_local));

    if (!$upload_file['error']) {
        $wp_filetype = wp_check_filetype($file_name, null);
        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title'     => sanitize_file_name($titulo_libro),
            'post_content'   => '',
            'post_status'    => 'inherit'
        );

        $attach_id = wp_insert_attachment($attachment, $upload_file['file'], $id_producto);
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $upload_file['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);

        // Establecer como imagen destacada
        if (set_post_thumbnail($id_producto, $attach_id)) {
            log_progreso("Imagen destacada asignada desde archivo local: {$upload_file['url']}", 'INFO');
            // Opcional: Eliminar el archivo original SFTP después de que WordPress lo haya copiado.
            // @unlink($ruta_imagen_local);
            return true;
        } else {
            log_progreso("ERROR: No se pudo establecer la imagen destacada para el producto ID {$id_producto}.", 'ERROR');
            return false;
        }
    } else {
        log_progreso("ERROR al subir imagen local a la biblioteca de medios: " . $upload_file['error'], 'ERROR');
        return false;
    }
}


function asignar_imagen_desde_url($url_imagen, $id_producto, $titulo_libro, $dry_run = false) {
    if ($dry_run) { // [2]
        log_progreso("DRY RUN: Se simula la asignación de imagen desde URL para '{$titulo_libro}'.", 'INFO');
        return true; // Simular éxito en dry run
    }

    if (empty($url_imagen)) return false;
    log_progreso("Intentando descargar imagen para '{$titulo_libro}' desde: {$url_imagen}", 'DEBUG');
    if (!function_exists('media_handle_sideload')) {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
    }
    $archivo_temporal = download_url($url_imagen, 30);
    if (is_wp_error($archivo_temporal)) {
        log_progreso("ERROR: Falló la descarga de imagen desde URL {$url_imagen}: " . $archivo_temporal->get_error_message(), 'ERROR');
        return false;
    }
    $info_archivo = ['name' => sanitize_file_name($titulo_libro) . '.jpg', 'tmp_name' => $archivo_temporal];
    $id_adjunto = media_handle_sideload($info_archivo, $id_producto, "Portada de " . $titulo_libro);
    @unlink($archivo_temporal);
    if (is_wp_error($id_adjunto)) {
        log_progreso("ERROR: media_handle_sideload falló para {$url_imagen}: " . $id_adjunto->get_error_message(), 'ERROR');
        return false;
    }
    if (set_post_thumbnail($id_producto, $id_adjunto)) {
        log_progreso("Imagen destacada asignada desde URL externa: {$url_imagen}", 'INFO');
        return true;
    } else {
        log_progreso("ERROR: No se pudo establecer la imagen destacada desde URL para el producto ID {$id_producto}.", 'ERROR');
        return false;
    }
}

// =================================================================================
// 4. LÓGICA PRINCIPAL DEL SCRIPT
// =================================================================================

// Borramos el log en cada inicio, esto es útil para el desarrollo/depuración.
// Para un cron job a largo plazo, podrías querer un log continuo o rotarlo.
// El archivo de log siempre se escribe, incluso en dry run.
if (file_exists(__DIR__ . '/import_progress.log')) {
    unlink(__DIR__ . '/import_progress.log');
}

log_progreso("================ INICIO DEL SCRIPT DE IMPORTACIÓN (v4.3.0_Estable_Corregido_Final) ================", 'INFO');

// Obtener o crear la categoría por defecto
$term = get_term_by('name', $default_category_name, 'product_cat');
if ($term) {
    $default_category_id = $term->term_id;
    log_progreso("ID de categoría '$default_category_name' obtenido: $default_category_id", 'INFO');
} else {
    if (!$dry_run) { // Solo crear categoría si no es dry run
        $new_term = wp_insert_term($default_category_name, 'product_cat', ['slug' => normalize_string($default_category_name)]);
        if (!is_wp_error($new_term)) {
            $default_category_id = $new_term['term_id'];
            log_progreso("Categoría '$default_category_name' creada con ID: $default_category_id", 'INFO');
        } else {
            log_progreso("Error al crear la categoría '$default_category_name': " . $new_term->get_error_message(), 'ERROR');
            die("No se pudo continuar sin una categoría de producto válida.");
        }
    } else {
        log_progreso("DRY RUN: Se simula la creación de la categoría '$default_category_name'.", 'INFO');
        $default_category_id = 99999; // ID ficticio para dry run
    }
}
// === INCLUSIÓN EXPLÍCITA DE FUNCIONES DE ATRIBUTOS DE WOOCOMMERCE ===
if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
}
$wc_includes_path = WP_PLUGIN_DIR . '/woocommerce/includes/';
if (file_exists($wc_includes_path . 'wc-attribute-functions.php')) {
    require_once $wc_includes_path . 'wc-attribute-functions.php';
    if (!function_exists('wc_get_attribute_id_from_name')) {
        error_log("Error: wc_get_attribute_id_from_name() no está disponible tras incluir wc-attribute-functions.php");
    }
} else {
    error_log("Error: No se encontró wc-attribute-functions.php en $wc_includes_path");
}

// Obtener los IDs de los atributos de producto configurados.
// Esto es importante para diferenciar entre atributos globales (taxonomías) y personalizados.
// Se ejecuta al inicio del script, una sola vez.
foreach ($product_attributes_config as $name => $slug) {
    $attribute_id = wc_get_attribute_id_from_name($name);
    if ($attribute_id) {
        $product_attribute_ids[$name] = $attribute_id;
        log_progreso("ID de atributo global '$name' (slug: $slug) obtenido: $attribute_id", 'INFO');
    } else {
        log_progreso("ADVERTENCIA: El atributo global '$name' (slug: $slug) no se encontró en WooCommerce. Asegúrate de haberlo creado. Este atributo se asignará como personalizado si se usa.", 'WARN');
    }
}


// Verificar si el proceso de importación ha terminado.
$import_finished_flag = __DIR__ . '/import_finished.flag';
$last_processed_file_path = __DIR__ . '/last_processed_epub.txt';

if (file_exists($import_finished_flag)) {
    log_progreso("El archivo 'import_finished.flag' existe. La importación ha sido marcada como completada. Finalizando script.", 'INFO');
    exit;
}

$productos_creados_en_este_lote = 0;
$last_processed_epub = '';

// Leer el último EPUB procesado para reanudar.
if (file_exists($last_processed_file_path)) {
    $last_processed_epub = trim(file_get_contents($last_processed_file_path));
    log_progreso("Reanudando importación desde: $last_processed_epub", 'INFO');
} else {
    log_progreso("Iniciando nueva secuencia de importación (no se encontró 'last_processed_epub.txt').", 'INFO');
}

$epub_files = [];
try {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directorio_raiz_libros, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'epub') {
            $epub_files[] = $file->getPathname();
        }
    }
    sort($epub_files);
    // === AÑADE ESTA LÍNEA EXACTAMENTE AQUÍ ABAJO ===
    log_progreso("DEBUG: Número de archivos EPUB detectados después de la iteración: " . count($epub_files), 'DEBUG');
    // =====================================
} catch(Exception $e) {
    die("Error Crítico al leer el directorio de libros: " . $e->getMessage());
}

$total_epub_files = count($epub_files);
log_progreso("Se encontraron $total_epub_files archivos EPUB pendientes de procesar.", 'INFO');

foreach ($epub_files as $epub_path) {
    // Si estamos reanudando, saltar archivos ya procesados.
    if (!empty($last_processed_epub) && strcmp($epub_path, $last_processed_epub) <= 0) {
        log_progreso("Saltando $epub_path (ya procesado o anterior al último procesado).", 'DEBUG');
        continue;
    }

    if ($productos_creados_en_este_lote >= $productos_por_lote) {
        log_progreso("Límite del lote ({$productos_por_lote}) alcanzado. Guardando estado y terminando script.", 'INFO');
        if (!$dry_run) { // Solo escribir archivo de estado si no es dry run [2]
            file_put_contents($last_processed_file_path, $epub_path); // Guarda el último archivo procesado ANTES de salir
        } else {
            log_progreso("DRY RUN: Se simula el guardado del último EPUB procesado: {$epub_path}", 'INFO');
        }
        break;
    }

    $nombre_archivo_original = basename($epub_path);

    // Verificar si el producto ya existe para evitar duplicados.
    $args_existencia = ['post_type' => 'product', 'meta_key' => '_original_filename', 'meta_value' => $nombre_archivo_original, 'posts_per_page' => 1, 'fields' => 'ids'];
    $producto_existente_query = new WP_Query($args_existencia);
    if ($producto_existente_query->have_posts()) {
        log_progreso("Saltando libro '{$nombre_archivo_original}': Ya existe un producto con este nombre de archivo original.", 'INFO');
        $producto_existente_query->reset_postdata();
        continue;
    }
    unset($producto_existente_query);

    log_progreso("----------------------------------------------------------------------", 'DEBUG');
    log_progreso("Iniciando procesamiento para: $epub_path", 'INFO');

    $datos_iniciales = extraer_datos_iniciales_desde_ruta($epub_path);
    if (!$datos_iniciales) {
        log_progreso("No se pudieron extraer datos iniciales del archivo: {$epub_path}", 'WARN');
        continue;
    }

    $titulo_inicial = $datos_iniciales['titulo_inicial'];
    $autor_inicial = $datos_iniciales['autor_inicial'];

    log_progreso("Datos iniciales extraídos: Título='{$titulo_inicial}', Autor='{$autor_inicial}'", 'INFO');

    $metadatos_api_bruto = obtener_metadatos_consolidados($titulo_inicial, $autor_inicial);
    // Asegurarse de que el autor de la API coincida antes de usar sus metadatos (título, autor, descripción)
    $metadatos_api_final = $metadatos_api_bruto; // Inicializar con todos los metadatos brutos
    if (!empty($metadatos_api_bruto) && !validar_coincidencia_api($metadatos_api_bruto['author'] ?? '', $autor_inicial)) {
        log_progreso("DISCREPANCIA DE API: Autor local '{$autor_inicial}' no coincide con autor de API '{$metadatos_api_bruto['author']}'. Se descartan algunos metadatos de API.", 'WARN');
        // Descartar solo los campos sensibles al autor para la descripción/título, pero mantener ISBN, páginas, etc.
        $metadatos_api_final = [
            'isbn'           => $metadatos_api_bruto['isbn'] ?? '',
            'page_count'     => $metadatos_api_bruto['page_count'] ?? '',
            'published_date' => $metadatos_api_bruto['published_date'] ?? '',
            'publisher'      => $metadatos_api_bruto['publisher'] ?? '',
            'language'       => $metadatos_api_bruto['language'] ?? '',
            'format'         => $metadatos_api_bruto['format'] ?? '',
            'image_url'      => $metadatos_api_bruto['image_url'] ?? '',
            'title'          => $titulo_inicial, // Forzar título inicial si no coincide el autor API
            'author'         => $autor_inicial,  // Forzar autor inicial si no coincide el autor API
            'description'    => ''               // Vaciar descripción para evitar datos incorrectos
        ];
    }
    if (is_null($metadatos_api_final)) $metadatos_api_final = [];


    $imagen_asignada = false;
    $id_producto = 0;

    // 1. Crear el producto en WordPress con datos básicos para obtener un ID.
    // Esta operación se salta en dry run.
    if (!$dry_run) {
        $product = new WC_Product_Simple();
        $product->set_name(traducir_texto_con_deepl($metadatos_api_final['title'] ?? $titulo_inicial));
        $product->set_sku(basename($epub_path, '.epub'));
        $product->set_status('pending');
        $product->set_catalog_visibility('hidden');
        $product->set_manage_stock(false);
        $product->set_regular_price($precio_por_defecto);
        $product->set_virtual(true);
        $product->set_downloadable(true);

        $id_producto = $product->save();
        clean_post_cache($id_producto);

        if (!$id_producto) {
            log_progreso("CRÍTICO: No se pudo crear el producto básico para '{$titulo_inicial}'. Saltando.", 'ERROR');
            continue;
        }
        log_progreso("Producto básico '{$titulo_inicial}' (ID: {$id_producto}) creado.", 'INFO');
    } else {
        log_progreso("DRY RUN: Se simula la creación del producto básico para '{$titulo_inicial}'.", 'INFO');
        $id_producto = 12345; // ID ficticio para dry run
        $product = new WC_Product_Simple(); // Crear un objeto dummy para que el resto del script no falle
        $product->set_name(traducir_texto_con_deepl($metadatos_api_final['title'] ?? $titulo_inicial));
    }


    // --- Lógica de asignación de imagen ---
    $nombre_base_sin_extension = pathinfo($epub_path, PATHINFO_FILENAME);
    $directorio_del_epub = dirname($epub_path);
    $ruta_portada_local = $directorio_del_epub . '/' . $nombre_base_sin_extension . '.jpg';

    if (file_exists($ruta_portada_local)) {
        log_progreso("Se encontró imagen de portada local (junto al EPUB): {$ruta_portada_local}", 'INFO');
        $imagen_asignada = asignar_imagen_desde_local($ruta_portada_local, $id_producto, $product->get_name(), $dry_run);
    } else {
        log_progreso("No se encontró imagen de portada local para '{$nombre_base_sin_extension}.jpg' en {$directorio_del_epub}. Intentando APIs...", 'INFO');
    }

    // Si no se asignó la imagen local, intentar con APIs (usando los metadatos ya obtenidos)
    if (!$imagen_asignada && !empty($metadatos_api_final['image_url'])) {
        $imagen_asignada = asignar_imagen_desde_url($metadatos_api_final['image_url'], $id_producto, $product->get_name(), $dry_run);
    }
    // Recargar el objeto producto después de asignar la imagen (por si la lógica de asignación lo modificó en la BD)
    // Solo recargar si no es dry run y si se asignó una imagen
    if (!$dry_run && $imagen_asignada) {
        $product = wc_get_product($id_producto);
    }
    // --- FIN Lógica de asignación de imagen ---


    // --- Generar/Reescribir Descripción con Gemini ---
    $original_api_description = $metadatos_api_final['description'] ?? "Sin descripción disponible.";
    $factual_metadata_for_gemini = [
        'isbn'           => $metadatos_api_final['isbn'] ?? '',
        'page_count'     => $metadatos_api_final['page_count'] ?? '',
        'published_date' => $metadatos_api_final['published_date'] ?? '',
        'publisher'      => $metadatos_api_final['publisher'] ?? '',
        'language'       => $metadatos_api_final['language'] ?? '',
        'format'         => $metadatos_api_final['format'] ?? ''
    ];

    $gemini_description = reescribir_descripcion_con_gemini(
    $gemini_api_key,
    $product->get_name(), // Usar el título actual del producto
    $metadatos_api_final['author'] ?? $autor_inicial, // Usar autor de API si validado, sino el inicial
    $original_api_description,
    $factual_metadata_for_gemini
);
log_progreso("DEBUG: Longitud de descripción de Gemini después de llamada: " . strlen(trim($gemini_description)), 'DEBUG');
// Si Gemini falló o devolvió algo vacío, usar la descripción original de la API o la predeterminada.
if (empty($gemini_description) || strlen(trim($gemini_description)) < 50) {
        $product_description = $original_api_description;
        log_progreso("La descripción de Gemini es corta o falló. Usando la descripción original de la API como fallback.", 'INFO');
    } else {
        $product_description = $gemini_description;
    }

    // Aplicar DeepL al título final y descripción (si se desea traducir todo)
    $titulo_final_actualizado = traducir_texto_con_deepl($product->get_name());
    $descripcion_final_producto = traducir_texto_con_deepl($product_description);

    $product->set_name($titulo_final_actualizado);
    $product->set_description($descripcion_final_producto);

    // === INICIO LÓGICA DE ASIGNACIÓN DE ATRIBUTOS ===
    // Aseguramos que $product_attribute_ids esté disponible (del inicio del script)
    $attributes_to_assign = [
        'ISBN' => $metadatos_api_final['isbn'] ?? '',
        'Número de páginas' => $metadatos_api_final['page_count'] ?? '',
        'Fecha de publicación' => $metadatos_api_final['published_date'] ?? '',
        'Editor' => $metadatos_api_final['publisher'] ?? '',
        'Idioma' => !empty($metadatos_api_final['language']) ? $metadatos_api_final['language'] : 'Español', // Si la API no da idioma, usa 'Español' como fallback.
        'Formato' => 'EPUB', // Formato fijo para EPUBs
        'Autor del libro' => $metadatos_api_final['author'] ?? $autor_inicial // Usar el autor consolidado
    ];
    assign_product_attributes_to_product_object($product, $attributes_to_assign, $product_attribute_ids);
    // === FIN LÓGICA DE ASIGNACIÓN DE ATRIBUTOS ===

    $product->set_status('publish'); // Publicar el producto una vez que esté completo
    $product->set_catalog_visibility('visible'); // Hacerlo visible en la tienda
    if (!$dry_run) {
        log_progreso("DEBUG: Intentando guardar producto con atributos. Producto ID: " . $id_producto, 'DEBUG');
        // === AÑADE ESTAS TRES LÍNEAS DE DEPURACIÓN AQUÍ ABAJO ===
        log_progreso("DEBUG: Tipo de producto antes de save(): " . $product->get_type(), 'DEBUG');
        log_progreso("DEBUG: Atributos en objeto \$product antes de save() (count): " . count($product->get_attributes()), 'DEBUG');
        echo "DEBUG_RAW_VAR_EXPORT: Atributos en objeto \$product antes de save() (var_export):\n";
		echo var_export($product->get_attributes(), true) . "\n";
		echo "DEBUG_RAW_VAR_EXPORT: Fin var_export.\n";
        try {
            $product->save();
            log_progreso("DEBUG: Producto guardado. Producto ID: " . $id_producto, 'DEBUG');
            wc_delete_product_transients($id_producto); // Añadido: Limpiar transitorios después de guardar
            log_progreso("Producto '{$product->get_name()}' (ID: {$id_producto}) actualizado y guardado con datos de WooCommerce y atributos.", 'INFO');
        } catch (Throwable $e) {
            log_progreso("Excepción al guardar el producto ID {$id_producto}: " . $e->getMessage(), 'ERROR');
            continue; // Continúa con el siguiente libro si falla el guardado final.
        }
    } else {
        log_progreso("DRY RUN: Se simula la actualización y guardado del producto '{$product->get_name()}' (ID: {$id_producto}) con datos de WooCommerce y atributos.", 'INFO');
    }


    // --- Asignar Categoría ---
    $categoria_final_nombre = $metadatos_api_final['author'] ?? $autor_inicial; // Usar el autor consolidado como categoría
    if (!empty($categoria_final_nombre)) {
        $term = get_term_by('name', $categoria_final_nombre, 'product_cat');
        $id_categoria = $term ? $term->term_id : null;
        if (is_null($id_categoria)) { // Si la categoría no existe, créala
            if (!$dry_run) { // Solo crear categoría si no es dry run
                $res_term = wp_insert_term($categoria_final_nombre, 'product_cat', ['slug' => normalize_string($categoria_final_nombre)]);
                if (!is_wp_error($res_term)) {
                    $id_categoria = $res_term['term_id'];
                    log_progreso("Categoría '$categoria_final_nombre' creada con ID {$id_categoria}.", 'INFO');
                } else {
                    log_progreso("ERROR: Falló la creación de la categoría '{$categoria_final_nombre}': " . $res_term->get_error_message(), 'ERROR');
                }
            } else {
                log_progreso("DRY RUN: Se simula la creación de la categoría '{$categoria_final_nombre}'.", 'INFO');
                $id_categoria = 99998; // ID ficticio para dry run
            }
        }
        if (!is_null($id_categoria)) {
            if (!$dry_run) { // Solo asignar término si no es dry run
                wp_set_object_terms($id_producto, (int)$id_categoria, 'product_cat');
                log_progreso("Categoría '{$categoria_final_nombre}' asignada al producto ID {$id_producto}.", 'INFO');
            } else {
                log_progreso("DRY RUN: Se simula la asignación de la categoría '{$categoria_final_nombre}' al producto ID {$id_producto}.", 'INFO');
            }
        } else {
            log_progreso("ADVERTENCIA: No se pudo asignar ninguna categoría al producto ID {$id_producto}.", 'WARN');
        }
    } else if ($default_category_id) { // Fallback a categoría por defecto si no hay autor válido
        if (!$dry_run) { // Solo asignar término si no es dry run
            wp_set_object_terms($id_producto, (int)$default_category_id, 'product_cat');
            log_progreso("Categoría por defecto '$default_category_name' (ID: $default_category_id) asignada al producto ID $id_producto (sin autor consolidado).", 'INFO');
        } else {
            log_progreso("DRY RUN: Se simula la asignación de la categoría por defecto '$default_category_name' al producto ID $id_producto.", 'INFO');
        }
    } else {
        log_progreso("ADVERTENCIA: No se pudo asignar ninguna categoría al producto ID {$id_producto}.", 'WARN');
    }

    // --- Asignar archivo EPUB como descargable ---
    $download_id = md5($epub_path . time()); // ID único para la descarga
    $downloadable_files = [
        $download_id => [
            'name' => sanitize_file_name($product->get_name() . '.epub'), // Nombre del archivo de descarga
            'file' => $epub_path // Ruta directa al archivo en el servidor.
        ]
    ];
    $product->set_downloads($downloadable_files);

    if (!$dry_run) {
        try {
            $product->save(); // Guardar cambios en el producto después de asignar descarga.
            clean_post_cache($id_producto);
            log_progreso("Archivo EPUB '{$epub_path}' asignado como descarga al producto ID {$id_producto}.", 'INFO');
        } catch (Throwable $e) {
            log_progreso("Excepción al guardar descargas para el producto ID {$id_producto}: " . $e->getMessage(), 'ERROR');
            continue;
        }
    } else {
        log_progreso("DRY RUN: Se simula la asignación del archivo EPUB '{$epub_path}' como descarga al producto ID {$id_producto}.", 'INFO');
    }


    // Marcar progreso.
    if (!$dry_run) {
        file_put_contents($last_processed_file_path, $epub_path);
    } else {
        log_progreso("DRY RUN: Se simula el marcado de progreso para: {$epub_path}", 'INFO');
    }
    $productos_creados_en_este_lote++;

    // Liberar memoria para el siguiente procesamiento
    unset($product, $metadatos_api_bruto, $metadatos_api_final, $datos_iniciales);
    if (function_exists('gc_collect_cycles')) gc_collect_cycles();
}

// === INICIO DEL CONTROL DE ESTADO DE FINALIZACIÓN ===
$total_files_processed_in_run = count($epub_files);
if ($productos_creados_en_este_lote < $productos_por_lote || $total_files_processed_in_run === 0) {
    // Si se procesaron todos los archivos disponibles en la lista de pendientes (o no había ninguno),
    // marca la importación como completa.
    log_progreso("Todos los archivos pendientes en el sistema de archivos han sido procesados. Eliminando 'last_processed_epub.txt' y creando 'import_finished.flag'.", 'INFO');
    if (!$dry_run) {
        if (file_exists($last_processed_file_path)) unlink($last_processed_file_path);
        file_put_contents($import_finished_flag, date('Y-m-d H:i:s'));
    } else {
        log_progreso("DRY RUN: Se simula la eliminación de 'last_processed_epub.txt' y la creación de 'import_finished.flag'.", 'INFO');
    }
}
// === FIN DEL CONTROL DE ESTADO DE FINALIZACIÓN ===


log_progreso("================ FIN DEL SCRIPT DE IMPORTACIÓN ================ ", 'INFO');
log_progreso("Se crearon/actualizaron {$productos_creados_en_este_lote} productos en esta ejecución.", 'INFO');

?>