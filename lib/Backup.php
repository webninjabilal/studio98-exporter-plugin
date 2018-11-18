<?php

/**
 * The main class for processing database and full backups on Studio98 Backup.
 *
 * @subpackage    backup
 */
class STDD_Backup
{

    public $site_name;

    public $tasks;

    public $s3;
    public function __construct()
    {
        
        $this->site_name = str_replace(array("_", "/", "~", ":"), array("", "-", "-", "-"), rtrim(std_remove_http(get_bloginfo('url')), "/"));
    }
    public function set_memory()
    {
        $changed = array('execution_time' => 0, 'memory_limit' => 0);
        ignore_user_abort(true);
        $tryLimit = 384;

        $limit = ini_get('memory_limit');

        $matched = preg_match('/^(\d+) ([KMG]?B)$/', $limit, $match);

        if ($matched
            && (
                ($match[2] === 'GB')
                || ($match[2] === 'MB' && (int) $match[1] >= $tryLimit)
            )
        ) {
            // Memory limits are satisfied.
        } else {
            ini_set('memory_limit', $tryLimit.'M');
            $changed['memory_limit'] = 1;
        }
        if (!std_is_safe_mode() && ((int) ini_get('max_execution_time') < 4000) && (ini_get('max_execution_time') !== '0')) {
            ini_set('max_execution_time', 4000);
            set_time_limit(4000);
            $changed['execution_time'] = 1;
        }

        return $changed;
    }

    /**
     * Backup a full wordpress instance, including a database dump, which is placed in swp_db dir in root folder.
     * All backups are compressed by zip and placed in wp-content/studio98wp/backups folder.
     *
     * @param string $args                     arguments passed from master
     *                                         [type] -> db, full
     *                                         [what] -> daily, weekly, monthly
     *                                         [account_info] -> remote destinations ftp, amazons3, dropbox, google_drive, email with their parameters
     *                                         [include] -> array of folders from site root which are included to backup (wp-admin, wp-content, wp-includes are default)
     *                                         [exclude] -> array of files of folders to exclude, relative to site's root
     * @param bool|string[optional] $task_name the name of backup task, which backup is done (default: false)
     * @param bool   $resultUuid               unique identifier for backup result
     *
     * @return bool|array false if $args are missing, array with error if error has occured, ture if is successful
     */
    public function backup($args, $file_name, $task_name = false, $resultUuid = false)
    {
        if (!$args || empty($args)) {
            return false;
        }
        extract($args); //extract settings

        //Try increase memory limit	and execution time
        $this->set_memory();

        $new_file_path = GrandCentral_BACKUP_DIR;

        if (!file_exists($new_file_path)) {
            if (!mkdir($new_file_path, 0755, true)) {
                return array(
                    'error' => 'Permission denied, make sure you have write permissions to the wp-content folder.',
                );
            }
        }

        @file_put_contents($new_file_path.'/index.php', ''); //safe

        //Prepare .zip file name
        $backup_file = $new_file_path.'/'.$file_name.'.zip';
        if(is_file($backup_file))
            return $backup_file;
        $backup_url  = WP_CONTENT_URL.'/studio98wp/backups/'.$file_name.'.zip';
		

        $begin_compress = microtime(true);

        //Optimize tables?
        if (isset($optimize_tables) && !empty($optimize_tables)) {
            $this->optimize_tables();
        }

        
        if (!isset($exclude) or !$exclude) {
            $exclude = array();
        }
        if (!isset($include) or !$include) {
            $include = array();
        }

        $content_backup = $this->backup_full($task_name, $backup_file, $exclude, $include);

        if (is_array($content_backup) && array_key_exists('error', $content_backup)) {
            $error_message = $content_backup['error'];
            die($error_message);
            return array(
                'error' => $error_message,
            );
        }
		$end_compress = microtime(true);
		
		$paths           = array();
		$size            = ceil(filesize($backup_file) / 1024);
		$duration        = round($end_compress - $begin_compress, 2);

        
        return $backup_file;
    }

    /**
     * Backup a full wordpress instance, including a database dump, which is placed in swp_db dir in root folder.
     * All backups are compressed by zip and placed in wp-content/studio98wp/backups folder.
     *
     * @param string $task_name   the name of backup task, which backup is done
     * @param string $backup_file relative path to file which backup is stored
     * @param        array        [optional] $exclude     the list of files and folders, which are excluded from backup (default: array())
     * @param        array        [optional] $include     the list of folders in wordpress root which are included to backup, expect wp-admin, wp-content, wp-includes, which are default (default: array())
     *
     * @return bool|array true if backup is successful, or an array with error message if is failed
     */
    public function backup_full($task_name, $backup_file, $exclude = array(), $include = array())
    {
        $db_result = $this->backup_db();

        if ($db_result == false) {
            return array(
                'error' => 'Failed to backup database.',
            );
        } else {
            if (is_array($db_result) && isset($db_result['error'])) {
                return array(
                    'error' => $db_result['error'],
                );
            }
        }


        @file_put_contents(GrandCentral_BACKUP_DIR.'/swp_db/index.php', '');
        $zip_db_result = false;//$this->zip_backup_db($task_name, $backup_file);
        
        if (!$zip_db_result) {
            $zip_archive_db_result = false;
            if (class_exists("ZipArchive")) {               
                $zip_archive_db_result = $this->zip_archive_backup_db($task_name, $db_result, $backup_file);
            }

            if (!$zip_archive_db_result) {
                
                $pclzip_db_result = $this->pclzip_backup_db($task_name, $backup_file);
                if (!$pclzip_db_result) {
                    @unlink(GrandCentral_BACKUP_DIR.'/swp_db/index.php');
                    @unlink(GrandCentral_BACKUP_DIR.'/swp_db/info.json');
                    @unlink($db_result);
                    @rmdir(GrandCentral_DB_DIR);

                    if ($archive->error_code != '') {
                        $archive->error_code = 'pclZip error ('.$archive->error_code.'): .';
                    }

                    return array(
                        'error' => 'Failed to zip database. '.$archive->error_code.$archive->error_string,
                    );
                }
            }
        }

        @unlink(GrandCentral_BACKUP_DIR.'/swp_db/index.php');
        @unlink(GrandCentral_BACKUP_DIR.'/swp_db/info.json');
        @unlink($db_result);
        @rmdir(GrandCentral_DB_DIR);

        $remove  = array(
            trim(basename(WP_CONTENT_DIR))."/managewp/backups",
            trim(basename(WP_CONTENT_DIR))."/infinitewp/backups",
            trim(basename(WP_CONTENT_DIR))."/".md5('mmb-worker')."/mwp_backups",
            trim(basename(WP_CONTENT_DIR))."/backupwordpress",
            trim(basename(WP_CONTENT_DIR))."/contents/cache",
            trim(basename(WP_CONTENT_DIR))."/content/cache",
            trim(basename(WP_CONTENT_DIR))."/cache",
            trim(basename(WP_CONTENT_DIR))."/old-cache",
            trim(basename(WP_CONTENT_DIR))."/uploads/backupbuddy_backups",
            trim(basename(WP_CONTENT_DIR))."/w3tc",
            trim(basename(WP_CONTENT_DIR))."/cmscommander/backups",
            trim(basename(WP_CONTENT_DIR))."/gt-cache",
            trim(basename(WP_CONTENT_DIR))."/wfcache",
            trim(basename(WP_CONTENT_DIR))."/bps-backup",
            trim(basename(WP_CONTENT_DIR))."/old-cache",
            trim(basename(WP_CONTENT_DIR))."/updraft",
            trim(basename(WP_CONTENT_DIR))."/nfwlog/cache",
            trim(basename(WP_CONTENT_DIR))."/upgrade",
            trim(basename(WP_CONTENT_DIR))."/wishlist-backup",
            trim(basename(WP_CONTENT_DIR))."/wptouch-data/infinity-cache/",
            trim(basename(WP_CONTENT_DIR)."/".basename($uploadDir['basedir']))."/ithemes-security/backups",
            trim(basename(WP_CONTENT_DIR)."/".basename($uploadDir['basedir']))."/mainwp/backup",
            trim(basename(WP_CONTENT_DIR)."/".basename($uploadDir['basedir']))."/sucuri",
            trim(basename(WP_CONTENT_DIR)."/".basename($uploadDir['basedir']))."/aiowps_backups",
            trim(basename(WP_CONTENT_DIR)."/".basename($uploadDir['basedir']))."/gravity_forms",
            trim(basename(WP_CONTENT_DIR)."/".basename($uploadDir['basedir']))."/mainwp",
            trim(basename(WP_CONTENT_DIR)."/".basename($uploadDir['basedir']))."/snapshots",
            trim(basename(WP_CONTENT_DIR)."/".basename($uploadDir['basedir']))."/wp-clone",
            trim(basename(WP_CONTENT_DIR)."/".basename($uploadDir['basedir']))."/wp-clone",
            trim(basename(WP_CONTENT_DIR)."/".basename($uploadDir['basedir']))."/wp_system",
            trim(basename(WP_CONTENT_DIR)."/".basename($uploadDir['basedir']))."/wpcf7_captcha",
            trim(basename(WP_CONTENT_DIR)."/".basename($uploadDir['basedir']))."/wc-logs",
            trim(basename(WP_CONTENT_DIR)."/".basename($uploadDir['basedir']))."/pb_backupbuddy",
            trim(basename(WP_CONTENT_DIR))."/mysql.sql",
            "error_log",
            "error.log",
            "debug.log",
            "WS_FTP.LOG",
            "security.log",
            "dbcache",
            "pgcache",
            "objectcache",
        );
        $exclude = array_merge($exclude, $remove);

        if (function_exists('proc_open') && $this->zipExists()) {
            $zip_result = $this->zip_backup($task_name, $backup_file, $exclude, $include);
        } else {
            $zip_result = false;
        }

        if (isset($zip_result['error'])) {
            return $zip_result;
        }

        if (!$zip_result) {
            $zip_archive_result = false;
            if (class_exists("ZipArchive")) {                
                $zip_archive_result = $this->zip_archive_backup($task_name, $backup_file, $exclude, $include);
            }

            if (!$zip_archive_result) {
                $pclzip_result = $this->pclzip_backup($task_name, $backup_file, $exclude, $include);
                if (!$pclzip_result) {
                    @unlink(GrandCentral_BACKUP_DIR.'/swp_db/index.php');
                    @unlink($db_result);
                    @rmdir(GrandCentral_DB_DIR);

                    if (!$pclzip_result) {
                        @unlink($backup_file);

                        return array(
                            'error' => 'Failed to zip files. pclZip error ('.$archive->error_code.'): .'.$archive->error_string,
                        );
                    }
                }
            }
        }

        return true;
    }

    /**
     * Zipping database dump and index.php in folder swp_db by system zip command, requires zip installed on OS.
     *
     * @param string $taskName   the name of backup task
     * @param string $backupFile absolute path to zip file
     *
     * @return bool is compress successful or not
     * @todo report errors back to the user
     * @todo report error if DB dump is not found
     */
    public function zip_backup_db($taskName, $backupFile)
    {

        $compressionLevel = 6;
        $zipFinder = new Symfony_Process_ExecutableFinder ();

        $zip = $zipFinder->find('zip', 'zip');

        $processBuilder = Symfony_Process_ProcessBuilder::create()
            ->setWorkingDirectory(untrailingslashit(GrandCentral_BACKUP_DIR))
            ->setTimeout(3600)
            ->setPrefix($zip)
            ->add('-q')// quiet operation
            ->add('-r')// recurse paths, include files in subdirs:  zip -r a path path ...
            ->add($compressionLevel)
            ->add($backupFile)// zipfile to write to
            ->add('swp_db') // file/directory list
        ;

        try {
            
            $process = $processBuilder->getProcess();
            
            $process->start();
            while ($process->isRunning()) {
                sleep(1);
                echo ".";
                flush();
               
            }

            if (!$process->isSuccessful()) {
                throw new Symfony_Process_Exception_ProcessFailedException($process);
            }
           

            return true;
        } catch (Symfony_Process_Exception_ProcessFailedException $e) {
            
        } catch (Exception $e) {
            
        }

        return false;
    }

    /**
     * Zipping database dump and index.php in folder swp_db by ZipArchive class, requires php zip extension.
     *
     * @param string $task_name   the name of backup task
     * @param string $db_result   relative path to database dump file
     * @param string $backup_file absolute path to zip file
     *
     * @return bool is compress successful or not
     */
    public function zip_archive_backup_db($task_name, $db_result, $backup_file)
    {
        $disable_comp = $this->tasks[$task_name]['task_args']['disable_comp'];
        $zip          = new ZipArchive();
        $result       = $zip->open($backup_file, ZIPARCHIVE::CREATE); // Tries to open $backup_file for acrhiving
        if ($result === true) {
            $result = $result && $zip->addFile(GrandCentral_BACKUP_DIR.'/swp_db/index.php', "swp_db/index.php"); // Tries to add swp_db/index.php to $backup_file
            $result = $result && $zip->addFile($db_result, "swp_db/".basename($db_result)); // Tries to add db dump form swp_db dir to $backup_file
            $result = $result && $zip->close(); // Tries to close $backup_file
        } else {
            $result = false;
        }

        return $result; // true if $backup_file iz zipped successfully, false if error is occured in zip process
    }

    /**
     * Zipping database dump and index.php in folder swp_db by PclZip library.
     *
     * @param string $task_name   the name of backup task
     * @param string $backup_file absolute path to zip file
     *
     * @return bool is compress successful or not
     */
    public function pclzip_backup_db($task_name, $backup_file)
    {
        $disable_comp = $this->tasks[$task_name]['task_args']['disable_comp'];
        define('PCLZIP_TEMPORARY_DIR', GrandCentral_BACKUP_DIR.'/');
        require_once ABSPATH.'/wp-admin/includes/class-pclzip.php';
        $zip = new PclZip($backup_file);

        if ($disable_comp) {
            $result = $zip->add(GrandCentral_BACKUP_DIR."/swp_db/", PCLZIP_OPT_REMOVE_PATH, GrandCentral_BACKUP_DIR, PCLZIP_OPT_NO_COMPRESSION) !== 0;
        } else {
            $result = $zip->add(GrandCentral_BACKUP_DIR."/swp_db/", PCLZIP_OPT_REMOVE_PATH, GrandCentral_BACKUP_DIR) !== 0;
        }

        return $result;
    }

    /**
     * Zipping whole site root folder and append to backup file with database dump
     * by system zip command, requires zip installed on OS.
     *
     * @param string $task_name  the name of backup task
     * @param string $backupFile absolute path to zip file
     * @param array  $exclude    array of files of folders to exclude, relative to site's root
     * @param array  $include    array of folders from site root which are included to backup (wp-admin, wp-content, wp-includes are default)
     *
     * @return array|bool true if successful or an array with error message if not
     */
    public function zip_backup($task_name, $backupFile, $exclude, $include)
    {
        $compressionLevel = $this->tasks[$task_name]['task_args']['disable_comp'] ? 0 : 1;

        try {
            $this->backupRootFiles($compressionLevel, $backupFile, $exclude);
        } catch (Exception $e) {
            return array(
                'error' => $e->getMessage(),
            );
        }
        try {
            $this->backupDirectories($compressionLevel, $backupFile, $exclude, $include);
        } catch (Exception $e) {
            return array(
                'error' => $e->getMessage(),
            );
        }

        return true;
    }

    private function backupRootFiles($compressionLevel, $backupFile, $exclude)
    {
        $zipFinder = new Symfony_Process_ExecutableFinder ();
        $zip            = $zipFinder->find('zip', 'zip');
        $arguments      = array($zip, '-q', '-j', '-'.$compressionLevel, $backupFile);
        $fileExclusions = array('../', 'error_log');
        foreach ($exclude as $exclusion) {
            if (is_file(ABSPATH.$exclusion)) {
                $fileExclusions[] = $exclusion;
            }
        }

        $parentWpConfig = '';
        if (!file_exists(ABSPATH.'wp-config.php')
            && file_exists(dirname(ABSPATH).'/wp-config.php')
            && !file_exists(dirname(ABSPATH).'/wp-settings.php')
        ) {
            $parentWpConfig = '../wp-config.php';
        }

        $command = implode(' ', array_map(array('Symfony_Process_ProcessUtils', 'escapeArgument'), $arguments))." .* ./* $parentWpConfig";

        if ($fileExclusions) {
            $command .= ' '.implode(' ', array_map(array('Symfony_Process_ProcessUtils', 'escapeArgument'), array_merge(array('-x'), $fileExclusions)));
        }

        try {
            
            $process = new Symfony_Process_Process($command, untrailingslashit(ABSPATH), null, null, 3600);
            
            $process->start();
            while ($process->isRunning()) {
                sleep(1);
                echo ".";
                flush();
            }

            if ($process->isSuccessful()) {
                
            } elseif ($process->getExitCode() === 18) {
                
            } else {
                throw new Symfony_Process_Exception_ProcessFailedException($process);
            }
        } catch (Symfony_Process_Exception_ProcessFailedException $e) {
            
            throw $e;
        } catch (Exception $e) {
            
            throw $e;
        }
    }

    private function backupDirectories($compressionLevel, $backupFile, $exclude, $include)
    {
        $zipFinder = new Symfony_Process_ExecutableFinder ();
        $zip = $zipFinder->find('zip', 'zip');

        $processBuilder = Symfony_Process_ProcessBuilder::create()
            ->setWorkingDirectory(untrailingslashit(ABSPATH))
            ->setTimeout(3600)
            ->setPrefix($zip)
            ->add('-q')
            ->add('-r')
            ->add('-'.$compressionLevel)
            ->add($backupFile)
            ->add('.');

        $uploadDir = wp_upload_dir();

        $inclusions = array(
            WPINC,
            basename(WP_CONTENT_DIR),
            'wp-admin',
        );

        $path = wp_upload_dir();
        $path = $path['path'];
        if (strpos($path, WP_CONTENT_DIR) === false && strpos($path, ABSPATH) === 0) {
            $inclusions[] = ltrim(substr($path, strlen(ABSPATH)), ' /');
        }

        $include = array_merge($include, $inclusions);
        $include = array_map('untrailingslashit', $include);
        foreach ($include as $inclusion) {
            if (is_dir(ABSPATH.$inclusion)) {
                $inclusions[] = $inclusion.'/*';
            } else {
                $inclusions[] = $inclusion;
            }
        }

        $processBuilder->add('-i');
        foreach ($inclusions as $inclusion) {
            $processBuilder->add($inclusion);
        }

        $exclusions = array();
        $exclude    = array_map('untrailingslashit', $exclude);
        foreach ($exclude as $exclusion) {
            if (is_dir(ABSPATH.$exclusion)) {
                $exclusions[] = $exclusion.'/*';
            } else {
                $exclusions[] = $exclusion;
            }
        }

        if ($exclusions) {
            $processBuilder->add('-x');
            foreach ($exclusions as $exclusion) {
                $processBuilder->add($exclusion);
            }
        }

        try {
            
            $process = $processBuilder->getProcess();
            
            $process->start();
            while ($process->isRunning()) {
                sleep(1);
                echo ".";
                flush();
            }

            if ($process->isSuccessful()) {
                
            } elseif ($process->getExitCode() === 18) {
                
            } else {
                throw new Symfony_Process_Exception_ProcessFailedException($process);
            }
        } catch (Symfony_Process_Exception_ProcessFailedException $e) {
            
            throw $e;
        } catch (Exception $e) {
            
            throw $e;
        }
    }

    /**
     * Zipping whole site root folder and append to backup file with database dump
     * by ZipArchive class, requires php zip extension.
     *
     * @param string $task_name   the name of backup task
     * @param string $backup_file absolute path to zip file
     * @param array  $exclude     array of files of folders to exclude, relative to site's root
     * @param array  $include     array of folders from site root which are included to backup (wp-admin, wp-content, wp-includes are default)
     *
     * @return array|bool true if successful or an array with error message if not
     */
    public function zip_archive_backup($task_name, $backup_file, $exclude, $include, $overwrite = false)
    {
        $filelist     = $this->get_backup_files($exclude, $include);
        $disable_comp = true;

        $zip = new ZipArchive();
        if ($overwrite) {
            $result = $zip->open($backup_file, ZipArchive::OVERWRITE); // Tries to open $backup_file for archiving
        } else {
            $result = $zip->open($backup_file); // Tries to open $backup_file for archiving
        }
        if ($result === true) {
            foreach ($filelist as $file) {
                $pathInZip = strpos($file, ABSPATH) === false ? basename($file) : str_replace(ABSPATH, '', $file);
                $result    = $result && $zip->addFile($file, $pathInZip); // Tries to add a new file to $backup_file
            }
            $result = $result && $zip->close(); // Tries to close $backup_file
        } else {
            $result = false;
        }

        return $result; // true if $backup_file iz zipped successfully, false if error is occured in zip process
    }

    /**
     * Zipping whole site root folder and append to backup file with database dump
     * by PclZip library.
     *
     * @param string $task_name   the name of backup task
     * @param string $backup_file absolute path to zip file
     * @param array  $exclude     array of files of folders to exclude, relative to site's root
     * @param array  $include     array of folders from site root which are included to backup (wp-admin, wp-content, wp-includes are default)
     *
     * @return array|bool true if successful or an array with error message if not
     */
    public function pclzip_backup($task_name, $backup_file, $exclude, $include)
    {
        define('PCLZIP_TEMPORARY_DIR', GrandCentral_BACKUP_DIR.'/');
        require_once ABSPATH.'/wp-admin/includes/class-pclzip.php';
        $zip = new PclZip($backup_file);
        $add = array(
            trim(WPINC),
            trim(basename(WP_CONTENT_DIR)),
            'wp-admin',
        );

        if (!file_exists(ABSPATH.'wp-config.php')
            && file_exists(dirname(ABSPATH).'/wp-config.php')
            && !file_exists(dirname(ABSPATH).'/wp-settings.php')
        ) {
            $include[] = '../wp-config.php';
        }

        $path = wp_upload_dir();
        $path = $path['path'];
        if (strpos($path, WP_CONTENT_DIR) === false && strpos($path, ABSPATH) === 0) {
            $add[] = ltrim(substr($path, strlen(ABSPATH)), ' /');
        }

        $include_data = array();
        if (!empty($include)) {
            foreach ($include as $data) {
                if ($data && file_exists(ABSPATH.$data)) {
                    $include_data[] = ABSPATH.$data.'/';
                }
            }
        }
        $include_data = array_merge($add, $include_data);

        if ($handle = opendir(ABSPATH)) {
            while (false !== ($file = readdir($handle))) {
                if ($file != "." && $file != ".." && !is_dir($file) && file_exists(ABSPATH.$file)) {
                    $include_data[] = ABSPATH.$file;
                }
            }
            closedir($handle);
        }

        $disable_comp = $this->tasks[$task_name]['task_args']['disable_comp'];

        if ($disable_comp) {
            $result = $zip->add($include_data, PCLZIP_OPT_REMOVE_PATH, ABSPATH, PCLZIP_OPT_NO_COMPRESSION) !== 0;
        } else {
            $result = $zip->add($include_data, PCLZIP_OPT_REMOVE_PATH, ABSPATH) !== 0;
        }

        $exclude_data = array();
        if (!empty($exclude)) {
            foreach ($exclude as $data) {
                if (file_exists(ABSPATH.$data)) {
                    if (is_dir(ABSPATH.$data)) {
                        $exclude_data[] = $data.'/';
                    } else {
                        $exclude_data[] = $data;
                    }
                }
            }
        }
        $result = $result && $zip->delete(PCLZIP_OPT_BY_NAME, $exclude_data);

        return $result;
    }

    /**
     * Gets an array of relative paths of all files in site root recursively.
     * By default, there are all files from root folder, all files from folders wp-admin, wp-content, wp-includes recursively.
     * Parameter $include adds other folders from site root, and excludes any file or folder by relative path to site's root.
     *
     * @param array $exclude array of files of folders to exclude, relative to site's root
     * @param array $include array of folders from site root which are included to backup (wp-admin, wp-content, wp-includes are default)
     *
     * @return array array with all files in site root dir
     */
    public function get_backup_files($exclude, $include)
    {
        $add = array(
            trim(WPINC),
            trim(basename(WP_CONTENT_DIR)),
            "wp-admin",
        );

        $include = array_merge($add, $include);
        foreach ($include as &$value) {
            $value = rtrim($value, '/');
        }

        $filelist = array();
        if ($handle = opendir(ABSPATH)) {
            while (false !== ($file = readdir($handle))) {
                if ($file !== '..' && is_dir($file) && file_exists(ABSPATH.$file) && !(in_array($file, $include))) {
                    $exclude[] = $file;
                }
            }
            closedir($handle);
        }
        $exclude[] = 'error_log';

        $filelist = get_all_files_from_dir(ABSPATH, $exclude);

        if (!file_exists(ABSPATH.'wp-config.php')
            && file_exists(dirname(ABSPATH).'/wp-config.php')
            && !file_exists(dirname(ABSPATH).'/wp-settings.php')
        ) {
            $filelist[] = dirname(ABSPATH).'/wp-config.php';
        }

        $path = wp_upload_dir();
        $path = $path['path'];
        if (strpos($path, WP_CONTENT_DIR) === false && strpos($path, ABSPATH) === 0) {
            $mediaDir = ABSPATH.ltrim(substr($path, strlen(ABSPATH)), ' /');
            if (is_dir($mediaDir)) {
                $allMediaFiles = get_all_files_from_dir($mediaDir);
                $filelist      = array_merge($filelist, $allMediaFiles);
            }
        }

        return $filelist;
    }

    /**
     * Backup a database dump of WordPress site.
     * All backups are compressed by zip and placed in wp-content/studio98wp/backups folder.
     *
     * @param string $task_name   the name of backup task, which backup is done
     * @param string $backup_file relative path to file which backup is stored
     *
     * @return bool|array true if backup is successful, or an array with error message if is failed
     */
    public function backup_db_compress($task_name, $backup_file)
    {
        $db_result = $this->backup_db();

        if ($db_result == false) {
            return array(
                'error' => 'Failed to backup database.',
            );
        } else {
            if (is_array($db_result) && isset($db_result['error'])) {
                return array(
                    'error' => $db_result['error'],
                );
            }
        }

        
        @file_put_contents(GrandCentral_BACKUP_DIR.'/swp_db/index.php', '');
        $zip_db_result = $this->zip_backup_db($task_name, $backup_file);

        if (!$zip_db_result) {
            $zip_archive_db_result = false;
            if (class_exists("ZipArchive")) {
                $zip_archive_db_result = $this->zip_archive_backup_db($task_name, $db_result, $backup_file);
            }

            if (!$zip_archive_db_result) {
                $pclzip_db_result = $this->pclzip_backup_db($task_name, $backup_file);
                if (!$pclzip_db_result) {
                    @unlink(GrandCentral_BACKUP_DIR.'/swp_db/index.php');
                    @unlink($db_result);
                    @rmdir(GrandCentral_DB_DIR);

                    return array(
                        'error' => 'Failed to zip database. pclZip error ('.$archive->error_code.'): .'.$archive->error_string,
                    );
                }
            }
        }

        @unlink(GrandCentral_BACKUP_DIR.'/swp_db/index.php');
        @unlink($db_result);
        @rmdir(GrandCentral_DB_DIR);


        return true;
    }

    /**
     * Creates database dump and places it in swp_db folder in site's root.
     * This function dispatches if OS mysql command does not work calls a php alternative.
     *
     * @return string|array path to dump file if successful, or an array with error message if is failed
     */
    public function backup_db()
    {
        $db_folder = GrandCentral_DB_DIR.'/';
        if (!file_exists($db_folder)) {
            if (!mkdir($db_folder, 0755, true)) {
                return array(
                    'error' => 'Error creating database backup folder ('.$db_folder.'). Make sure you have correct write permissions.',
                );
            }
        }

        $file   = $db_folder.DB_NAME.'.sql';
        $result = $this->backup_db_dump($file); // try mysqldump always then fallback to php dump
        return $result;
    }

    public function file_get_size($file)
    {
        if (!extension_loaded('bcmath')) {
            return filesize($file);
        }

        //open file
        $fh = fopen($file, "r");
        //declare some variables
        $size = "0";
        $char = "";
        //set file pointer to 0; I'm a little bit paranoid, you can remove this
        fseek($fh, 0, SEEK_SET);
        //set multiplicator to zero
        $count = 0;
        while (true) {
            //jump 1 MB forward in file
            fseek($fh, 1048576, SEEK_CUR);
            //check if we actually left the file
            if (($char = fgetc($fh)) !== false) {
                //if not, go on
                $count++;
            } else {
                //else jump back where we were before leaving and exit loop
                fseek($fh, -1048576, SEEK_CUR);
                break;
            }
        }
        //we could make $count jumps, so the file is at least $count * 1.000001 MB large
        //1048577 because we jump 1 MB and fgetc goes 1 B forward too
        $size = bcmul("1048577", $count);
        //now count the last few bytes; they're always less than 1048576 so it's quite fast
        $fine = 0;
        while (false !== ($char = fgetc($fh))) {
            $fine++;
        }
        //and add them
        $size = bcadd($size, $fine);
        fclose($fh);

        return $size;
    }

    /**
     * Creates database dump by system mysql command.
     *
     * @param string $file absolute path to file in which dump should be placed
     *
     * @return string|array path to dump file if successful, or an array with error message if is failed
     */
    public function backup_db_dump($file)
    {
        if(!class_exists('Symfony_Process_ExecutableFinder')) {
            require_once( GrandCentral_FILE_PATH . '/Symfony/Process/ExecutableFinder.php' );
        }
        $mysqlFinder = new Symfony_Process_ExecutableFinder ();
        $mysqldump = $mysqlFinder->find('mysqldump', 'mysqldump');

        $processBuilder = Symfony_Process_ProcessBuilder::create()
            ->setWorkingDirectory(untrailingslashit(ABSPATH))
            ->setTimeout(3600)
            ->setPrefix($mysqldump)
            ->add('--force')// Continue even if we get an SQL error.
            ->add('--user='.DB_USER)// User for login if not current user.
            ->add('--password='.DB_PASSWORD)//  Password to use when connecting to server. If password is not given it's solicited on the tty.
            ->add('--add-drop-table')// Add a DROP TABLE before each create.
            ->add('--lock-tables=false')// Don't lock all tables for read.
            ->add(DB_NAME)
            ->add('--result-file='.$file);

        $port = 0;
        $host = DB_HOST;

        if (strpos($host, ':') !== false) {
            list($host, $port) = explode(':', $host);
        }
        $socket = false;

        if (strpos(DB_HOST, '/') !== false || strpos(DB_HOST, '\\') !== false) {
            $socket = true;
            $host   = end(explode(':', DB_HOST));
        }

        if ($socket) {
            $processBuilder->add('--socket='.$host);
        } else {
            $processBuilder->add('--host='.$host);
            if (!empty($port)) {
                $processBuilder->add('--port='.$port);
            }
        }

        try {            
            $result = $this->backup_db_php($file);
        } catch (Exception $e) {
            
        }

        if (filesize($file) === 0) {
            unlink($file);
            

            return false;
        } else {
            

            file_put_contents(dirname($file).'/info.json', json_encode(array('table-prefix' => $GLOBALS['wpdb']->prefix, 'site-url' => get_option('siteurl'))));

            return $file;
        }
    }

    /**
     * Creates database dump by php functions.
     *
     * @param string $file absolute path to file in which dump should be placed
     *
     * @return string|array path to dump file if successful, or an array with error message if is failed
     */
    public function backup_db_php($file)
    {
        global $wpdb;
        $tables = $wpdb->get_results('SHOW TABLES', ARRAY_N);
        foreach ($tables as $table) {
            //drop existing table
            $dump_data = "DROP TABLE IF EXISTS $table[0];";
            file_put_contents($file, $dump_data, FILE_APPEND);
            //create table
            $create_table = $wpdb->get_row("SHOW CREATE TABLE $table[0]", ARRAY_N);
            $dump_data    = "\n\n".$create_table[1].";\n\n";
            file_put_contents($file, $dump_data, FILE_APPEND);

            $count = $wpdb->get_var("SELECT count(*) FROM $table[0]");
            if ($count > 100) {
                $count = ceil($count / 100);
            } else {
                if ($count > 0) {
                    $count = 1;
                }
            }

            for ($i = 0; $i < $count; $i++) {
                $low_limit = $i * 100;
                $qry       = "SELECT * FROM $table[0] LIMIT $low_limit, 100";
                $rows      = $wpdb->get_results($qry, ARRAY_A);
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        //insert single row
                        $dump_data  = "INSERT INTO $table[0] VALUES(";
                        $num_values = count($row);
                        $j          = 1;
                        foreach ($row as $value) {
                            $value = addslashes($value);
                            $value = preg_replace("/\n/Ui", "\\n", $value);
                            $num_values == $j ? $dump_data .= "'".$value."'" : $dump_data .= "'".$value."', ";
                            $j++;
                            unset($value);
                        }
                        $dump_data .= ");\n";
                        file_put_contents($file, $dump_data, FILE_APPEND);
                    }
                }
            }
            $dump_data = "\n\n\n";
            file_put_contents($file, $dump_data, FILE_APPEND);

            unset($rows);
            unset($dump_data);
        }

        if (filesize($file) == 0 || !is_file($file)) {
            @unlink($file);

            return array(
                'error' => 'Database backup failed. Try to enable MySQL dump on your server.',
            );
        }

        return $file;
    }

    /**
     * Retruns table_prefix for this WordPress installation.
     * It is used by restore.
     *
     * @return string table prefix from wp-config.php file, (default: wp_)
     */
    public function get_table_prefix()
    {
        $lines = file(ABSPATH.'wp-config.php');
        foreach ($lines as $line) {
            if (strstr($line, '$table_prefix')) {
                $pattern = "/(\'|\")[^(\'|\")]*/";
                preg_match($pattern, $line, $matches);
                $prefix = substr($matches[0], 1);

                return $prefix;
                break;
            }
        }

        return 'wp_'; //default
    }

    /**
     * Change all tables to InnoDB engine, and executes mysql OPTIMIZE TABLE for each table.
     *
     * @return bool optimized successfully or not
     */
    public function optimize_tables()
    {
        global $wpdb;
        $query        = 'SHOW TABLE STATUS';
        $tables       = $wpdb->get_results($query, ARRAY_A);
        $table_string = '';
        foreach ($tables as $table) {
            $table_string .= $table['Name'].",";
        }
        $table_string = rtrim($table_string, ",");
        $optimize     = $wpdb->query("OPTIMIZE TABLE $table_string");

        return (bool) $optimize;
    }

    private function zipExists()
    {
        $zipFinder = new Symfony_Process_ExecutableFinder ();
        $zip            = $zipFinder->find('zip', 'zip');
        $processBuilder = Symfony_Process_ProcessBuilder::create()
            ->setWorkingDirectory(untrailingslashit(ABSPATH))
            ->setPrefix($zip);
        try {
            
            $process = $processBuilder->getProcess();
            $process->run();

            return $process->isSuccessful();
        } catch (Exception $e) {
            return false;
        }
    }

    private function unzipExists()
    {
        $zipFinder = new Symfony_Process_ExecutableFinder ();
        $unzip          = $zipFinder->find('unzip', 'unzip');
        $processBuilder = Symfony_Process_ProcessBuilder::create()
            ->setWorkingDirectory(untrailingslashit(ABSPATH))
            ->setPrefix($unzip)
            ->add('-h');
        try {
           
            $process = $processBuilder->getProcess();
            $process->run();

            return $process->isSuccessful();
        } catch (Exception $e) {
            return false;
        }
    }

    private function mySqlDumpExists()
    {
        $zipFinder = new Symfony_Process_ExecutableFinder ();
        $mysqldump      = $zipFinder->find('mysqldump', 'mysqldump');
        $processBuilder = Symfony_Process_ProcessBuilder::create()
            ->setWorkingDirectory(untrailingslashit(ABSPATH))
            ->setPrefix($mysqldump)
            ->add('--version');
        try {
            
            $process = $processBuilder->getProcess();
            $process->run();

            return $process->isSuccessful();
        } catch (Exception $e) {
            return false;
        }
    }

    private function mySqlExists()
    {
        $zipFinder = new Symfony_Process_ExecutableFinder ();
        $mysql          = $zipFinder->find('mysql', 'mysql');
        $processBuilder = Symfony_Process_ProcessBuilder::create()
            ->setWorkingDirectory(untrailingslashit(ABSPATH))
            ->setPrefix($mysql)
            ->add('--version');
        try {
            
            $process = $processBuilder->getProcess();
            $process->run();

            return $process->isSuccessful();
        } catch (Exception $e) {
            return false;
        }
    }

    private function is32Bits()
    {
        return strlen(decbin(~0)) == 32;
    }


    /**
     * Uploads backup file from server to Amazon S3.
     *
     * @param array $args arguments passed to the function
     *                    [as3_bucket_region] -> Amazon S3 bucket region
     *                    [as3_bucket] -> Amazon S3 bucket
     *                    [as3_access_key] -> Amazon S3 access key
     *                    [as3_secure_key] -> Amazon S3 secure key
     *                    [as3_directory] -> folder on user's Amazon S3 account which backup file should be upload to
     *                    [as3_site_folder] -> subfolder with site name in as3_directory which backup file should be upload to
     *                    [backup_file] -> absolute path of backup file on local server
     *
     * @return bool|array true is successful, array with error message if not
     */
    public function amazons3_backup($args)
    {
        if ($args['as3_site_folder'] != '') {
            $args['as3_directory'] .= '/'.$args['as3_site_folder'];
        }
        $endpoint        = isset($args['as3_bucket_region']) ? $args['as3_bucket_region'] : 's3.amazonaws.com';
        $fileSize        = filesize($args['backup_file']);
        $start           = microtime(true);

        try {
            $s3 = new S3_Client(trim($args['as3_access_key']), trim(str_replace(' ', '+', $args['as3_secure_key'])), false, $endpoint);
            $s3->setExceptions(true);
            $s3->putObjectFile($args['backup_file'], $args['as3_bucket'], $args['as3_directory'].'/'.basename($args['backup_file']), S3_Client::ACL_PRIVATE);
        } catch (Exception $e) {
            

            return array(
                'error' => 'Failed to upload to Amazon S3 ('.$e->getMessage().').',
            );
        }



        return true;
    }

    /**
     * Downloads backup file from Amazon S3 to root folder on local server.
     *
     * @param array $args arguments passed to the function
     *                    [as3_bucket_region] -> Amazon S3 bucket region
     *                    [as3_bucket] -> Amazon S3 bucket
     *                    [as3_access_key] -> Amazon S3 access key
     *                    [as3_secure_key] -> Amazon S3 secure key
     *                    [file_name] -> folder on user's Amazon S3 account which backup file should be downloaded from
     *
     * @return bool|array absolute path to downloaded file is successful, array with error message if not
     */
    public function get_amazons3_backup($args)
    {
        $endpoint = isset($args['as3_bucket_region']) ? $args['as3_bucket_region'] : 's3.amazonaws.com';
        
        try {
            $s3 = new S3_Client($args['as3_access_key'], str_replace(' ', '+', $args['as3_secure_key']), false, $endpoint);
            $s3->setExceptions(true);
            $temp = ABSPATH.'studio98_backup.zip';
            $s3->getObject($args['as3_bucket'], $args['file_name'], $temp);
        } catch (Exception $e) {
           
            return array(
                'error' => 'Error while downloading the backup file from Amazon S3: '.$e->getMessage(),
            );
        }
        $fileSize = filesize($temp);
        return $temp;
    }
    public function restoreBackup($zip_path) {
        
        $unzipFailed = false;
        $deleteBackupAfterRestore = true;

        if (class_exists("ZipArchive")) {
            $unzipFailed = false;
            try {
                $this->unzipWithZipArchive($zip_path);
            } catch (Exception $e) {
                $unzipFailed = true;
            }
        }

        if ($unzipFailed) {
            try {
                $this->pclUnzipIt($zip_path);
            } catch (Exception $e) {
                return array(
                    'error' => $e->getMessage(),
                );
            }
        }
        $this->deleteTempBackupFile($zip_path);
        
        $filePath = ABSPATH.'swp_db';

        @chmod($filePath, 0755);
        $fileName = glob($filePath.'/*.sql');
        $fileName = $fileName[0];

        $restoreDbFailed = false;
        
        try {
            $this->restore_db($fileName);
        } catch (Exception $e) {
            $restoreDbFailed = true;
        }
        if ($restoreDbFailed) {
            try {
                $this->restore_db_php($fileName);
            } catch (Exception $e) {
                @unlink($filePath.'/index.php');
                @unlink($filePath.'/info.json');
                @rmdir($filePath);

                return array(
                    'error' => $e->getMessage(),
                );
            }
        } else {
            @unlink($fileName);
        }
        @unlink($filePath.'/index.php');
        global $wpdb;
        
        // Try to fetch old home and site url, as well as new ones for usage later in database updates
        // Take fresh options
        $homeOpt    = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", 'home'));
        $siteUrlOpt = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", 'siteurl'));
        global $restoreParams;
        $restoreParams = array(
            'oldUrl'      => is_object($homeOpt) ? $homeOpt->option_value : null,
            'oldSiteUrl'  => is_object($siteUrlOpt) ? $siteUrlOpt->option_value : null,
            'tablePrefix' => $this->get_table_prefix(),
            'newUrl'      => '',
        );
        
        $newUrl                  = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", 'home'));
        $restoreParams['newUrl'] = is_object($newUrl) ? $newUrl->option_value : null;
        restore_migrate_urls();
        restore_htaccess();
        return true;        
    }
    
    private function unzipWithZipArchive($backupFile)
    {
        $result     = false;
        $zipArchive = new ZipArchive();
        $zipOpened  = $zipArchive->open($backupFile);
        if ($zipOpened === true) {
            $result = $zipArchive->extractTo(ABSPATH);
            $zipArchive->close();
        }
        if ($result === false) {
            throw new Exception('Failed to unzip files with ZipArchive. Message: '.$zipArchive->getStatusString());
        }
    }
    private function pclUnzipIt($backupFile)
    {
        require_once ABSPATH.'/wp-admin/includes/class-pclzip.php';
        $archive = new PclZip($backupFile);
        $result  = $archive->extract(PCLZIP_OPT_PATH, ABSPATH, PCLZIP_OPT_REPLACE_NEWER);

        if (!$result) {
            throw new Exception('Failed to unzip files. pclZip error ('.$archive->error_code.'): .'.$archive->error_string);
        }
    }

    private function deleteTempBackupFile($backupFile)
    {
        @unlink($backupFile);   
    }

    public function restore_db($fileName)
    {
        if (!$fileName) {
            throw new Exception('Cannot access database file.');
        }

        $port = 0;
        $host = DB_HOST;

        if (strpos(DB_HOST, ':') !== false) {
            list($host, $port) = explode(':', DB_HOST);
        }
        $socket = false;

        if (strpos(DB_HOST, '/') !== false || strpos(DB_HOST, '\\') !== false) {
            $socket = true;
            $host   = end(explode(':', DB_HOST));
        }

        if ($socket) {
            $connection = array('--socket='.$host);
        } else {
            $connection = array('--host='.$host);
            if (!empty($port)) {
                $connection[] = '--port='.$port;
            }
        }

        $mysqlFinder = new Symfony_Process_ExecutableFinder ();
        $mysql     = $mysqlFinder->find('mysql', 'mysql');
        $arguments = array_merge(array($mysql, '--user='.DB_USER, '--password='.DB_PASSWORD, '--default-character-set=utf8', DB_NAME), $connection);
        $command   = implode(' ', array_map(array('Symfony_Process_ProcessUtils', 'escapeArgument'), $arguments)).' < '.Symfony_Process_ProcessUtils::escapeArgument($fileName);

        try {            
            $process = new Symfony_Process_Process($command, untrailingslashit(ABSPATH), $this->getEnv(), null, 3600);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new Symfony_Process_Exception_ProcessFailedException($process);
            }
        } catch (Symfony_Process_Exception_ProcessFailedException $e) {
            //unlink($fileName);
            throw $e;
        } catch (Exception $e) {
            throw $e;
        }
       return true;
    }

    /**
     * Restores database dump by php functions.
     *
     * @param string $file_name relative path to database dump, which should be restored
     *
     * @return bool is successful or not
     */
    public function restore_db_php($file_name)
    {
        global $wpdb;

        $current_query = '';
        // Read in entire file
//        $lines = file($file_name);
        $fp = @fopen($file_name, 'r');
        if (!$fp) {
            throw new Exception("Failed restoring database: could not open dump file ($file_name)");
        }
        while (!feof($fp)) {
            $line = fgets($fp);

            // Skip it if it's a comment
            if (substr($line, 0, 2) == '--' || $line == '') {
                continue;
            }

            // Add this line to the current query
            $current_query .= $line;
            // If it has a semicolon at the end, it's the end of the query
            if (substr(trim($line), -1, 1) == ';') {
                // Perform the query
                $trimmed = trim($current_query, " ;\n");
                if (!empty($trimmed)) {
                    $result = $wpdb->query($current_query);
                    if ($result === false) {
                        @fclose($fp);
                        @unlink($file_name);
                        throw new Exception("Error while restoring database on ($current_query) $wpdb->last_error");
                    }
                }
                // Reset temp variable to empty
                $current_query = '';
            }
        }
        @fclose($fp);
        @unlink($file_name);
    }
    

    /**
     * Deletes all unneeded files produced by backup process.
     *
     * @return array array of deleted files
     */
    public function cleanup()
    {
        $tasks             = $this->tasks;
        $backup_folder     = WP_CONTENT_DIR.'/'.md5('stdd-backup').'/studio98wp/';
        $backup_folder_new = GrandCentral_BACKUP_DIR.'/';
        $files             = glob($backup_folder."*");
        $new               = glob($backup_folder_new."*");

        //Failed db files first
        $db_folder = GrandCentral_DB_DIR.'/';
        $db_files  = glob($db_folder."*");
        if (is_array($db_files) && !empty($db_files)) {
            foreach ($db_files as $file) {
                @unlink($file);
            }
            @unlink(GrandCentral_BACKUP_DIR.'/studio98wp/index.php');
            @unlink(GrandCentral_BACKUP_DIR.'/studio98wp/info.json');
            @rmdir(GrandCentral_DB_DIR);
        }

        //clean_old folder?
        if ((isset($files[0]) && basename($files[0]) == 'index.php' && count($files) == 1) || (empty($files))) {
            if (!empty($files)) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @rmdir(WP_CONTENT_DIR.'/'.md5('stdd-backup').'/studio98wp');
            @rmdir(WP_CONTENT_DIR.'/'.md5('stdd-backup'));
        }

        if (!empty($new)) {
            foreach ($new as $b) {
                $files[] = $b;
            }
        }
        $deleted = array();

        if (is_array($files) && count($files)) {
            $results = array();            

            $num_deleted = 0;
            foreach ($files as $file) {
                if (!in_array($file, $results) && basename($file) != 'index.php') {
                    @unlink($file);
                    $deleted[] = basename($file);
                    $num_deleted++;
                }
            }
        }

        return $deleted;
    }

    

    /**
     * Replaces .htaccess file in process of restoring WordPress site.
     *
     * @param string $url url of current site
     *
     * @return void
     */
    public function replace_htaccess($url)
    {
        $file = @file_get_contents(ABSPATH.'.htaccess');
        if ($file && strlen($file)) {
            $args    = parse_url($url);
            $string  = rtrim($args['path'], "/");
            $regex   = "/BEGIN WordPress(.*?)RewriteBase(.*?)\n(.*?)RewriteRule \.(.*?)index\.php(.*?)END WordPress/sm";
            $replace = "BEGIN WordPress$1RewriteBase ".$string."/ \n$3RewriteRule . ".$string."/index.php$5END WordPress";
            $file    = preg_replace($regex, $replace, $file);
            @file_put_contents(ABSPATH.'.htaccess', $file);
        }
    }

    

    /**
     * @return array|null
     */
    private function getEnv()
    {
        $lang = getenv('LANG');

        if (preg_match('{^.*\.UTF-?8$}i', $lang)) {
            // Allow the environment to be inherited.
            return null;
        }

        if (!$lang) {
            $lang = 'en_US';
        }

        $langParts = explode('.', $lang);

        if (!isset($langParts[1]) || $langParts[1] !== 'UTF-8') {
            $lang = $langParts[0].'.UTF-8';
        }

        return array('LANG' => $lang);
    }
}

if (!function_exists('get_all_files_from_dir')) {
    /**
     * Get all files in directory
     *
     * @param string $path    Relative or absolute path to folder
     * @param array  $exclude List of excluded files or folders, relative to $path
     *
     * @return array List of all files in folder $path, exclude all files in $exclude array
     */
    function get_all_files_from_dir($path, $exclude = array())
    {
        if ($path[strlen($path) - 1] === "/") {
            $path = substr($path, 0, -1);
        }
        global $directory_tree, $ignore_array;
        $directory_tree = array();
        foreach ($exclude as $file) {
            if (!in_array($file, array('.', '..'))) {
                if ($file[0] === "/") {
                    $path = substr($file, 1);
                }
                $ignore_array[] = "$path/$file";
            }
        }
        get_all_files_from_dir_recursive($path);

        return $directory_tree;
    }
}

if (!function_exists('get_all_files_from_dir_recursive')) {
    /**
     * Get all files in directory,
     * wrapped function which writes in global variable
     * and exclued files or folders are read from global variable
     *
     * @param string $path Relative or absolute path to folder
     *
     * @return void
     */
    function get_all_files_from_dir_recursive($path)
    {
        if ($path[strlen($path) - 1] === "/") {
            $path = substr($path, 0, -1);
        }
        global $directory_tree, $ignore_array;
        $directory_tree_temp = array();
        $dh                  = @opendir($path);

        while (false !== ($file = @readdir($dh))) {
            if (!in_array($file, array('.', '..'))) {
                if (empty($ignore_array) || !in_array("$path/$file", $ignore_array)) {
                    if (!is_dir("$path/$file")) {
                        $directory_tree[] = "$path/$file";
                    } else {
                        get_all_files_from_dir_recursive("$path/$file");
                    }
                }
            }
        }
        @closedir($dh);
    }
}

/**
 * Retrieves a value from an array by key, or a specified default if given key doesn't exist
 *
 * @param array $array
 * @param       $key
 * @param null  $default
 *
 * @return mixed
 */
function getKey($key, array $array, $default = null)
{
    return array_key_exists($key, $array) ? $array[$key] : $default;
}

function recursiveUrlReplacement(&$value, $index, $data)
{
    if (is_string($value)) {
        if (is_string($data['regex'])) {
            $expressions = array($data['regex']);
        } elseif (is_array($data['regex'])) {
            $expressions = $data['regex'];
        } else {
            return;
        }

        foreach ($expressions as $exp) {
            $value = preg_replace($exp, $data['newUrl'], $value);
        }
    }
}
/**
 * This should mirror database replacements in cloner.php
 */
function restore_migrate_urls()
{
    // ----- DATABASE REPLACEMENTS

    /**
     * Finds all urls that begin with $oldSiteUrl AND
     * end either with OPTIONAL slash OR with MANDATORY slash following any number of any characters
     */

    //     Get all options that contain old urls, then check if we can replace them safely
    // Now check for old urls without WWW
    global $restoreParams, $wpdb;
    $oldSiteUrl  = $restoreParams['oldSiteUrl'];
    $oldUrl      = $restoreParams['oldUrl'];
    $tablePrefix = $restoreParams['tablePrefix'];
    $newUrl      = $restoreParams['newUrl'];

    if (!isset($oldSiteUrl) || !isset($oldUrl)) {
        return false;
    }

    $parsedOldSiteUrl      = parse_url(strpos($oldSiteUrl, '://') === false ? "http://$oldSiteUrl" : $oldSiteUrl);
    $parsedOldUrl          = parse_url(strpos($oldUrl, '://') === false ? "http://$oldUrl" : $oldUrl);
    $host                  = getKey('host', $parsedOldSiteUrl, '');
    $path                  = getKey('path', $parsedOldSiteUrl, '');
    $oldSiteUrlNoWww       = preg_replace('#^www\.(.+\.)#i', '$1', $host).$path;
    $parsedOldSiteUrlNoWww = parse_url(strpos($oldSiteUrlNoWww, '://') === false
        ? "http://$oldSiteUrlNoWww"
        : $oldSiteUrlNoWww
    );
    if (isset($parse['scheme'])) {
        $oldSiteUrlNoWww = "{$parse['scheme']}://$oldSiteUrlNoWww";
    }

    // Modify the database for two variants of url, one with and one without WWW
    $oldUrls = array('oldSiteUrl' => $oldSiteUrl);
    $tmp1    = @"{$parsedOldUrl['host']}/{$parsedOldUrl['path']}";
    $tmp2    = @"{$parsedOldSiteUrlNoWww['host']}/{$parsedOldSiteUrlNoWww['path']}";
    if ($oldSiteUrlNoWww != $oldSiteUrl && $tmp1 != $tmp2) {
        $oldUrls['oldSiteUrlNoWww'] = $oldSiteUrlNoWww;
    }
    if (strpos($oldSiteUrl, $oldUrl
        ) !== false && $oldSiteUrl != $oldUrl && $parsedOldUrl['host'] != $parsedOldSiteUrl['host']
    ) {
        $oldUrls['oldUrl'] = $oldUrl;
    }
    foreach ($oldUrls as $key => $url) {
        if (empty($url) || strlen($url) <= 1) {
            continue;
        }

        if ($key == 'oldSiteUrlNoWww') {
            $amazingRegex = "~http://{$url}(?=(((/.*)+)|(/?$)))~";
        } else {
            $amazingRegex = "~{$url}(?=(((/.*)+)|(/?$)))~";
        }
        // Check options
        $query     = "SELECT option_id, option_value FROM {$tablePrefix}options WHERE option_value LIKE '%{$url}%';";
        $selection = $wpdb->get_results($query, ARRAY_A);
        foreach ($selection as $row) {
            // Set a default value untouched
            $replaced = $row['option_value'];

            if (is_serialized($row['option_value'])) {
                $unserialized = unserialize($row['option_value']);
                if (is_array($unserialized)) {
                    array_walk_recursive($unserialized, 'recursiveUrlReplacement', array(
                            'newUrl' => $newUrl,
                            'regex'  => $amazingRegex,
                        )
                    );
                    $replaced = serialize($unserialized);
                }
            } else {
                $replaced = preg_replace($amazingRegex, $newUrl, $replaced);
            }

            $escapedReplacement = $wpdb->_escape($replaced);

            $optId = $row['option_id'];
            if ($row['option_value'] != $replaced) {
                $query = "UPDATE {$tablePrefix}options SET option_value = '{$escapedReplacement}' WHERE option_id = {$optId}";
                $wpdb->query($query);
            }
        }

        // Check post meta
        $query     = "SELECT meta_id, meta_value FROM {$tablePrefix}postmeta WHERE meta_value LIKE '%{$url}%'";
        $selection = $wpdb->get_results($query, ARRAY_A);
        foreach ($selection as $row) {
            $replacement = $row['meta_value'];
            if (is_serialized($replacement)) {
                $unserialized = unserialize($replacement);
                if (is_array($unserialized)) {
                    array_walk_recursive($unserialized, 'recursiveUrlReplacement', array(
                            'newUrl' => $newUrl,
                            'regex'  => $amazingRegex,
                        )
                    );
                }
                $replacement = serialize($unserialized);
            } else {
                $replacement = preg_replace($amazingRegex, $newUrl, $replacement);
            }

            if ($replacement != $row['meta_value']) {
                $escapedReplacement = $wpdb->_escape($replacement);
                $id                 = $row['meta_id'];
                $query              = "UPDATE {$tablePrefix}postmeta SET meta_value = '{$escapedReplacement}' WHERE meta_id = '$id'";
                $wpdb->query($query);
            }
        }

        // Do the same with posts
        $query     = "SELECT ID, post_content, guid FROM {$tablePrefix}posts WHERE post_content LIKE '%{$url}%' OR guid LIKE '%{$url}%'";
        $selection = $wpdb->get_results($query, ARRAY_A);
        foreach ($selection as &$row) {
            $postContent = preg_replace($amazingRegex, $newUrl, $row['post_content']);
            $guid        = preg_replace($amazingRegex, $newUrl, $row['guid']);

            if ($postContent != $row['post_content'] || $guid != $row['guid']) {
                $postContent = $wpdb->_escape($postContent);
                $guid        = $wpdb->_escape($guid);
                $postId      = $row['ID'];
                $q           = "UPDATE {$tablePrefix}posts SET post_content = '$postContent', guid = '$guid' WHERE ID = {$postId}";
                $wpdb->query($q);
            }
        }
    }
}
function restore_htaccess()
{
    // This has to be done because it contains the function save_mod_rewrite_rules().
    include_once ABSPATH.'wp-admin/includes/admin.php';

    $htaccessRealpath = realpath(ABSPATH.'.htaccess');

    if ($htaccessRealpath) {
        @rename($htaccessRealpath, "$htaccessRealpath.old");
    }

    if (isset($GLOBALS['wp_rewrite'])) {
        $wpRewrite = $GLOBALS['wp_rewrite'];
    } else {
        $wpRewrite = $GLOBALS['wp_rewrite'] = new WP_Rewrite();
    }

    $wpRewrite->flush_rules(true);
}
