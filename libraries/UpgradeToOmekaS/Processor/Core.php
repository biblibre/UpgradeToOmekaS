<?php

/**
 * Upgrade Core to Omeka S.
 *
 * @internal All checks can be bypassed with another "Core" processor.
 *
 * @package UpgradeToOmekaS
 */
class UpgradeToOmekaS_Processor_Core extends UpgradeToOmekaS_Processor_Abstract
{
    public $pluginName = 'Core';
    public $minVersion = '2.3.1';
    public $maxVersion = '2.5';
    protected $_bypassDefaultPrechecks = true;

    public $omekaSemantic = array(
        'version' => 'v1.0.0-beta2',
        'size' => 11526232,
        'md5' => '45283a20f3a8e13dac1a9cfaeeaa9c51',
    );

    public $omekaSemanticMinDb = array(
        'mariadb' => '5.5.3',
        'mysql' => '5.5.3',
    );

    /**
     * Define a minimum size for the install directory (without files).
     *
     * @var integer
     */
    public $minOmekaSemanticSize = 100000000;

    /**
     * Define a minimum size for the destination base dir.
     *
     * @var integer
     */
    public $minDestinationSize = 1000000000;

    /**
     * Define a minimum size for the temp directory.
     *
     * @var integer
     */
    public $minTempDirSize = 1000000000;

    /**
     * List of methods to process for the upgrade.
     *
     * @var array
     */
    public $processMethods = array(
        // Installation.
        '_createDirectory',
        '_downloadOmekaS',
        '_unzipOmekaS',
        '_configOmekaS',
        '_installOmekaS',

        // Database.
        '_importSettings',
        '_importUsers',
        '_importItemTypes',
        '_importCollections',
        '_importItems',
        '_importFiles',

        // Files.
        '_copyFiles',
        '_copyThemes',

        // Specific tasks.
        '_downloadCompatibilityModule',
        '_unzipCompatibiltyModule',
        '_installCompatibiltyModule',
    );

    /**
     * The url of the Omeka S package, minus version.
     *
     * @var string
     */
    protected $_urlPackage = 'https://github.com/omeka/omeka-s/releases/download/%s/omeka-s.zip';

    /**
     * Default tables of Omeka S.
     *
     * @var array
     */
    protected $_omekaSTables = array(
        'api_key', 'asset', 'item', 'item_item_set', 'item_set', 'job', 'media',
        'migration', 'module', 'password_creation', 'property', 'resource',
        'resource_class', 'resource_template', 'resource_template_property',
        'session', 'setting', 'site', 'site_block_attachment', 'site_item_set',
        'site_page', 'site_page_block', 'site_permission', 'site_setting',
        'user', 'value', 'vocabulary',
    );

    /**
     * Store the full archive size (directory "files").
     *
     * @var integer
     */
    protected $_archiveSize;

    /**
     * Store the full database size.
     *
     * @var integer
     */
    protected $_databaseSize;

    /**
     * Store the free size of the destination directory.
     *
     * @var integer
     */
    protected $_destinationFreeSize;

    /**
     * Check if the plugin is installed.
     *
     * @internal Always true for the Core.
     *
     * @return boolean
     */
    public function isPluginReady()
    {
        return true;
    }

    /**
     * Return the default Omeka S tables.
     */
    public function getOmekaSDefaultTables()
    {
        return $this->_omekaSTables;
    }

    /**
     * @todo Load all the config checks from Omeka Semantic.
     *
     * {@inheritDoc}
     * @see UpgradeToOmekaS_Processor_Abstract::_precheckConfig()
     * @see application/config/module.config.php['installer']['pre_tasks']
     */
    protected function _precheckConfig()
    {
        $this->_checkVersion();
        // May be disabled during debug.
        $this->_checkBackgroundJob();
        // During the background process, the server is not Apache.
        if (!$this->_isProcessing) {
            $this->_checkServer();
        }
        // See Omeka S ['installer']['pre_tasks']: CheckEnvironmentTask.php
        $this->_checkPhp();
        $this->_checkPhpModules();
        // See Omeka S ['installer']['pre_tasks']: CheckDbConfigurationTask.php
        $this->_checkDatabaseServer();
        $this->_checkZip();
        // Don't check the jobs during true process.
        if (!$this->_isProcessing) {
            $this->_checkJobs();
        }
    }

    protected function _checkConfig()
    {
        $this->_checkDatabase();
        // See Omeka S ['installer']['pre_tasks']: CheckDirPermissionsTask.php
        $this->_checkFileSystem();
        $this->_checkFreeSize();
    }

    /* Prechecks. */

    protected function _checkVersion()
    {
        if (version_compare($this->minVersion, OMEKA_VERSION, '>')) {
            $this->_prechecks[] = __('The current release requires at least Omeka %s, current is only %s.',
                $this->minVersion, OMEKA_VERSION);
        }

        if (version_compare($this->maxVersion, OMEKA_VERSION, '<')) {
            $this->_prechecks[] = __('The current release requires at most Omeka %s, current is %s.',
                $this->maxVersion, OMEKA_VERSION);
        }
    }

    protected function _checkBackgroundJob()
    {
        $config = Zend_Registry::get('bootstrap')->config;
        if ($config) {
            if (isset($config->jobs->dispatcher->longRunning)) {
                if ($config->jobs->dispatcher->longRunning == 'Omeka_Job_Dispatcher_Adapter_Synchronous') {
                    $this->_prechecks[] = __('The process should be done in the background: modify the setting "jobs.dispatcher.longRunning" in the config of Omeka Classic.');
                }
            }
            // No long job.
            else {
                $this->_prechecks[] = __('The background job config is not defined in the config of Omeka Classic.');
            }
        }
        // No config.
        else {
            $this->_prechecks[] = __('The config of Omeka Classic has not been found.');
        }
    }

    protected function _checkServer()
    {
        if ($this->_isServerWindows()) {
            $this->_prechecks[] = __('According to the readme of Omeka Semantic, the server should be a Linux one.');
        }

        if (!$this->_isServerApache()) {
            $this->_prechecks[] = __('According to the readme of Omeka Semantic, the server should be an Apache one.');
        }
    }

    protected function _checkPhp()
    {
        if (version_compare(PHP_VERSION, '5.6', '<')) {
            $this->_prechecks[] = __('Omeka Semantic requires at least PHP 5.6 and prefers the last stable version.');
        }
        // TODO Add a check for the vesion of PHP in background process?
    }

    protected function _checkPhpModules()
    {
        $requiredExtensions = array(
            'pdo',
            'pdo_mysql',
        );
        foreach ($requiredExtensions as $extension) {
            if (!extension_loaded($extension)) {
                // TODO Check under Windows.
                if (!function_exists('dl') || !dl($extension . '.so')) {
                    $this->_prechecks[] = __('Omeka Semantic requires the php extension "%s".', $extension);
                }
            }
        }
    }

    protected function _checkDatabaseServer()
    {
        $sql = 'SHOW VARIABLES LIKE "version"';
        $result = $this->_db->query($sql)->fetchAll();
        if (empty($result)) {
            $this->_prechecks[] = __('The version of the database server cannot be checked.');
        }
        // Standard server.
        else {
            $result = strtolower($result[0]['Value']);
            $mariadb = strpos($result, '-mariadb');
            $version = strtok($result, '-');
            if ($mariadb) {
                $result = version_compare($this->omekaSemanticMinDb['mariadb'], $version, '>');
            }
            // Probably a mysql database.
            else {
                $result = version_compare($this->omekaSemanticMinDb['mysql'], $version, '>');
            }
            if ($result) {
                $this->_prechecks[] = __('The current release requires at least MariaDB %s or Mysql %s, current is only %s.',
                    $this->omekaSemanticMinDb['mariadb'], $this->omekaSemanticMinDb['mysql'], ($mariadb ? 'MariaDB' : 'MySQL') . ' ' . $version);
            }
        }
    }

    protected function _checkZip()
    {
        if (!class_exists('ZipArchive')) {
            try {
                $messageError = __('Zip (as an available command line tool or as the php module ZipArchive) is required to extract downloaded packages.');
                UpgradeToOmekaS_Common::executeCommand('unzip', $status, $output, $errors);
                // A return value of 0 indicates the convert binary is working correctly.
                if ($status != 0) {
                    $this->_prechecks[] = $messageError;
                    $this->_prechecks[] = __('The shell returns an error: %s', $errors);
                }
            } catch (Exception $e) {
                $this->_prechecks[] = $messageError;
                $this->_prechecks[] = __('An error occurs: %s', $e->getMessage());
            }
        }
    }

    protected function _checkJobs()
    {
        $totalRunningJobs = $this->_db->getTable('Process')
            ->count(array('status' => array(Process::STATUS_STARTING, Process::STATUS_IN_PROGRESS)));
        if ($totalRunningJobs) {
            $this->_prechecks[] = __(plural('%d job is running.', '%d jobs are running.',
                $totalRunningJobs), $totalRunningJobs);
        }
    }

    /* Checks. */

    protected function _checkDatabase()
    {
        // Get the database name.
        $db = $this->_db;
        $config = $db->getAdapter()->getConfig();
        $dbName = $config['dbname'];
        if (empty($dbName)) {
            $this->_checks[] = __('Unable to get the database name.');
            return;
        }
        $dbHost = $config['host'];
        if (empty($dbHost)) {
            $this->_checks[] = __('Unable to get the database host.');
            return;
        }

        // Get size of the current database.
        $sql = 'SELECT SUM(data_length + index_length) AS "Size"
        FROM information_schema.TABLES
        WHERE table_schema = "' . $dbName . '";';
        $sizeDatabase = $db->fetchOne($sql);

        // Get snaky free size of the current database.
        $sql = 'SELECT SUM(data_free) AS "Free Size"
        FROM information_schema.TABLES
        WHERE table_schema = "' . $dbName . '";';
        $freeSizeDatabase = $db->fetchOne($sql);

        $databaseSize = $sizeDatabase + $freeSizeDatabase;
        $this->_databaseSize = $databaseSize;
        if (empty($sizeDatabase) || empty($databaseSize)) {
            $this->_checks[] = __('Cannot evaluate the size of the Omeka Classic database.');
        }

        // Check if matching params.
        $type = $this->getParam('database_type');
        switch ($type) {
            case 'separate':
                $host = $this->getParam('database_host');
                $port = $this->getParam('database_port');
                $username = $this->getParam('database_username');
                $password = $this->getParam('database_password');
                $name = $this->getParam('database_name');
                if (empty($host)) {
                    $this->_checks[] = __('The param "%s" should be set when the databases are separate.', 'host');
                }
                if (empty($username)) {
                    $this->_checks[] = __('The param "%s" should be set when the databases are separate.', 'username');
                }
                if (empty($name)) {
                    $this->_checks[] = __('The param "%s" should be set when the databases are separate.', 'name');
                }
                if ($name == $dbName && $host == $dbHost) {
                    $this->_checks[] = __('The database name should be different from the Omeka Classic one when the databases are separate, but on the same server.');
                }

                // Check access rights.
                $params=array(
                    'host' => $host,
                    'username' => $username,
                    'password' => $password,
                    'dbname' => $name,
                );
                if ($port) {
                    $params['port'] = $port;
                }

                try {
                    $targetDb = Zend_Db::Factory('PDO_MYSQL', $params);
                    if (empty($targetDb)) {
                        $this->_checks[] = __('Can’t get access to the database "%s": %s', $name, $e->getMessage());
                    }
                } catch (Exception $e) {
                    $this->_checks[] = __('Cannot access to the database "%s": %s', $name, $e->getMessage());
                    return;
                }

                $sql = 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = "' . $name . '";';
                $result = $targetDb->fetchOne($sql);
                if ($result) {
                    $this->_checks[] = __('The database "%s" should be empty.', $name);
                    return;
                }
                break;

                // The database is shared; so the prefix should be different.
            case 'share':
                $prefix = $this->getParam('database_prefix');
                if (empty($prefix)) {
                    $this->_checks[] = __('A database prefix is required when the database is shared.');
                    return;
                }
                if ($prefix == $db->prefix) {
                    $this->_checks[] = __('The database prefix should be different from the Omeka Classic one when the database is shared.');
                    return;
                }

                // Check the names of the tables and the prefix.
                $sql = 'SHOW TABLES;';
                $result = $db->fetchCol($sql);
                if (empty($result)) {
                    $this->_checks[] = __('Cannot get the list of the tables of Omeka Classic.');
                    return;
                }
                $existings = array_filter($result, function ($v) use ($prefix) {
                    return strpos($v, $prefix) === 0;
                });
                if ($existings) {
                    $this->_checks[] = __('The prefix "%s" cannot be used, because it causes a conflict in the table names of Omeka Classic.', $prefix);
                    return;
                }

                // Check conflicts of table names.
                if (array_intersect($result, $this->getOmekaSDefaultTables())) {
                    $this->_checks[] = __('Some names of tables of Omeka S are existing in the database of Omeka Classic.');
                }
                break;

            default:
                $this->_checks[] = __('The type of database "%s" is not supported.', $type);
                return;
        }

        // Check max database size.
        // TODO Check max database size. Currently, this is done partially via
        // the check of the size of the filesystem, but the database may be
        // mounted differently or externalized, so some cases can't be managed.
    }

    protected function _checkFileSystem()
    {
        $path = $this->getParam('base_dir');

        // The dir is already validated by the form, but this is an important
        // param and revalidation is quick. The checks are important in
        // particular because the document root may be changed between a web
        // request (the one set by Apache) and a command line request (empty, so
        // saved from document root during install).
        if (!UpgradeToOmekaS_Form_Validator::validateBaseDir($path)) {
            $this->_checks[] = __('The base dir "%s" is not allowed or not writable.', $path);
            // Other checks are not processed when this one fails.
            return;
        }

        // Check access rights inside the directory, in particular when the
        // directory preexists.
        $isCreated = !file_exists($path);
        if ($isCreated) {
            $result = UpgradeToOmekaS_Common::createDir($path);
            if (empty($result)) {
                $this->_checks[] = __('The base dir "%s" is not writable.', $path);
                return;
            }
        }

        // Check creation of a sub directory.
        $testDir = $path . DIRECTORY_SEPARATOR . 'testdir';
        $result = UpgradeToOmekaS_Common::createDir($testDir);
        if (empty($result)) {
            $this->_checks[] = __('The base dir "%s" is not usable.', $path);
            UpgradeToOmekaS_Common::removeDir($isCreated ? $path : $testDir, true);
            return;
        }

        // Check creation of a file.
        $testFile = $testDir . DIRECTORY_SEPARATOR . md5(rtrim(strtok(substr(microtime(), 2), ' '), '0'));
        $result = touch($testFile);
        if (empty($result)) {
            $this->_checks[] = __('The base dir "%s" does not creation of files.', $path);
            UpgradeToOmekaS_Common::removeDir($isCreated ? $path : $testDir, true);
            return;
        }

        // Check hard linking if needed. This is important when the dir is
        // different from the Omeka Classic one.
        $type = $this->getParam('files_type');
        if ($type == 'hard_link') {
            $testLink = $testDir . DIRECTORY_SEPARATOR . md5(rtrim(strtok(substr(microtime(), 2), ' '), '0'));
            $result = link($testFile, $testLink);
            if (empty($result)) {
                $this->_checks[] = __('The base dir "%s" does not allow creation of hard links.', $path);
                UpgradeToOmekaS_Common::removeDir($isCreated ? $path : $testDir, true);
                return;
            }
        }

        // Get free size on the temp folder.
        $tempDir = sys_get_temp_dir();
        $result = disk_free_space($tempDir);
        if ($result < $this->minTempDirSize) {
            $this->_checks[] = __('The free size of the temp directory should be greater than %dMB.', ceil($this->minTempDirSize / 1000000));
            UpgradeToOmekaS_Common::removeDir($isCreated ? $path : $testDir, true);
            return;
        }

        // Get free size on the destination file sytem.
        $result = disk_free_space($path);
        if ($result < $this->minDestinationSize) {
            $this->_checks[] = __('The free size of the base dir should be greater than %dMB.', ceil($this->minDestinationSize / 1000000));
            UpgradeToOmekaS_Common::removeDir($isCreated ? $path : $testDir, true);
            return;
        }
        $this->_destinationFreeSize = $result;

        // Get current size of the files folder.
        $result = UpgradeToOmekaS_Common::getDirectorySize(FILES_DIR);
        if (empty($result)) {
            $this->_checks[] = __('Cannot evaluate the size of the Omeka Classic files dir.');
            UpgradeToOmekaS_Common::removeDir($isCreated ? $path : $testDir, true);
            return;
        }
        $this->_archiveSize = $result;

        UpgradeToOmekaS_Common::removeDir($isCreated ? $path : $testDir, true);
    }

    protected function _checkFreeSize()
    {
        $archiveSize = $this->_archiveSize;
        $databaseSize = $this->_databaseSize;
        $destinationFreeSize = $this->_destinationFreeSize;

        if (empty($archiveSize)) {
            $this->_checks[] = __('The size of the archive can not be determined.');
            return;
        }
        if (empty($databaseSize)) {
            $this->_checks[] = __('The size of the database can not be determined.');
            return;
        }
        if (empty($destinationFreeSize)) {
            $this->_checks[] = __('The free space size can not be determined.');
            return;
        }

        $type = $this->getParam('files_type');
        switch ($type) {
            case 'copy':
                $minDestinationSize = 1.2 * $archiveSize + $this->minOmekaSemanticSize;
                break;
            case 'hard_link':
                $numberFiles = UpgradeToOmekaS_Common::countFilesInDir(FILES_DIR);
                $minDestinationSize = 5000 * $numberFiles + $this->minOmekaSemanticSize;
                break;
            case 'dummy':
                $numberFiles = UpgradeToOmekaS_Common::countFilesInDir(FILES_DIR);
                $minDestinationSize = 10000 * $numberFiles + $this->minOmekaSemanticSize;
                break;
            default:
                $this->_checks[] = __('The type of files "%s" is unknown.', $type);
                return;
        }

        if ($destinationFreeSize < $minDestinationSize) {
            $this->_checks[] = __('A minimum size of %dMB is required in the base dir, only %dMB is available.',
                ceil($minDestinationSize / 1000000), ceil($destinationFreeSize / 1000000));
            return;
        }

        // TODO Check when the file systems of the database and files are different.

        $minSize = $minDestinationSize + 2 * $databaseSize;
        if ($destinationFreeSize < $minSize) {
            $this->_checks[] = __('A minimum size of %dMB (%dMB for the files and %dMB for the database) is required in the base dir, only %dMB is available.',
                ceil($minSize / 1000000), ceil($minDestinationSize / 1000000), ceil($databaseSize / 1000000), ceil($destinationFreeSize / 1000000));
            return;
        }
    }

    /**
     * Helper to get the server OS.
     *
     * @return string
     */
    protected function _isServerWindows()
    {
        return strncasecmp(PHP_OS, 'WIN', 3) == 0;
    }

    /**
     * Helper to get the server OS.
     *
     * @return string
     */
    protected function _isServerApache()
    {
        $serverSofware = isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '';
        return strpos(strtolower($serverSofware), 'apache') === 0;
    }

    /* Methods for the upgrade. */

    protected function _createDirectory()
    {
        $path = $this->getParam('base_dir');
        $result = UpgradeToOmekaS_Common::createDir($path);
        if (!$result) {
            throw new UpgradeToOmekaS_Exception(
                __('Unable to create the directory %s.', $path));
        }
    }

    protected function _downloadOmekaS()
    {
        $url = sprintf($this->_urlPackage, $this->omekaSemantic['version']);
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'omeka-s.zip';
        if (file_exists($path)) {
            // Check if the file is empty, in particular for network issues.
            if (!filesize($path)) {
                throw new UpgradeToOmekaS_Exception(
                    __('An empty file "omeka-s.zip" exists in the temp directory.')
                    . ' ' . __('You should remove it manually or replace it by the true file (%s).', $url));
            }
            if (filesize($path) != $this->omekaSemantic['size']
                    || md5_file($path) != $this->omekaSemantic['md5']
                ) {
                throw new UpgradeToOmekaS_Exception(
                    __('A file "omeka-s.zip" exists in the temp directory and this is not the release %s.',
                        $this->omekaSemantic['version']));
            }
            $this->_log('[' . __FUNCTION__ . ']: ' . __('The file is already downloaded.'), Zend_Log::INFO);
        }
        // Download the file.
        else {
            $this->_log('[' . __FUNCTION__ . ']: ' . __('The size of the file to download is %dMB, so wait a while.', $this->omekaSemantic['size'] / 1000000), Zend_Log::INFO);
            $result = file_put_contents($path, fopen($url, 'r'));
            if (empty($result)) {
                throw new UpgradeToOmekaS_Exception(
                    __('An issue occured during the file download.')
                    . ' ' . __('Try to download it manually (%s) and to save it as "%s" in the temp folder of Apache.', $url, $path));
            }
            if (filesize($path) != $this->omekaSemantic['size']
                    || md5_file($path) != $this->omekaSemantic['md5']
                ) {
                throw new UpgradeToOmekaS_Exception(
                    __('The downloaded file is corrupted.')
                    . ' ' . __('Try to download it manually (%s) and to save it as "%s" in the temp folder of Apache.', $url, $path));
            }
        }
    }

    protected function _unzipOmekaS()
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'omeka-s.zip';
        $baseDir = $this->getParam('base_dir');
        $result = UpgradeToOmekaS_Common::extractZip($path, $baseDir);
        if (!$result) {
            throw new UpgradeToOmekaS_Exception(
                __('Unable to extract the zip file "%s" into the destination "%s".', $path, $baseDir));
        }
    }

    protected function _configOmekaS()
    {
        // Create database.ini.
        $type = $this->getParam('database_type');
        switch ($type) {
            case 'separate':
                $host = $this->getParam('database_host');
                $port = $this->getParam('database_port');
                $dbname = $this->getParam('database_name');
                $username = $this->getParam('database_username');
                $password = $this->getParam('database_password');
                // $prefix = $this->getParam('database_prefix');
                break;

            case 'share':
                $db = $this->_db;
                $config = $db->getAdapter()->getConfig();
                $host = isset($config['host']) ? $config['host'] : '';
                $port = isset($config['port']) ? $config['port'] : '';
                $dbname = isset($config['dbname']) ? $config['dbname'] : '';
                $username = isset($config['username']) ? $config['username'] : '';
                $password = isset($config['password']) ? $config['password'] : '';
                // $prefix = isset($config['prefix']) ? $config['prefix'] : '';
                break;

            default:
                throw new UpgradeToOmekaS_Exception(
                    __('The type "%s" is not possible for the database.', $type));
        }

        $databaseIni = $this->_getFullPath('config/database.ini');
        $databaseConfig = 'user     = "' . $username . '"'. PHP_EOL;
        $databaseConfig .= 'password = "' . $password . '"'. PHP_EOL;
        $databaseConfig .= 'dbname   = "' . $dbname . '"'. PHP_EOL;
        $databaseConfig .= 'host     = "' . $host . '"'. PHP_EOL;
        // $databaseConfig .= empty($prefix)
        //     ? ';prefix   = '. PHP_EOL
        //     : 'prefix   = "' . $prefix . '"'. PHP_EOL;
        $databaseConfig .= empty($port)
            ? ';port     = '. PHP_EOL
            : 'port     = "' . $port . '"'. PHP_EOL;
        $databaseConfig .= ';unix_socket = "' . '' . '"'. PHP_EOL;
        $databaseConfig .= ';log_path = "' . '' . '"'. PHP_EOL;

        $result = file_put_contents($databaseIni, $databaseConfig);
        if (empty($result)) {
            throw new UpgradeToOmekaS_Exception(
                __('Unable to save the database.ini file.'));
        }

        $this->_log('[' . __FUNCTION__ . ']: ' . __('The file "config/database.ini" has been updated successfully.'), Zend_Log::INFO);

        // The connection should be checked even for a shared database.
        $params = array(
            'host' => $host,
            'username' => $username,
            'password' => $password,
            'dbname' => $dbname,
        );
        if ($port) {
            $params['port'] = $port;
        }

        try {
            $targetDb = Zend_Db::Factory('PDO_MYSQL', $params);
            if (empty($targetDb)) {
                throw new UpgradeToOmekaS_Exception(
                    __('Database is null.'));
            }
        } catch (Exception $e) {
            throw new UpgradeToOmekaS_Exception(
                __('Cannot access to the database "%s": %s', $dbname, $e->getMessage()));
        }

        // Another check.
        switch ($type) {
            case 'separate':
                $sql = 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = "' . $dbname . '";';
                $result = $targetDb->fetchOne($sql);
                if ($result) {
                    throw new UpgradeToOmekaS_Exception(
                        __('The target database "%s" should be empty when using a separate database.', $dbname));
                }
                break;
            case 'share':
                $sql = 'SHOW TABLES;';
                $result = $targetDb->fetchCol($sql);
                if (array_intersect($result, $this->getOmekaSDefaultTables())) {
                    throw new UpgradeToOmekaS_Exception(
                        __('Some names of tables of Omeka S are existing in the database of Omeka Classic.'));
                }
                break;
        }

        $this->_targetDb = $targetDb;
    }

    /**
     * @see application/config/module.config.php['installer']['tasks']
     * @throws UpgradeToOmekaS_Exception
     */
    protected function _installOmekaS()
    {
        $targetDb = $this->getTargetDb();

        // See Omeka S ['installer']['tasks']: DestroySessionTask.php
        // Nothing to do: there is no session by default in the tables and no
        // user is logged since it is processed automatically.
        $this->_log('[' . __FUNCTION__ . ']: ' . __('Installer Task "%s" ended (nothing to do).', 'Destroy Session'), Zend_Log::DEBUG);

        // See Omeka S ['installer']['tasks']: ClearCacheTask.php
        // Nothing to do.
        $this->_log('[' . __FUNCTION__ . ']: ' . __('Installer Task "%s" ended (nothing to do).', 'Clear Cache'), Zend_Log::DEBUG);

        // See Omeka S ['installer']['tasks']: InstallSchemaTask.php
        // The Omeka S schema is an optimized sql script, so use it.
        $script = $this->_getFullPath('application/data/install/schema.sql');
        $sql = file_get_contents($script);
        $result = $targetDb->prepare($sql)->execute();
        if (!$result) {
            throw new UpgradeToOmekaS_Exception(
                __('Unable to execute install queries.'));
        }
        $this->_log('[' . __FUNCTION__ . ']: ' . __('Installer Task "%s" ended.', 'Install Schema'), Zend_Log::DEBUG);

        // See Omeka S ['installer']['tasks']: RecordMigrationsTask.php
        // See Omeka\Db\Migration\Manager::getAvailableMigrations()
        // Omeka 2 should be compatible with 5.3.2.
        $path = $this->_getFullPath('application/data/migrations');
        $migrations = array();
        $globPattern = $path . DIRECTORY_SEPARATOR . '*.php';
        $regexPattern = '/^(\d+)_(\w+)\.php$/';
        $files = glob($globPattern, GLOB_MARK);
        foreach ($files as $filename) {
            if (preg_match($regexPattern, basename($filename), $matches)) {
                $version = $matches[1];
                $migrations[] = $version;
            }
        }
        $sql = 'INSERT INTO migration VALUES(' . implode('),(', $migrations) . ');';
        $result = $targetDb->prepare($sql)->execute();
        if (!$result) {
            throw new UpgradeToOmekaS_Exception(
                __('Unable to update list of migrations.'));
        }
        $this->_log('[' . __FUNCTION__ . ']: ' . __('Installer Task "%s" ended.', 'Record Migrations'), Zend_Log::DEBUG);

        // See Omeka S ['installer']['tasks']: InstallDefaultVocabulariesTask.php
        // To simplify process for vocabularies, that Omeka doesn't manage, an
        // export of a fresh automatic install is used (the installer task
        // imports rdf vocabularies in application/data/vocabularies).
        $script = dirname(dirname(dirname(dirname(__FILE__))))
            . DIRECTORY_SEPARATOR . 'libraries'
            . DIRECTORY_SEPARATOR . 'scripts'
            . DIRECTORY_SEPARATOR . 'rdf_vocabularies.sql';
        $sql = file_get_contents($script);
        $result = $targetDb->prepare($sql)->execute();
        if (!$result) {
            throw new UpgradeToOmekaS_Exception(
                __('Unable to execute install queries for default vocabularies.'));
        }
        $this->_log('[' . __FUNCTION__ . ']: ' . __('Installer Task "%s" ended.', 'Install Default Vocabularies'), Zend_Log::DEBUG);

        // See Omeka S ['installer']['tasks']: InstallDefaultTemplatesTask.php
        // Same note than vocabularies above.
        $script = dirname(dirname(dirname(dirname(__FILE__))))
            . DIRECTORY_SEPARATOR . 'libraries'
            . DIRECTORY_SEPARATOR . 'scripts'
            . DIRECTORY_SEPARATOR . 'default_templates.sql';
        $sql = file_get_contents($script);
        $result = $targetDb->prepare($sql)->execute();
        if (!$result) {
            throw new UpgradeToOmekaS_Exception(
                __('Unable to execute install queries for default templates.'));
        }
        $this->_log('[' . __FUNCTION__ . ']: ' . __('Installer Task "%s" ended.', 'Install Default Templates'), Zend_Log::DEBUG);

        // See Omeka S ['installer']['tasks']: CreateFirstUserTask.php
        $this->_log('[' . __FUNCTION__ . ']: ' . __('Installer Task "%s" skipped (processed with other users).', 'Create First User'), Zend_Log::DEBUG);

        // See Omeka S ['installer']['tasks']: AddDefaultSettingsTask.php
        $user = $this->getParam('user');
        if (empty($user)) {
            throw new UpgradeToOmekaS_Exception(
                __('No user has been defined.'));
        }
        // Use the option "administrator_email" instead of the current user.
        $value = get_option('administrator_email') ?: $user->email;
        $result = $targetDb->insert('setting', array('id' => 'administrator_email', 'value' => json_encode($value)));
        $result = $result && $targetDb->insert('setting', array('id' => 'installation_title', 'value' => json_encode($this->getParam('installation_title'))));
        $result = $result && $targetDb->insert('setting', array('id' => 'time_zone', 'value' => json_encode($this->getParam('time_zone'))));
        if (!$result) {
            throw new UpgradeToOmekaS_Exception(
                __('Unable to insert default settings.'));
        }
        $this->_log('[' . __FUNCTION__ . ']: ' . __('Installer Task "%s" ended.', 'Add Default Settings'), Zend_Log::DEBUG);

        $this->_log('[' . __FUNCTION__ . ']: ' . __('The main tables are created and default data inserted.'), Zend_Log::INFO);
    }

    protected function _importSettings()
    {
        // Convert config.ini.

        // Settings of Omeka Classic: create first site.

        // application.ini: useless.

        // routes.ini
        $this->_log('[' . __FUNCTION__ . ']: ' . __('"routes.ini" is not upgradable currently (rarely customized).'), Zend_Log::WARN);
    }

    protected function _importUsers()
    {

    }

    protected function _importItemTypes()
    {

    }

    protected function _importCollections()
    {

    }

    protected function _importItems()
    {

    }

    protected function _importFiles()
    {

    }

    protected function _copyFiles()
    {

    }

    protected function _copyThemes()
    {
        // with theme media uploaded.
    }

    protected function _downloadCompatibilityModule()
    {
        // TODO Compatibility module.
    }

    protected function _unzipCompatibiltyModule()
    {
        // TODO Compatibility module.
    }

    protected function _installCompatibiltyModule()
    {
        // TODO Compatibility module.
    }
}
