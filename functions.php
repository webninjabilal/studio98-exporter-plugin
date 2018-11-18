<?php

function mogambooo_get_transient($option_name)
{
    global $wp_version;

    if (trim($option_name) == '') {
        return false;
    }

    if (version_compare($wp_version, '3.4', '>')) {
        return get_site_transient($option_name);
    }

    $transient = get_option('_site_transient_'.$option_name);

    return apply_filters("site_transient_".$option_name, $transient);
}
function mogambooo_get_error($error_object)
{
    if (!is_wp_error($error_object)) {
        return $error_object != '' ? $error_object : '';
    } else {
        $errors = array();
        if (!empty($error_object->error_data)) {
            foreach ($error_object->error_data as $error_key => $error_string) {
                $errors[] = str_replace('_', ' ', ucfirst($error_key)).': '.$error_string;
            }
        } elseif (!empty($error_object->errors)) {
            foreach ($error_object->errors as $error_key => $err) {
                $errors[] = 'Error: '.str_replace('_', ' ', strtolower($error_key));
            }
        }

        return implode('<br />', $errors);
    }
}
function validate_connection() 
{
    $public_key   = get_option( '_grandcentral_public_key' );
    if(empty($public_key)) {
        die('Site is not connected to a master instance.');
    }
    if (!isset($_POST['signature']) || !isset($_POST['validate_at']) || !isset($_POST['salt'])) {
        die('something missing in request');
    }
    $validate_at = sanitize_text_field($_POST['validate_at']);
    $signature   = sanitize_text_field($_POST['signature']);
    
    if(validateSignature($signature) == false) {
        die('Signature not validated');
    } 
    
    if(get_transient( $signature ) !== false) {
        die('Signature already used.');
    }
    
    set_transient($signature, $validate_at, 2 * HOUR_IN_SECONDS);
}
function mogambooo_is_server_writable()
{
    if (!function_exists('get_filesystem_method')) {
        include_once ABSPATH.'wp-admin/includes/file.php';
    }

    if ((!defined('FTP_HOST') || !defined('FTP_USER')) && (get_filesystem_method(array(), false) != 'direct')) {
        return false;
    } else {
        return true;
    }
}
add_action( 'wp_ajax_nopriv_grandcentral_wp_version', 'GrandCentralWpVersionCallback' );
add_action( 'wp_ajax_grandcentral_wp_version', 'GrandCentralWpVersionCallback' );

function GrandCentralWpVersionCallback() {
    $wp_version = get_bloginfo('version');
    die(json_encode(['wp_version' => $wp_version]));
    //wp-admin/update-core.php?action=do-core-upgrade
}
function std_is_safe_mode()
{
    $value = ini_get("safe_mode");
    if ((int) $value === 0 || strtolower($value) === "off") {
        return false;
    }

    return true;
}
function std_remove_http($url = '')
{
    if ($url == 'http://' or $url == 'https://') {
        return $url;
    }

    return preg_replace('/^(http|https)\:\/\/(www.)?/i', '', $url);
}
function swp_autoload($class)
{
    if (substr($class, 0, 8) === 'Symfony_'
        || substr($class, 0, 3) === 'S3_'
    ) {
        $file = dirname(__FILE__).'/src/'.str_replace('_', '/', $class).'.php';
        if (file_exists($file)) {
            include_once $file;
        }
    }
}

function validate_ajax_connection()
{
    $_grandcentral_export_ID   = get_option( '_grandcentral_export_ID' );
    if(empty($_grandcentral_export_ID)) {
        die('Site is not connected to a master instance.');
    }
    if (!isset($_POST['signature']) || !isset($_POST['validate_at']) || !isset($_POST['salt'])) {
        die('something missing in request');
    }
    $validate_at = sanitize_text_field($_POST['validate_at']);
    $signature   = sanitize_text_field($_POST['signature']);

    if($_grandcentral_export_ID != $signature) {
        die('Signature not validated');
    }
}

?>