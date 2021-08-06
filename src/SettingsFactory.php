<?php

namespace Centretek\Settings;

use Centretek\Environment\Environment;
use Centretek\Environment\Platform;

class SettingsFactory {

  protected $appRoot;
  protected $sitePath;
  protected $settings;
  protected $databases;
  protected $config;

  /**
   * @throws \Exception
   */
  public static function create($appRoot, $sitePath, &$settings, &$databases, &$config) {
    if (stripos(ini_get('variables_order'), 'E') === FALSE) {
      throw new \Exception('Environment variables global variable has not been populated. variables_order should include "E" in php.ini.');
    }
    return new static($appRoot, $sitePath, $settings, $databases, $config);
  }

  protected function __construct($appRoot, $sitePath, &$settings, &$databases, &$config) {
    $this->appRoot = $appRoot;
    $this->sitePath = $sitePath;
    $this->settings = &$settings;
    $this->databases = &$databases;
    $this->config = &$config;
  }

  public function addContainerYaml($path) {
    $this->settings['container_yamls'][] = $path;
    return $this;
  }

  public function withDefaults() {
    $this->settings['update_free_access'] = FALSE;
    $this->settings['rebuild_access'] = FALSE;
    $this->settings['entity_update_batch_size'] = FALSE;
    $this
      ->addContainerYaml($this->appRoot . '/' . $this->sitePath . '/services.yml')
      ->withConfigSync($this->appRoot . '/config')
      ->withFileScanIgnoreDirectories(
        'node_modules',
        'bower_components'
      )
      ->withPrivateFilePath($this->appRoot . '/files-private')
      ->withTempFilePath('/tmp')
      ->withFast404()
    ;
    switch (Environment::getEnvironment()) {
      case Environment::LOCAL:
      case Environment::DEV:
        $this->config['system.logging']['error_level'] = 'verbose';
        $this->config['system.performance']['css']['preprocess'] = FALSE;
        $this->config['system.performance']['js']['preprocess'] = FALSE;
        $this->settings['extension_discovery_scan_tests'] = TRUE;
        $this->settings['skip_permissions_hardening'] = TRUE;
        $this->addContainerYaml($this->appRoot . '/sites/services.dev.yml');
        break;
      case Environment::STAGING:
      case Environment::PROD:

        break;
    }
    switch (Platform::getPlatform()) {
      case Platform::ACQUIA:
        $this
          ->withPrivateFilePath('/mnt/files/' . $_ENV['AH_SITE_GROUP'] . '.' . $_ENV['AH_SITE_ENVIRONMENT'] . '/files-private')
          ->withTempFilePath('/mnt/gfs/' . $_ENV['AH_SITE_GROUP'] . '.' . $_ENV['AH_SITE_ENVIRONMENT'] . '/tmp')
          ->includeSettings('/var/www/site-php/' . $_ENV['AH_SITE_GROUP'] . '/' . $_ENV['AH_SITE_GROUP'] . '-settings.inc');
        break;
      case Platform::PANTHEON:

        break;
      case Platform::LANDO:
        $this
          ->withDatabase(...$this->getDatabaseFromLandoInfo())
          ->withLocalSettings()
        ;
        break;
    }
    return $this;
  }

  public function withFast404() {
    $this->config['system.performance']['fast_404']['exclude_paths'] = '/\/(?:styles)|(?:system\/files)\//';
    $this->config['system.performance']['fast_404']['paths'] = '/\.(?:txt|png|gif|jpe?g|css|js|ico|swf|flv|cgi|bat|pl|dll|exe|asp)$/i';
    $this->config['system.performance']['fast_404']['html'] = '<!DOCTYPE html><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1><p>The requested URL "@path" was not found on this server.</p></body></html>';
    return $this;
  }

  protected function getDatabaseFromLandoInfo() {
    $landoInfo = json_decode(getenv('LANDO_INFO'));
    $database = $landoInfo->database;
    return [
      $database->internal_connection->host,
      $database->internal_connection->port,
      $database->creds->database,
      $database->creds->user,
      $database->creds->password,
    ];
  }

  public function withDatabase(
    $host,
    $port,
    $database,
    $username,
    $password,
    $driver = 'mysql',
    $prefix = '',
    $collation = 'utf8mb4_general_ci'
  ) {
    $this->databases['default']['default'] = [
      'database' => $database,
      'username' => $username,
      'password' => $password,
      'host' => $host,
      'port' => $port,
      'driver' => $driver,
      'prefix' => $prefix,
      'collation' => $collation,
    ];
    return $this;
  }

  public function withPrivateFilePath($path) {
    $this->settings['file_private_path'] = $path;
    return $this;
  }

  public function withTempFilePath($path) {
    $this->settings['file_temp_path'] = $path;
    return $this;
  }

  public function withConfigSync($path) {
    $this->settings['config_sync_directory'] = $path;
    return $this;
  }

  public function withFileScanIgnoreDirectories(...$directories) {
    $this->settings['file_scan_ignore_directories'] = $directories;
    return $this;
  }

  public function withLocalSettings($path = NULL) {
    if (is_null($path)) {
      $path = $this->appRoot . '/' . $this->sitePath . '/settings.local.php';
    }
    $this->includeSettings($path);
    return $this;
  }

  protected function includeSettings($path) {
    $app_root = $this->appRoot;
    $site_path = $this->sitePath;
    $settings = &$this->settings;
    $databases = &$this->databases;
    $config = &$this->config;
    if (file_exists($path)) {
      include $path;
    }
  }

}
