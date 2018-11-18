<?php

class STD_Updater
{
    public function __construct()
    {
    }

    public static function isSupported()
    {
        global $wp_version;

        if (version_compare($wp_version, '3.7', '<')) {
            return false;
        }

        return true;
    }

    public static function register()
    {
        if (!self::isSupported()) {
            return;
        }

        $updater = new self();
        $autoUpdatePlugin = get_option('std_plugin_autoupdate');
        
        if($autoUpdatePlugin == 'yes') {
            add_filter('auto_update_plugin', array($updater, 'updatePlugin'), PHP_INT_MAX, 2);            
        }
        
    }

    public function updatePlugin($update, $item)
    {
        $slug = $item->plugin;
        if ($slug === 'studio98-backup/studio98-backup.php') {
            return false;
        }
        
        return true;
    }
    public function updateTranslation($update)
    {
        return true;
    }
}