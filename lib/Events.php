<?php
// server receive functions

/*
 * Hook Actions
 */

add_action( 'wp_ajax_nopriv_do_studio98_backup', 'uploadStudio98AmazonDoBackupCallback' );
add_action( 'wp_ajax_nopriv_restore_studio98_backup', 'restoreStudio98BackupCallback' );


// End Hooks

/*
 * Callback Functions
 */

// Do backups
function uploadStudio98AmazonDoBackupCallback()
{
    validate_connection();

    $backup_file_name = date('d-m-Y');
    if(isset($_POST['do_backup_before_restore'], $_POST['backup_file_name']) and $_POST['do_backup_before_restore'] == 'yes') {
        $backup_file_name = sanitize_text_field($_POST['backup_file_name']);
    }

    $backup = new STDD_Backup();
    $filename = $backup->backup([
        'type' => 'full',
        'what' => 'full',
        'exclude' => ['wp-content/cache','wp-content/w3tc-cache'],
        ], $backup_file_name);

    $app_name       = (isset($_POST['app_name']))       ? sanitize_text_field($_POST['app_name']) : '';
    $app_bucket     = (isset($_POST['app_bucket']))     ? sanitize_text_field($_POST['app_bucket']) : '';
    $public_key     = (isset($_POST['app_public_key'])) ? sanitize_text_field($_POST['app_public_key']) : '';
    $secure_key     = (isset($_POST['app_secure_key'])) ? sanitize_text_field($_POST['app_secure_key']) : '';
    $folder_name    = (isset($_POST['app_folder_name']))? sanitize_text_field($_POST['app_folder_name']) : '';
    $hash           = (isset($_POST['app_hash']))       ? sanitize_text_field($_POST['app_hash']) : '';


    if($app_name == ''
            || $app_bucket == ''
            || $public_key == ''
            || $secure_key == ''
            || $folder_name == ''
            ) {
        @unlink($filename);
        die('Required Parameters are missing');
    }

    $mNumber     = date('m-Y');
    if(!empty($hash))
        $folder_name = $hash.'/'.$folder_name;

    $result = $backup->amazons3_backup([
        'as3_bucket' => $app_bucket,
        'as3_access_key' => $public_key,
        'as3_secure_key' => $secure_key,
        'as3_directory' => $folder_name,
        'as3_site_folder' => $app_name.'/'.$mNumber.'/'.date('d-m-Y'),
        'backup_file' => $filename,
    ]);
    if(isset($result['error'])) {
        die('Something Wrong with amazon');
    }
    @unlink($filename);
    if(isset($_POST['do_backup_before_restore'], $_POST['backup_file_name']) and $_POST['do_backup_before_restore'] == 'yes') {
        return 'Yup';
    }
    die('Done');
}

function restoreStudio98BackupCallback() {
    validate_connection();
    
    $backup = new STDD_Backup();

    $app_name       = (isset($_POST['app_name']))       ? sanitize_text_field($_POST['app_name']) : '';
    $app_bucket     = (isset($_POST['app_bucket']))     ? sanitize_text_field($_POST['app_bucket']) : '';
    $public_key     = (isset($_POST['app_public_key'])) ? sanitize_text_field($_POST['app_public_key']) : '';
    $secure_key     = (isset($_POST['app_secure_key'])) ? sanitize_text_field($_POST['app_secure_key']) : '';
    $file_name      = (isset($_POST['app_file']))      ? sanitize_text_field($_POST['app_file']) : '';
    $folder_name    = (isset($_POST['app_folder_name']))      ? sanitize_text_field($_POST['app_folder_name']) : '';
    $hash           = (isset($_POST['app_hash']))       ? sanitize_text_field($_POST['app_hash']) : '';

    if(isset($_POST['do_backup_before_restore']) and $_POST['do_backup_before_restore'] == 'yes') {
        uploadStudio98AmazonDoBackupCallback();
    }

    if($app_bucket == ''
        || $public_key == ''
        || $secure_key == ''
        || $file_name == ''
    ) {
        die('Required Parameters are missing');
    }
    $folder_name = $folder_name.'/'.$app_name;
    if(!empty($hash)) {
        $folder_name = $hash.'/'.$folder_name;
    }
    $file_name = $folder_name.'/'.$file_name;
    set_time_limit(0);
    ini_set('memory_limit', '-1');

    $backup->get_amazons3_backup([
        'as3_bucket' => $app_bucket,
        'as3_access_key' => $public_key,
        'as3_secure_key' => $secure_key,
        'file_name' => $file_name,
    ]);
    
    $zip_path = ABSPATH.'studio98_backup.zip';   
    try {
        $backup->restoreBackup($zip_path);
        die('Done');
    } catch (Exception $ex) {
        die('error');
    }    
}