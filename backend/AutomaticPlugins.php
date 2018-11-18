<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
add_action( 'wp_ajax_nopriv_grandcentral_list_plugins', 'automaticListPluginsCallback' );
add_action( 'wp_ajax_grandcentral_list_plugins', 'automaticListPluginsCallback' );
function automaticListPluginsCallback() 
{
    validate_connection();
    
    @wp_update_plugins();
    
    $current = mogambooo_get_transient('update_plugins');
    $upgradable_plugins = array();
    $other_plugins = array();
    
    if (!empty($current->response)) {
        if (!function_exists('get_plugin_data')) {
            include_once ABSPATH.'wp-admin/includes/plugin.php';
        }
        foreach ($current->response as $plugin_path => $plugin_data) {
            $data = get_plugin_data(WP_PLUGIN_DIR.'/'.$plugin_path, false, false);

            if (strlen($data['Name']) > 0 && strlen($data['Version']) > 0) {
                
                $active = (is_plugin_active( $plugin_path )) ? 1 : 0;
                $current->response[$plugin_path]->name        = $data['Name'];
                $current->response[$plugin_path]->description = addslashes($data['Description']);
                $current->response[$plugin_path]->old_version = $data['Version'];
                $current->response[$plugin_path]->file        = $plugin_path;
                $current->response[$plugin_path]->active        = $active;
                unset($current->response[$plugin_path]->upgrade_notice);
                $upgradable_plugins[] = $current->response[$plugin_path];
            }
        }
    }
    if (!empty($current->no_update)) {
        if (!function_exists('get_plugin_data')) {
            include_once ABSPATH.'wp-admin/includes/plugin.php';
        }
        foreach ($current->no_update as $plugin_path => $plugin_data) {
            $data = get_plugin_data(WP_PLUGIN_DIR.'/'.$plugin_path, false, false);

            if (strlen($data['Name']) > 0 && strlen($data['Version']) > 0) {
                $active = (is_plugin_active( $plugin_path )) ? 1 : 0;
                $current->no_update[$plugin_path]->name        = $data['Name'];
                $current->no_update[$plugin_path]->description = addslashes($data['Description']);
                $current->no_update[$plugin_path]->old_version = $data['Version'];
                $current->no_update[$plugin_path]->file        = $plugin_path;
                $current->no_update[$plugin_path]->active        = $active;
                unset($current->no_update[$plugin_path]->upgrade_notice);
                $other_plugins[] = $current->no_update[$plugin_path];
            }
        }
    }
    $listting['upgradeable'] = $upgradable_plugins;
    $listting['no_update']   = $other_plugins;
    die(json_encode($listting));
}
add_action( 'wp_ajax_nopriv_grandcentral_update_plugin', 'automaticUpdatePluginsCallback' );
add_action( 'wp_ajax_grandcentral_update_plugin', 'automaticUpdatePluginsCallback' );
function automaticUpdatePluginsCallback()
{
    validate_connection();   
    
    if (!mogambooo_is_server_writable()) {
        return array(
            'error' => 'Failed, please Server is not writeable.',
        );
    }
    
    $plugins = (!isset($_POST['plugins'])) ? '' : sanitize_text_field($_POST['plugins']);
    $plugins = explode(',', $plugins);
    

    if (!function_exists('wp_update_plugins')) {
        include_once ABSPATH.'wp-includes/update.php';
    }
    if (!class_exists('Plugin_Upgrader')) {
        @include_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        @include_once ABSPATH.'wp-admin/includes/class-plugin-upgrader.php';
    }

    $return = array();
    if (class_exists('Plugin_Upgrader')) 
    {
        /** @handled class */
        
        $upgrader = new Plugin_Upgrader();
        $result   = $upgrader->bulk_upgrade($plugins);
        @wp_update_plugins();
        if (!empty($result)) {
            foreach ($result as $plugin_slug => $plugin_info) {
                if (!$plugin_info || is_wp_error($plugin_info)) {
                    $return[$plugin_slug] = mogambooo_get_error($plugin_info);
                    continue;
                }

                $return[$plugin_slug] = 1;
            }
            die();
        } else {
            die('Upgrade failed.');
        }
    } else {
        die('WordPress update required first.');
    }
}
add_action( 'wp_ajax_nopriv_grandcentral_activate_plugin', 'automaticActivatePluginsCallback' );
add_action( 'wp_ajax_grandcentral_activate_plugin', 'automaticActivatePluginsCallback' );
function automaticActivatePluginsCallback()
{
    validate_connection();
    
    $plugin = (!isset($_POST['plugin'])) ? '' : sanitize_text_field($_POST['plugin']);
    if($plugin == ''){
        die('Nothing to activate');
    }

    if (!function_exists('activate_plugins')) {
        include_once ABSPATH.'wp-admin/includes/plugin.php';
    }

    $return = array();
    if (function_exists('activate_plugins')) 
    {
        $result = activate_plugins($plugin);        
        if(is_wp_error($result)) {
            die(mogambooo_get_error($result));
        }
        die('Plugin activated successfully.');
        
    } else {
        die('WordPress update required first.');
    }
}

add_action( 'wp_ajax_nopriv_grandcentral_deactivate_plugin', 'automaticDeactivatePluginsCallback' );
add_action( 'wp_ajax_grandcentral_deactivate_plugin', 'automaticDeactivatePluginsCallback' );
function automaticDeactivatePluginsCallback()
{
    validate_connection();  
    
    $plugin = (!isset($_POST['plugin'])) ? '' : sanitize_text_field($_POST['plugin']);
    if($plugin == ''){
        die('Nothing to deactivate');
    }

    if (!function_exists('deactivate_plugins')) {
        include_once ABSPATH.'wp-admin/includes/plugin.php';
    }

    $return = array();
    if (function_exists('deactivate_plugins')) 
    {        
        $result = deactivate_plugins($plugin); 
        if(is_wp_error($result)) {
            die(mogambooo_get_error($result));
        }
        die('Plugin deactivated successfully.');
        
    } else {
        die('WordPress update required first.');
    }
}
add_action( 'wp_ajax_nopriv_grandcentral_delete_plugin', 'automaticDeletePluginsCallback' );
add_action( 'wp_ajax_grandcentral_delete_plugin', 'automaticDeletePluginsCallback' );
function automaticDeletePluginsCallback()
{
    validate_connection();  
    
    $plugin = (!isset($_POST['plugin'])) ? '' : sanitize_text_field($_POST['plugin']);
    if($plugin == ''){
        die('Nothing to delete');
    }

    if (!function_exists('delete_plugins')) {
        include_once ABSPATH.'wp-admin/includes/plugin.php';
    }

    $return = array();
    if (function_exists('delete_plugins')) 
    {        
        $result = delete_plugins([$plugin]); 
        if(is_wp_error($result)) {
            die(mogambooo_get_error($result));
        }
        die('Plugin deleted successfully.');
        
    } else {
        die('WordPress update required first.');
    }
}