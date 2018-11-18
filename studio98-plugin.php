<?php
/*
Plugin Name: Studio98 Plugin
Description: Studio98 Plugin for backup, moniter, post exporter
Version:     0.1.2
Author:      Studio98.com
Author URI:  https://www.studio98.com
*/


if ( ! defined( 'ABSPATH' ) ) {
	die( 'Access denied.' );
}

if ( ! class_exists( 'Studio98_GrandCentral_Plugin' ) ) {
	class Studio98_GrandCentral_Plugin {		

		/**
		 * Constructor
		 */
		public function __construct() {
                    add_action( 'plugins_loaded', array( $this, 'init' ), 8 );
			
		}
                
		function init() {
				$this->includes();
		}
		function includes() { 
			define( 'GrandCentral_FILE_PATH', dirname( __FILE__ ) );
			define( 'GrandCentral_FILE_URL',  __FILE__  );
            define( 'GrandCentral_LOGO',  plugins_url('images/logo.png',GrandCentral_FILE_URL)  );

            define( 'GrandCentral_BACKUP_DIR', WP_CONTENT_DIR.'/studio98wp/backups');
            define( 'GrandCentral_DB_DIR', GrandCentral_BACKUP_DIR.'/swp_db');
			
			
			require_once( GrandCentral_FILE_PATH . '/functions.php' );
			require_once( GrandCentral_FILE_PATH . '/backend/settings.php' );
            require_once( GrandCentral_FILE_PATH . '/backend/AutomaticLogin.php' );
            require_once( GrandCentral_FILE_PATH . '/backend/AutomaticPlugins.php' );
            require_once( GrandCentral_FILE_PATH . '/backend/UpgradeCore.php' );


            require_once( GrandCentral_FILE_PATH . '/lib/Backup.php' );
            require_once( GrandCentral_FILE_PATH . '/lib/Events.php' );
            require_once( GrandCentral_FILE_PATH . '/lib/Upgrade.php' );

            // Register the autoloader that loads everything except the Google namespace.
            if (version_compare(PHP_VERSION, '5.3', '<')) {
                spl_autoload_register('swp_autoload');
            } else {
                // The prepend parameter was added in PHP 5.3.0
                spl_autoload_register('swp_autoload', true, true);
            }

            $backup = new STDD_Backup();

		}
		public function Studio98ActivePlugin() {
                    
                    if (!get_option( '_grandcentral_ID' )) {
                        add_option( '_grandcentral_ID', '', '', 'no' );
                    } 
                    if (!get_option( '_grandcentral_website' )) {
                        add_option( '_grandcentral_website', '', '', 'no' );
                    } 
			
		}
		public function Studio98DeactivePlugin() {                    
                    delete_option( '_grandcentral_ID' );
                    delete_option( '_grandcentral_website' ); 
		}

	} // end Studio98_GrandCentral_Plugin
        
$grandCentral = new Studio98_GrandCentral_Plugin();

// Activation
register_activation_hook( __FILE__, array( $grandCentral,'Studio98ActivePlugin'));
register_deactivation_hook( __FILE__, array( $grandCentral,'Studio98DeactivePlugin' ));
}
?>