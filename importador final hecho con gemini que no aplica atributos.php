<?php

// =================== CONFIGURACIÓN INICIAL ===================
set_time_limit(0);
echo "DEBUG_OUT: Script iniciado.\n";
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('html_errors', 0);
ini_set('memory_limit', '2048M');
echo "DEBUG_OUT: Configuración PHP aplicada.\n";

// Establecer la zona horaria a la de México Central (CST)
date_default_timezone_set('America/Mexico_City');


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

// Carga de la librería epub.php
require_once(__DIR__ . '/epub.php'); // Asegurar que epub.php esté cargado

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

$google_books_api_key = '*';
// $deepl_api_key = '*'; // Eliminada la variable DeepL
$gemini_api_key       = '*';

$directorio_raiz_libros = WP_CONTENT_DIR . '/uploads/libros/';
$carpeta_especial_otros = '_Otros'; // Se mantiene el nombre tal cual lo tenías en tu código base
$productos_por_lote = 1; // Reducido para pruebas
$precio_por_defecto = '9.99';

// Archivos para el control de estado del cron job.
$last_processed_file_path = __DIR__ . '/last_processed_epub.txt';
$import_finished_flag = __DIR__ . '/import_finished.flag';

// Ruta del archivo JSON índice - ¡IMPORTANTE!
$json_index_file_path = '/home/u415911998/domains/libros.hidronerd.com/public_html/indice.json';

// Nombres de los atributos de producto y sus slugs esperados.
$product_attributes_config = [
    'ISBN' => 'isbn',
    'Número de páginas' => 'numero-de-paginas',
    'Fecha de publicación' => 'fecha-de-publicacion',
    'Editor' => 'editor',
    'Idioma' => 'idioma',
    'Formato' => 'formato',
    'Autor del libro' => 'autor-del-libro' // Se mantiene el slug que tenías en tu código funcional
];
$product_attribute_ids = [];

// Define la categoría por defecto para los libros (por nombre).
$default_category_name = 'eBooks';
$default_category_id = null;

// Registrar el tiempo de inicio del script
$start_time = microtime(true);

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
 */
function log_progreso($mensaje, $nivel = 'INFO') {
    global $dry_run;
    $timestamp = (new DateTime())->format('[Y-m-d H:i:s T]');
    $prefix = $dry_run ? ' [DRY RUN]' : '';
    $linea_log = "$timestamp [$nivel]{$prefix} - $mensaje" . PHP_EOL;
    file_put_contents(__DIR__ . '/import_progress.log', $linea_log, FILE_APPEND);
    echo $linea_log;
    if (php_sapi_name() === 'cli') fflush(STDOUT);
}

/**
 * Función de apagado para registrar el tiempo total de ejecución y eliminar el lock.
 */
register_shutdown_function(function() {
    global $start_time, $dry_run;
    $end_time = microtime(true);
    $execution_time = round($end_time - $start_time, 2);
    log_progreso("Script finalizado. Tiempo de ejecución: {$execution_time} segundos.", 'INFO');

    $lock_file_to_delete = __DIR__ . '/import.lock';
    if (file_exists($lock_file_to_delete)) {
        unlink($lock_file_to_delete);
        log_progreso("Archivo de bloqueo '{$lock_file_to_delete}' eliminado.", 'INFO');
    }
});


/**
 * Normaliza una cadena para hacerla compatible con slugs de URL o nombres de archivo.
 */
function normalize_string($string) {
    $string = remove_accents($string);
    $string = strtolower($string);
    $string = preg_replace('/[^a-z0-9\s-]/', '', $string);
    $string = preg_replace('/[\s-]+/', '-', $string);
    $string = trim($string, '-');
    return $string;
}

/**
 * Normaliza nombres de autor para mejorar la coincidencia.
 */
function normalize_author_name($name) {
    $name = str_replace(['.', ','], '', $name);
    $name = preg_replace('/\s+/', ' ', trim($name));
    return $name;
}

/**
 * Calcula la similitud entre dos cadenas usando similar_text.
 */
function validar_coincidencia_api($api_author, $local_author, $threshold = 70) {
    $api_author_norm = normalize_string(normalize_author_name($api_author));
    $local_author_norm = normalize_string(normalize_author_name($local_author));

    if (empty($api_author_norm) || empty($local_author_norm)) {
        return false;
    }

    similar_text($api_author_norm, $local_author_norm, $percent);
    log_progreso("Similitud entre autor API ('$api_author_norm') y autor local ('$local_author_norm'): " . round($percent, 2) . "%", 'DEBUG');

    return $percent >= $threshold;
}

// **Función traducir_texto_con_deepl ELIMINADA del script**

/**
 * Reescribe una descripción utilizando la API de Google Gemini o la genera desde cero si es necesario.
 */
function reescribir_descripcion_con_gemini($gemini_api_key, $title, $author, $original_description, $factual_metadata) {
    log_progreso("Intentando generar/reescribir descripción con Gemini API para '$title'.", 'DEBUG');

    if (empty($gemini_api_key) || $gemini_api_key === 'TU_API_KEY_DE_GEMINI') {
        log_progreso("Error: Clave API de Gemini no configurada. No se puede generar descripción.", 'ERROR');
        return $original_description;
    }

    $api_url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$gemini_api_key}";

    $prompt = "";
    if (!empty($original_description) && strlen($original_description) > 50) {
        log_progreso("Gemini: Reescribiendo descripción existente.", 'INFO');
        $prompt = "Reescribe la siguiente descripción de un libro de forma creativa, única y atractiva para una tienda online, optimizada para SEO. Mantén los hechos clave pero usa un lenguaje narrativo e inmersivo. El libro se titula '{$title}' y el autor es '{$author}'. Descripción original: \n\n\"{$original_description}\"";
    } else {
        log_progreso("Gemini: Generando descripción desde cero.", 'INFO');
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
        'timeout'     => 60,
        'sslverify'   => false
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
        log_progreso("DEBUG: Descripción GENERADA por Gemini (primeras 100 chars): " . substr($generated_text, 0, 100) . "...", 'DEBUG');
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
    $query = http_build_query(['q' => $title . ' ' . $author]);
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

    $best_match = null;
    foreach ($data['docs'] as $doc) {
        if (isset($doc['description']) && !empty($doc['description'])) {
            $best_match = $doc;
            break;
        }
        if (isset($doc['title']) && validar_coincidencia_api($doc['title'], $title, 85)) {
            $best_match = $doc;
            break;
        }
    }

    if (!$best_match) {
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
        'image_url' => ''
    ];

    if (isset($best_match['description'])) {
        if (is_array($best_match['description']) && isset($best_match['description']['value'])) {
            $metadata['description'] = $best_match['description']['value'];
        } elseif (is_string($best_match['description'])) {
            $metadata['description'] = $best_match['description'];
        }
    }

    if (isset($best_match['isbn'])) {
        $metadata['isbn'] = array_unique(array_merge($metadata['isbn'], (array)$best_match['isbn']));
    }

    return $metadata;
}

/**
 * Consolidar metadatos de múltiples fuentes, priorizando JSON, luego EPUB, y finalmente APIs.
 * Esta es la función clave que ahora toma la entrada directa del JSON.
 */
function obtener_metadatos_consolidados_final($json_book_entry, $full_epub_path) {
    global $google_books_api_key;

    // Inicializar metadatos consolidados con lo que viene del JSON
    $consolidated_metadata = [
        'title'          => $json_book_entry['title'] ?? '',
        'author'         => implode(', ', $json_book_entry['authors'] ?? []),
        'description'    => $json_book_entry['description'] ?? '',
        'isbn'           => [], // Empezamos vacío, se llenará con ISBNs del JSON
        'page_count'     => $json_book_entry['pagecount'] ?? null,
        'published_date' => $json_book_entry['published'] ?? '',
        'publisher'      => '', // Se buscará en API si no está en JSON
        'language'       => '', // Se buscará en API si no está en JSON
        'format'         => 'EPUB', // Formato fijo para este importador
        'image_url'      => ''  // No se usa para descarga, pero se mantiene por estructura
    ];

    log_progreso("DEBUG: Metadatos iniciales del JSON: " . print_r($consolidated_metadata, true), 'DEBUG');

    // Prioridad 1: ISBN del JSON
    if (!empty($json_book_entry['isbn'])) {
        $consolidated_metadata['isbn'] = array_unique(array_merge($consolidated_metadata['isbn'], (array)$json_book_entry['isbn']));
        log_progreso("DEBUG: ISBN(s) tomado(s) del JSON: " . implode(', ', $consolidated_metadata['isbn']), 'DEBUG');
    }

    // Prioridad 2: ISBN del EPUB (si no está en JSON)
    // Se intentará abrir el EPUB SOLO si no se encontró ISBN en el JSON.
    if (empty($consolidated_metadata['isbn']) && file_exists($full_epub_path)) {
        try {
            $epub = new EPub($full_epub_path);
            $epub_isbn = $epub->ISBN();
            if (!empty($epub_isbn)) {
                $consolidated_metadata['isbn'] = array_unique(array_merge($consolidated_metadata['isbn'], (array)$epub_isbn));
                log_progreso("DEBUG: ISBN(s) tomado(s) del EPUB: " . implode(', ', $consolidated_metadata['isbn']), 'DEBUG');
            } else {
                log_progreso("DEBUG: No se encontró ISBN en el archivo EPUB.", 'DEBUG');
            }
        } catch (Exception $e) {
            log_progreso("ERROR al leer EPUB para ISBN en '{$full_epub_path}': " . $e->getMessage(), 'ERROR');
        }
    }

    // Prioridad 3: ISBN, Publisher y Language de las APIs (si aún faltan)
    $api_isbn_found_this_step = false;
    $title_for_api = $consolidated_metadata['title'];
    $author_for_api = $consolidated_metadata['author'];

    if (empty($consolidated_metadata['isbn']) || empty($consolidated_metadata['publisher']) || empty($consolidated_metadata['language'])) {
        $google_metadata = ejecutar_consulta_google_api($title_for_api, $author_for_api, $google_books_api_key);
        if ($google_metadata) {
            if (!empty($google_metadata['isbn'])) {
                $consolidated_metadata['isbn'] = array_unique(array_merge($consolidated_metadata['isbn'], (array)$google_metadata['isbn']));
                log_progreso("DEBUG: ISBN(s) complementado(s) de Google Books: " . implode(', ', $consolidated_metadata['isbn']), 'DEBUG');
                $api_isbn_found_this_step = true;
            }
            if (empty($consolidated_metadata['publisher']) && !empty($google_metadata['publisher'])) {
                $consolidated_metadata['publisher'] = $google_metadata['publisher'];
                log_progreso("DEBUG: Publisher tomado de Google Books: {$consolidated_metadata['publisher']}", 'DEBUG');
            }
            if (empty($consolidated_metadata['language']) && !empty($google_metadata['language'])) {
                $consolidated_metadata['language'] = $google_metadata['language'];
                log_progreso("DEBUG: Idioma tomado de Google Books: {$consolidated_metadata['language']}", 'DEBUG');
            }
        }
    }

    if (!$api_isbn_found_this_step || empty($consolidated_metadata['publisher']) || empty($consolidated_metadata['language'])) {
        $open_library_metadata = buscar_en_open_library($title_for_api, $author_for_api);
        if ($open_library_metadata) {
            if (!empty($open_library_metadata['isbn'])) {
                $consolidated_metadata['isbn'] = array_unique(array_merge($consolidated_metadata['isbn'], (array)$open_library_metadata['isbn']));
                log_progreso("DEBUG: ISBN(s) complementado(s) de Open Library: " . implode(', ', $consolidated_metadata['isbn']), 'DEBUG');
            }
            if (empty($consolidated_metadata['publisher']) && !empty($open_library_metadata['publisher'])) {
                $consolidated_metadata['publisher'] = $open_library_metadata['publisher'];
                log_progreso("DEBUG: Publisher tomado de Open Library: {$consolidated_metadata['publisher']}", 'DEBUG');
            }
            if (empty($consolidated_metadata['language']) && !empty($open_library_metadata['language'])) {
                $consolidated_metadata['language'] = $open_library_metadata['language'];
                log_progreso("DEBUG: Idioma tomado de Open Library: {$consolidated_metadata['language']}", 'DEBUG');
            }
        }
    }

    // Asegurar que el ISBN sea una cadena separada por comas al final
    $consolidated_metadata['isbn'] = implode(', ', array_unique($consolidated_metadata['isbn']));

    // Fallback para idioma si no se encontró en ningún lado
    if (empty($consolidated_metadata['language'])) {
        $consolidated_metadata['language'] = 'Español';
        log_progreso("ADVERTENCIA: Idioma no encontrado en APIs. Se usará 'Español' como fallback.", 'WARN');
    }

    log_progreso("Metadatos consolidados FINALIZADOS: " . print_r($consolidated_metadata, true), 'DEBUG');
    return $consolidated_metadata;
}

/**
 * Obtiene el ID del término para un valor de atributo dado, creándolo si no existe.
 */
function get_or_create_attribute_term_id( $attribute_display_name, $term_value ) {
    $taxonomy = wc_attribute_taxonomy_name( $attribute_display_name );
    $term_value = (string)$term_value;

    $term = get_term_by( 'name', $term_value, $taxonomy );

    if ( ! $term || is_wp_error( $term ) ) {
        $inserted_term = wp_insert_term( $term_value, $taxonomy );

        if ( ! is_wp_error( $inserted_term ) ) {
            return $inserted_term['term_id'];
        } else {
            error_log( "Error al crear el término '{$term_value}' para la taxonomía '{$taxonomy}': " . $inserted_term->get_error_message() );
            return 0;
        }
    }
    return $term->term_id;
}

/**
 * Asigna atributos de producto de WooCommerce.
 * **Esta función ha sido restaurada EXACTAMENTE a la versión de tu "importador final.php" adjunto.**
 */
function assign_product_attributes_to_product_object($product, $attributes_data, $attribute_ids_map) {
    $product_attributes = [];

    $wc_product_attribute_class_exists = class_exists('WC_Product_Attribute');
    log_progreso("DEBUG: class_exists('WC_Product_Attribute') = " . ($wc_product_attribute_class_exists ? 'true' : 'false'), 'DEBUG');

    $terms_to_assign = [];

    foreach ($attributes_data as $attr_name_visible => $attr_value) {
        if (empty($attr_value) && $attr_value !== 0 && $attr_value !== '0') {
            log_progreso("Atributo '$attr_name_visible' tiene valor vacío o nulo. No se asignará.", 'DEBUG');
            continue;
        }

        $attribute_slug = sanitize_title($attr_name_visible);
        $is_global_attribute_processed = false;

        $attribute_id_global = 0;
        if ($wc_product_attribute_class_exists && isset($attribute_ids_map[$attr_name_visible])) {
            $attribute_id_global = $attribute_ids_map[$attr_name_visible];
            
            $taxonomy_name = wc_attribute_taxonomy_name( $attr_name_visible );

            if (taxonomy_exists($taxonomy_name)) {
                $current_attribute_term_ids = [];
                $values = array_map('trim', explode(',', (string)$attr_value));

                foreach ($values as $single_value) {
                    if (empty($single_value)) continue;

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
                    $attribute_object->set_id($attribute_id_global); // Mantengo esta línea como estaba en tu código funcional
                    $attribute_object->set_name($attr_name_visible);

                    $term_names_for_options = [];
                    foreach ($current_attribute_term_ids as $term_id) {
                        $term_obj = get_term_by('id', $term_id, $taxonomy_name);
                        if ($term_obj && !is_wp_error($term_obj)) {
                            $term_names_for_options[] = $term_obj->name;
                        }
                    }
                    $attribute_object->set_options($term_names_for_options);

                    $attribute_object->set_position(count($product_attributes));
                    $attribute_object->set_visible(true);
                    $attribute_object->set_variation(false);

                    $product_attributes[ $taxonomy_name ] = $attribute_object;

                    $terms_to_assign[$taxonomy_name] = array_merge($terms_to_assign[$taxonomy_name] ?? [], array_map('intval', $current_attribute_term_ids));

                    log_progreso("Atributo GLOBAL '{$attr_name_visible}' asignado con términos NOMBRES: " . implode(', ', $term_names_for_options) . " (IDs: " . implode(', ', $current_attribute_term_ids) . ") y valor original: '{$attr_value}'.", 'INFO');
                    $is_global_attribute_processed = true;

                } else {
                    log_progreso("ADVERTENCIA: No se pudo asignar ningún término válido para el atributo global '{$attr_name_visible}'. Se asignará como personalizado si es necesario.", 'WARN');
                    $is_global_attribute_processed = false;
                }

            } else {
                log_progreso("ADVERTENCIA: La taxonomía '{$taxonomy_name}' para el atributo global '{$attr_name_visible}' no existe. Se asignará como atributo personalizado.", 'WARN');
            }
        }

        if (!$is_global_attribute_processed) {
            $attribute_object = new WC_Product_Attribute();
            $attribute_object->set_name($attr_name_visible);
            $attribute_object->set_options([(string)$attr_value]);
            $attribute_object->set_position(count($product_attributes));
            $attribute_object->set_visible(true);
            $attribute_object->set_variation(false);
            $attribute_object->set_id(0);
            $product_attributes[$attribute_slug] = $attribute_object;
            log_progreso("Atributo PERSONALIZADO '{$attr_name_visible}' asignado con valor '{$attr_value}'.", 'INFO');
        }
    }

    $product->set_attributes($product_attributes);

    foreach ($terms_to_assign as $taxonomy => $term_ids) {
        wp_set_object_terms($product->get_id(), array_map('intval', array_unique($term_ids)), $taxonomy, false);
        log_progreso("DEBUG: wp_set_object_terms ejecutado para producto {$product->get_id()} en taxonomía '{$taxonomy}' con IDs: " . implode(', ', array_unique($term_ids)), 'DEBUG');
    }
}

/**
 * Asigna una imagen de portada desde una ruta local.
 * **Esta función no fue modificada.**
 */
function asignar_imagen_desde_local($ruta_imagen_local, $id_producto, $titulo_libro, $dry_run = false) {
    if ($dry_run) {
        log_progreso("DRY RUN: Se simula la asignación de imagen local para '{$titulo_libro}'.", 'INFO');
        return true;
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

        if (set_post_thumbnail($id_producto, $attach_id)) {
            log_progreso("Imagen destacada asignada desde archivo local: {$upload_file['url']}", 'INFO');
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

// **Función asignar_imagen_desde_url ELIMINADA del script**

// =================================================================================
// 4. LÓGICA PRINCIPAL DEL SCRIPT
// =================================================================================

// Borramos el log en cada inicio, esto es útil para el desarrollo/depuración.
if (file_exists(__DIR__ . '/import_progress.log')) {
    unlink(__DIR__ . '/import_progress.log');
}

log_progreso("================ INICIO DEL SCRIPT DE IMPORTACIÓN (v5.6.0 - JSON Main Source, Attributes Preserved) ================", 'INFO');

// --- MECANISMO DE BLOQUEO DE CRON ---
$lock_file = __DIR__ . '/import.lock';
$lock_timeout = 720; // 12 minutos en segundos

if (file_exists($lock_file)) {
    $lock_time = filemtime($lock_file);
    if (time() - $lock_time < $lock_timeout) {
        die("[" . date('Y-m-d H:i:s') . "] - El script ya está en ejecución. Bloqueado por 'import.lock'. Saliendo.\n");
    } else {
        log_progreso("Archivo de bloqueo 'import.lock' encontrado pero caducado. Eliminando y continuando.", 'WARN');
        unlink($lock_file);
    }
}
file_put_contents($lock_file, getmypid()); // Crea el archivo de bloqueo con el PID actual

// La función register_shutdown_function ya se encarga de eliminar el lock y registrar el tiempo final.
// --- FIN MECANISMO DE BLOQUEO ---


// Obtener o crear la categoría por defecto
$term = get_term_by('name', $default_category_name, 'product_cat');
if ($term) {
    $default_category_id = $term->term_id;
    log_progreso("ID de categoría '$default_category_name' obtenido: $default_category_id", 'INFO');
} else {
    if (!$dry_run) {
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
        $default_category_id = 99999;
    }
}

// Obtener los IDs de los atributos de producto configurados.
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
$last_processed_entry_filename = ''; // Ahora guarda el 'filename' del JSON

// Leer el último libro procesado para reanudar.
if (file_exists($last_processed_file_path)) {
    $last_processed_entry_filename = trim(file_get_contents($last_processed_file_path));
    log_progreso("Reanudando importación desde el archivo JSON: $last_processed_entry_filename", 'INFO');
} else {
    log_progreso("Iniciando nueva secuencia de importación (no se encontró 'last_processed_epub.txt').", 'INFO');
}

// --- Cargar el archivo JSON índice ---
$libros_data_from_json = [];
if (file_exists($json_index_file_path)) {
    log_progreso("Cargando datos desde: {$json_index_file_path}", 'INFO');
    $json_content = file_get_contents($json_index_file_path);
    $libros_data_from_json = json_decode($json_content, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        log_progreso("ERROR CRÍTICO: Falló la decodificación del JSON: " . json_last_error_msg(), 'FATAL');
        die("Error al procesar el archivo JSON.");
    }
    log_progreso("Se cargaron " . count($libros_data_from_json) . " entradas de libros desde el JSON.", 'INFO');

    // Ordenar para procesar _Otros primero
    usort($libros_data_from_json, function($a, $b) use ($carpeta_especial_otros) {
        $a_path = dirname($a['filename'] ?? '');
        $b_path = dirname($b['filename'] ?? '');

        $is_a_otros = (basename($a_path) === $carpeta_especial_otros);
        $is_b_otros = (basename($b_path) === $carpeta_especial_otros);

        if ($is_a_otros && !$is_b_otros) return -1;
        if (!$is_a_otros && $is_b_otros) return 1;
        return 0;
    });
    log_progreso("DEBUG: JSON ordenado, priorizando la carpeta '{$carpeta_especial_otros}'.", 'DEBUG');

} else {
    log_progreso("ERROR CRÍTICO: No se encontró el archivo JSON índice en la ruta: {$json_index_file_path}", 'FATAL');
    die("No se pudo continuar sin el archivo JSON índice.");
}


$total_json_entries = count($libros_data_from_json);
log_progreso("Se encontraron $total_json_entries entradas de libros en el JSON.", 'INFO');

// Bucle principal ahora itera sobre las entradas del JSON.
foreach ($libros_data_from_json as $json_book_entry) {
    $relative_epub_filename = $json_book_entry['filename'] ?? '';
    if (empty($relative_epub_filename)) {
        log_progreso("ADVERTENCIA: Entrada de JSON sin 'filename'. Saltando.", 'WARN');
        continue;
    }

    $full_epub_path = $directorio_raiz_libros . $relative_epub_filename;
    $nombre_archivo_original = basename($full_epub_path);

    // Si estamos reanudando, saltar entradas ya procesadas.
    if (!empty($last_processed_entry_filename) && strcmp($relative_epub_filename, $last_processed_entry_filename) <= 0) {
        log_progreso("Saltando entrada '{$relative_epub_filename}' (ya procesada o anterior al último procesado).", 'DEBUG');
        continue;
    }

    if ($productos_creados_en_este_lote >= $productos_por_lote) {
        log_progreso("Límite del lote ({$productos_por_lote}) alcanzado. Guardando estado y terminando script.", 'INFO');
        if (!$dry_run) {
            file_put_contents($last_processed_file_path, $relative_epub_filename); // Guarda el filename JSON del último procesado
        } else {
            log_progreso("DRY RUN: Se simula el guardado del último EPUB procesado: {$relative_epub_filename}", 'INFO');
        }
        break;
    }

    log_progreso("----------------------------------------------------------------------", 'DEBUG');
    log_progreso("Iniciando procesamiento para entrada JSON: '{$relative_epub_filename}'", 'INFO');

    // === Priorización y Consolidación de Metadatos (usando la nueva función que prioriza JSON) ===
    $metadatos_consolidados = obtener_metadatos_consolidados_final($json_book_entry, $full_epub_path);
    if (empty($metadatos_consolidados['title']) && empty($metadatos_consolidados['author'])) {
        log_progreso("ADVERTENCIA: No se pudo obtener título ni autor de ninguna fuente para '{$relative_epub_filename}'. Saltando.", 'WARN');
        continue;
    }

    // === Definición del SKU y Verificación de Duplicados ===
    $product_sku = '';
    $product_id_by_sku = 0;
    $is_duplicate = false;

    // Prioridad 1: Usar ISBN como SKU
    if (!empty($metadatos_consolidados['isbn'])) {
        $args_isbn = [
            'post_type'      => 'product',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'     => '_sku',
                    'value'   => $metadatos_consolidados['isbn'],
                    'compare' => '='
                ],
                [
                    'key'     => 'attribute_pa_isbn',
                    'value'   => $metadatos_consolidados['isbn'],
                    'compare' => '='
                ]
            ],
            'tax_query' => [
                [
                    'taxonomy' => 'pa_isbn',
                    'field'    => 'name',
                    'terms'    => explode(', ', $metadatos_consolidados['isbn']),
                    'operator' => 'IN'
                ]
            ]
        ];
        $existing_products_isbn = new WP_Query($args_isbn);
        if ($existing_products_isbn->have_posts()) {
            $product_id_by_sku = $existing_products_isbn->posts[0];
            $is_duplicate = true;
            log_progreso("Saltando libro '{$nombre_archivo_original}': Ya existe un producto con ISBN '{$metadatos_consolidados['isbn']}' (ID: {$product_id_by_sku}).", 'INFO');
            $existing_products_isbn->reset_postdata();
        } else {
            $product_sku = $metadatos_consolidados['isbn'];
            log_progreso("DEBUG: Se asignará SKU basado en ISBN: {$product_sku}", 'DEBUG');
        }
    }

    // Prioridad 2: Usar filename como SKU si no hay ISBN o no se encontró duplicado por ISBN
    if (!$is_duplicate && empty($product_sku)) {
        $product_sku = basename($relative_epub_filename, '.epub');
        $args_filename = [
            'post_type'      => 'product',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'     => '_sku',
                    'value'   => $product_sku,
                    'compare' => '='
                ],
                [
                    'key'     => '_original_filename',
                    'value'   => $nombre_archivo_original,
                    'compare' => '='
                ]
            ]
        ];
        $existing_products_filename = new WP_Query($args_filename);
        if ($existing_products_filename->have_posts()) {
            $product_id_by_sku = $existing_products_filename->posts[0];
            $is_duplicate = true;
            log_progreso("Saltando libro '{$nombre_archivo_original}': Ya existe un producto con SKU/Original Filename '{$product_sku}' (ID: {$product_id_by_sku}).", 'INFO');
            $existing_products_filename->reset_postdata();
        } else {
            log_progreso("DEBUG: No hay ISBN, se asignará SKU basado en filename: {$product_sku}", 'DEBUG');
        }
    }

    if ($is_duplicate) {
        continue;
    }

    // --- Creación/Actualización del Producto ---
    $id_producto = 0;
    $product = new WC_Product_Simple();

    if (!$dry_run) {
        $product->set_name($metadatos_consolidados['title']); // No se usa DeepL aquí
        $product->set_sku($product_sku);
        $product->set_status('pending');
        $product->set_catalog_visibility('hidden');
        $product->set_manage_stock(false);
        $product->set_regular_price($precio_por_defecto);
        $product->set_virtual(true);
        $product->set_downloadable(true);

        $id_producto = $product->save();
        clean_post_cache($id_producto);

        if (!$id_producto) {
            log_progreso("CRÍTICO: No se pudo crear el producto básico para '{$metadatos_consolidados['title']}'. Saltando.", 'ERROR');
            continue;
        }
        log_progreso("Producto básico '{$metadatos_consolidados['title']}' (ID: {$id_producto}, SKU: {$product_sku}) creado.", 'INFO');
    } else {
        log_progreso("DRY RUN: Se simula la creación del producto básico para '{$metadatos_consolidados['title']}' (SKU: {$product_sku}).", 'INFO');
        $id_producto = 12345;
        $product->set_name($metadatos_consolidados['title']);
        $product->set_sku($product_sku);
    }

    // --- Lógica de asignación de imagen (SOLO LOCAL) ---
    $imagen_asignada = false;
    $nombre_base_sin_extension_epub = pathinfo($full_epub_path, PATHINFO_FILENAME);
    $directorio_del_epub = dirname($full_epub_path);
    $ruta_portada_local = $directorio_del_epub . '/' . $nombre_base_sin_extension_epub . '.jpg';

    if (file_exists($ruta_portada_local)) {
        log_progreso("Se encontró imagen de portada local (junto al EPUB): {$ruta_portada_local}", 'INFO');
        $imagen_asignada = asignar_imagen_desde_local($ruta_portada_local, $id_producto, $product->get_name(), $dry_run);
    } else {
        log_progreso("No se encontró imagen de portada local para '{$nombre_base_sin_extension_epub}.jpg' en {$directorio_del_epub}. No se asignará imagen por URL (lógica eliminada).", 'INFO');
    }

    // Recargar el objeto producto si no es dry run y se asignó una imagen
    if (!$dry_run && $imagen_asignada) {
        $product = wc_get_product($id_producto);
    }

    // --- Generar/Reescribir Descripción con Gemini ---
    $original_description_from_json = $metadatos_consolidados['description'];
    $factual_metadata_for_gemini = [
        'isbn'           => $metadatos_consolidados['isbn'],
        'page_count'     => $metadatos_consolidados['page_count'],
        'published_date' => $metadatos_consolidados['published_date'],
        'publisher'      => $metadatos_consolidados['publisher'],
        'language'       => $metadatos_consolidados['language'],
        'format'         => $metadatos_consolidados['format']
    ];

    $gemini_description = reescribir_descripcion_con_gemini(
        $gemini_api_key,
        $product->get_name(),
        $metadatos_consolidados['author'],
        $original_description_from_json,
        $factual_metadata_for_gemini
    );
    log_progreso("DEBUG: Longitud de descripción de Gemini después de llamada: " . strlen(trim($gemini_description)), 'DEBUG');

    if (empty($gemini_description) || strlen(trim($gemini_description)) < 50) {
        $product_description = $original_description_from_json;
        log_progreso("La descripción de Gemini es corta o falló. Usando la descripción original del JSON como fallback.", 'INFO');
    } else {
        $product_description = $gemini_description;
    }

    // DeepL ha sido eliminado: se usa directamente la descripción generada/original.
    $product->set_description($product_description);


    // === LÓGICA DE ASIGNACIÓN DE ATRIBUTOS ===
    // Se ha restaurado esta función EXACTAMENTE a tu "importador final.php" adjunto.
    $attributes_to_assign = [
        'ISBN' => $metadatos_consolidados['isbn'],
        'Número de páginas' => $metadatos_consolidados['page_count'],
        'Fecha de publicación' => $metadatos_consolidados['published_date'],
        'Editor' => $metadatos_consolidados['publisher'],
        'Idioma' => $metadatos_consolidados['language'],
        'Formato' => 'EPUB',
        'Autor del libro' => $metadatos_consolidados['author']
    ];
    assign_product_attributes_to_product_object($product, $attributes_to_assign, $product_attribute_ids);

    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    if (!$dry_run) {
        log_progreso("DEBUG: Intentando guardar producto con atributos. Producto ID: " . $id_producto, 'DEBUG');
        log_progreso("DEBUG: Tipo de producto antes de save(): " . $product->get_type(), 'DEBUG');
        log_progreso("DEBUG: Atributos en objeto \$product antes de save() (count): " . count($product->get_attributes()), 'DEBUG');
        echo "DEBUG_RAW_VAR_EXPORT: Atributos en objeto \$product antes de save() (var_export):\n";
		echo var_export($product->get_attributes(), true) . "\n";
		echo "DEBUG_RAW_VAR_EXPORT: Fin var_export.\n";
        try {
            $product->save();
            log_progreso("DEBUG: Producto guardado. Producto ID: " . $id_producto, 'DEBUG');
            wc_delete_product_transients($id_producto);
            log_progreso("Producto '{$product->get_name()}' (ID: {$id_producto}) actualizado y guardado con datos de WooCommerce y atributos.", 'INFO');
        } catch (Throwable $e) {
            log_progreso("Excepción al guardar el producto ID {$id_producto}: " . $e->getMessage(), 'ERROR');
            continue;
        }
    } else {
        log_progreso("DRY RUN: Se simula la actualización y guardado del producto '{$product->get_name()}' (ID: {$id_producto}) con datos de WooCommerce y atributos.", 'INFO');
    }

    // --- Asignar Categoría (Autor consolidado o por defecto) ---
    $categoria_final_nombre = $metadatos_consolidados['author'];
    if (!empty($categoria_final_nombre)) {
        $term = get_term_by('name', $categoria_final_nombre, 'product_cat');
        $id_categoria = $term ? $term->term_id : null;
        if (is_null($id_categoria)) {
            if (!$dry_run) {
                $res_term = wp_insert_term($categoria_final_nombre, 'product_cat', ['slug' => normalize_string($categoria_final_nombre)]);
                if (!is_wp_error($res_term)) {
                    $id_categoria = $res_term['term_id'];
                    log_progreso("Categoría '$categoria_final_nombre' creada con ID {$id_categoria}.", 'INFO');
                } else {
                    log_progreso("ERROR: Falló la creación de la categoría '{$categoria_final_nombre}': " . $res_term->get_error_message(), 'ERROR');
                }
            } else {
                log_progreso("DRY RUN: Se simula la creación de la categoría '{$categoria_final_nombre}'.", 'INFO');
                $id_categoria = 99998;
            }
        }
        if (!is_null($id_categoria)) {
            if (!$dry_run) {
                wp_set_object_terms($id_producto, (int)$id_categoria, 'product_cat');
                log_progreso("Categoría '{$categoria_final_nombre}' asignada al producto ID {$id_producto}.", 'INFO');
            } else {
                log_progreso("DRY RUN: Se simula la asignación de la categoría '{$categoria_final_nombre}' al producto ID {$id_producto}.", 'INFO');
            }
        } else {
            log_progreso("ADVERTENCIA: No se pudo asignar ninguna categoría al producto ID {$id_producto}.", 'WARN');
        }
    } else if ($default_category_id) {
        if (!$dry_run) {
            wp_set_object_terms($id_producto, (int)$default_category_id, 'product_cat');
            log_progreso("Categoría por defecto '$default_category_name' (ID: $default_category_id) asignada al producto ID $id_producto (sin autor consolidado).", 'INFO');
        } else {
            log_progreso("DRY RUN: Se simula la asignación de la categoría por defecto '$default_category_name' al producto ID $id_producto.", 'INFO');
        }
    } else {
        log_progreso("ADVERTENCIA: No se pudo asignar ninguna categoría al producto ID {$id_producto}.", 'WARN');
    }

    // --- Asignar archivo EPUB como descargable ---
    if (file_exists($full_epub_path)) {
        $download_id = md5($full_epub_path . time());
        $downloadable_files = [
            $download_id => [
                'name' => sanitize_file_name($product->get_name() . '.epub'),
                'file' => $full_epub_path
            ]
        ];
        $product->set_downloads($downloadable_files);

        if (!$dry_run) {
            try {
                $product->save();
                clean_post_cache($id_producto);
                log_progreso("Archivo EPUB '{$full_epub_path}' asignado como descarga al producto ID {$id_producto}.", 'INFO');
            } catch (Throwable $e) {
                log_progreso("Excepción al guardar descargas para el producto ID {$id_producto}: " . $e->getMessage(), 'ERROR');
                continue;
            }
        } else {
            log_progreso("DRY RUN: Se simula la asignación del archivo EPUB '{$full_epub_path}' como descarga al producto ID {$id_producto}.", 'INFO');
        }
    } else {
        log_progreso("ERROR: Archivo EPUB no encontrado en la ruta esperada: {$full_epub_path}. No se asignará como descargable.", 'ERROR');
    }


    // Marcar progreso.
    if (!$dry_run) {
        file_put_contents($last_processed_file_path, $relative_epub_filename);
    } else {
        log_progreso("DRY RUN: Se simula el marcado de progreso para: {$relative_epub_filename}", 'INFO');
    }
    $productos_creados_en_este_lote++;

    // Liberar memoria para el siguiente procesamiento
    unset($product, $metadatos_consolidados, $json_book_entry);
    if (function_exists('gc_collect_cycles')) gc_collect_cycles();
}

// === INICIO DEL CONTROL DE ESTADO DE FINALIZACIÓN ===
$total_files_processed_in_this_run = $productos_creados_en_este_lote;

$all_processed = true;
if ($total_json_entries > 0 && !empty($last_processed_entry_filename)) {
    $last_processed_index = -1;
    foreach ($libros_data_from_json as $index => $entry) {
        if (($entry['filename'] ?? '') === $last_processed_entry_filename) {
            $last_processed_index = $index;
            break;
        }
    }
    if ($last_processed_index < (count($libros_data_from_json) - 1)) {
        $all_processed = false;
    }
} elseif ($total_json_entries > 0 && empty($last_processed_entry_filename) && $productos_creados_en_this_lote > 0) {
    if ($productos_creados_en_este_lote < $productos_por_lote && $productos_creados_en_este_lote == $total_json_entries) {
        $all_processed = true;
    } else {
        $all_processed = false;
    }
}


if ($all_processed) {
    log_progreso("Todos los archivos en el JSON han sido procesados. Eliminando 'last_processed_epub.txt' y creando 'import_finished.flag'.", 'INFO');
    if (!$dry_run) {
        if (file_exists($last_processed_file_path)) unlink($last_processed_file_path);
        file_put_contents($import_finished_flag, date('Y-m-d H:i:s'));
    } else {
        log_progreso("DRY RUN: Se simula la eliminación de 'last_processed_epub.txt' y la creación de 'import_finished.flag'.", 'INFO');
    }
} else {
    log_progreso("La importación no ha terminado. Quedan más entradas en el JSON para el siguiente lote.", 'INFO');
}
// === FIN DEL CONTROL DE ESTADO DE FINALIZACIÓN ===


log_progreso("================ FIN DEL SCRIPT DE IMPORTACIÓN ================ ", 'INFO');
log_progreso("Se crearon/actualizaron {$productos_creados_en_este_lote} productos en esta ejecución.", 'INFO');

?>