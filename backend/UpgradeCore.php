<?php

add_action( 'wp_ajax_nopriv_grandcentral_upgrade_core', 'grandcentralUpdateCoreCallback' );
add_action( 'wp_ajax_grandcentral_upgrade_core', 'grandcentralUpdateCoreCallback' );

function grandcentralUpdateCoreCallback()
{
    validate_connection();
    $params = sanitize_text_field($_POST['params']);

    $core_upgrade         =  array();
    $upgrades         = array();
    die(json_encode(mogambooo_upgrade_core($core_upgrade)));
}

/**
 * Upgrades WordPress locally
 */
function mogambooo_upgrade_core($current)
{
    ob_start();

    if (file_exists(ABSPATH.'/wp-admin/includes/update.php')) {
        include_once ABSPATH.'/wp-admin/includes/update.php';
    }

    $current_update = false;
    ob_end_flush();
    ob_end_clean();
    $core = mogambooo_get_transient('update_core');
    if (isset($core->updates) && !empty($core->updates)) {
        $updates = $core->updates[0];
        $updated = $core->updates[0];
        if (!isset($updated->response) || $updated->response == 'latest') {
            return array(
                'upgraded' => ' updated',
            );
        }
        $current_update = $updated;
    }
    if ($current_update != false) {
        global $wp_filesystem, $wp_version;

        if (version_compare($wp_version, '3.1.9', '>')) {
            if (!class_exists('Core_Upgrader')) {
                include_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
            }

            /** @handled class */
            $core   = new Core_Upgrader();
            $result = $core->upgrade($current_update);
            mogambooo_maintenance_mode(false);
            if (is_wp_error($result)) {
                return array(
                    'error' => mogambooo_get_error($result),
                );
            } else {
                return array(
                    'upgraded' => ' updated',
                );
            }
        } else {
            if (!class_exists('WP_Upgrader')) {
                include_once ABSPATH.'wp-admin/includes/update.php';
                if (function_exists('wp_update_core')) {
                    $result = wp_update_core($current_update);
                    if (is_wp_error($result)) {
                        return array(
                            'error' => mogambooo_get_error($result),
                        );
                    } else {
                        return array(
                            'upgraded' => ' updated',
                        );
                    }
                }
            }

            if (class_exists('WP_Upgrader')) {
                /** @handled class */
                $upgrader_skin              = new WP_Upgrader_Skin();
                $upgrader_skin->done_header = true;

                /** @handled class */
                $upgrader = new WP_Upgrader($upgrader_skin);

                // Is an update available?
                if (!isset($current_update->response) || $current_update->response == 'latest') {
                    return array(
                        'upgraded' => ' updated',
                    );
                }

                $res = $upgrader->fs_connect(
                    array(
                        ABSPATH,
                        WP_CONTENT_DIR,
                    )
                );
                if (is_wp_error($res)) {
                    return array(
                        'error' => mogambooo_get_error($res),
                    );
                }

                $wp_dir = trailingslashit($wp_filesystem->abspath());

                $core_package = false;
                if (isset($current_update->package) && !empty($current_update->package)) {
                    $core_package = $current_update->package;
                } elseif (isset($current_update->packages->full) && !empty($current_update->packages->full)) {
                    $core_package = $current_update->packages->full;
                }

                $download = $upgrader->download_package($core_package);
                if (is_wp_error($download)) {
                    return array(
                        'error' => mogambooo_get_error($download),
                    );
                }

                $working_dir = $upgrader->unpack_package($download);
                if (is_wp_error($working_dir)) {
                    return array(
                        'error' => mogambooo_get_error($working_dir),
                    );
                }

                if (!$wp_filesystem->copy($working_dir.'/wordpress/wp-admin/includes/update-core.php', $wp_dir.'wp-admin/includes/update-core.php', true)) {
                    $wp_filesystem->delete($working_dir, true);

                    return array(
                        'error' => 'Unable to move update files.',
                    );
                }

                $wp_filesystem->chmod($wp_dir.'wp-admin/includes/update-core.php', FS_CHMOD_FILE);

                require ABSPATH.'wp-admin/includes/update-core.php';

                $update_core = update_core($working_dir, $wp_dir);
                ob_end_clean();

                if (is_wp_error($update_core)) {
                    return array(
                        'error' => mogambooo_get_error($update_core),
                    );
                }
                ob_end_flush();

                return array(
                    'upgraded' => 'updated',
                );
            } else {
                return array(
                    'error' => 'failed',
                );
            }
        }
    } else {
        return array(
            'error' => 'failed',
        );
    }
}

function mogambooo_maintenance_mode($enable = false, $maintenance_message = '')
{
    global $wp_filesystem;

    $maintenance_message .= '<?php $upgrading = '.time().'; ?>';

    $file = $wp_filesystem->abspath().'.maintenance';
    if ($enable) {
        $wp_filesystem->delete($file);
        $wp_filesystem->put_contents($file, $maintenance_message, FS_CHMOD_FILE);
    } else {
        $wp_filesystem->delete($file);
    }
}


?>