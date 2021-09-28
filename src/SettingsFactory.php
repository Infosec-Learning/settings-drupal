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

  protected $landoInfo;

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
    if (Platform::getPlatform() === Platform::LANDO) {
      $this->landoInfo = json_decode(getenv('LANDO_INFO'));
    }
  }

  public function addContainerYaml($path) {
    $this->settings['container_yamls'][] = $path;
    return $this;
  }

  public function withDefaults() {
    $this->settings['update_free_access'] = FALSE;
    $this->settings['rebuild_access'] = FALSE;
    $this->settings['entity_update_batch_size'] = FALSE;
    $this->settings['extension_discovery_scan_tests'] = FALSE;
    $this
      ->addContainerYaml($this->appRoot . '/' . $this->sitePath . '/services.yml')
      ->withConfigSync($this->appRoot . '/../config')
      ->withFileScanIgnoreDirectories(
        'node_modules',
        'bower_components'
      )
      ->withPrivateFilePath($this->appRoot . '/../files-private')
      ->withTempFilePath('/tmp')
      ->withFast404()
    ;
    switch (Environment::getEnvironment()) {
      case Environment::LOCAL:
      case Environment::DEV:
        $this->config['system.logging']['error_level'] = 'verbose';
        $this->config['system.performance']['css']['preprocess'] = FALSE;
        $this->config['system.performance']['js']['preprocess'] = FALSE;
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
          ->includeSettings('/var/www/site-php/' . $_ENV['AH_SITE_GROUP'] . '/' . $_ENV['AH_SITE_GROUP'] . '-settings.inc')
          ->withConfigSync($this->appRoot . '/../config');
        break;
      case Platform::PANTHEON:
          $this
              ->withPrivateFilePath('sites/default/files/private')
              ->withTempFilePath(sys_get_temp_dir())
              ->withConfigSync($this->appRoot . '/../config');

          /**
           * Override the $databases variable to pass the correct Database credentials
           * directly from Pantheon to Drupal.
           *
           * Issue: https://github.com/pantheon-systems/drops-8/issues/8
           *
           */
          if (isset($_SERVER['PRESSFLOW_SETTINGS'])) {
              $pressflow_settings = json_decode($_SERVER['PRESSFLOW_SETTINGS'], TRUE);
              foreach ($pressflow_settings as $key => $value) {
                  // One level of depth should be enough for $conf and $database.
                  if ($key == 'conf') {
                      foreach($value as $conf_key => $conf_value) {
                          $this->config[$conf_key] = $conf_value;
                      }
                  }
                  elseif ($key == 'databases') {
                      // Protect default configuration but allow the specification of
                      // additional databases. Also, allows fun things with 'prefix' if they
                      // want to try multisite.
                      if (!isset($this->databases) || !is_array($this->databases)) {
                          $this->databases = array();
                      }
                      $this->databases = array_replace_recursive($this->databases, $value);
                  }
              }
          }

          /**
           * Place Twig cache files in the Pantheon rolling temporary directory.
           * A new rolling temporary directory is provided on every code deploy,
           * guaranteeing that fresh twig cache files will be generated every time.
           * Note that the rendered output generated from the twig cache files
           * are also cached in the database, so a cache clear is still necessary
           * to see updated results after a code deploy.
           */
          if (isset($_ENV['PANTHEON_ROLLING_TMP']) && isset($_ENV['PANTHEON_DEPLOYMENT_IDENTIFIER'])) {
              // Relocate the compiled twig files to <binding-dir>/tmp/ROLLING/twig.
              // The location of ROLLING will change with every deploy.
              $this->settings['php_storage']['twig']['directory'] = $_ENV['PANTHEON_ROLLING_TMP'];
              // Ensure that the compiled twig templates will be rebuilt whenever the
              // deployment identifier changes.  Note that a cache rebuild is also necessary.
              $this->settings['deployment_identifier'] = $_ENV['PANTHEON_DEPLOYMENT_IDENTIFIER'];
              $this->settings['php_storage']['twig']['secret'] = $_ENV['DRUPAL_HASH_SALT'] . $this->settings['deployment_identifier'];
          }

          /**
           * Install the Pantheon Service Provider to hook Pantheon services into
           * Drupal 8. This service provider handles operations such as clearing the
           * Pantheon edge cache whenever the Drupal cache is rebuilt.
           */
          $GLOBALS['conf']['container_service_providers']['PantheonServiceProvider'] = '\Pantheon\Internal\PantheonServiceProvider';

          /**
           * "Trusted host settings" are not necessary on Pantheon; traffic will only
           * be routed to your site if the host settings match a domain configured for
           * your site in the dashboard.
           */
          $this->settings['trusted_host_patterns'][] = '.*';

          /**
           * Load secrets file (workaround for ENV variables in pantheon)
           */
          $secrets_file = $_SERVER['HOME'] . '/files/private/secrets.json';
          if (file_exists($secrets_file)) {
              $pantheon_secrets = json_decode(file_get_contents($secrets_file), 1);
              foreach ($pantheon_secrets as $pantheon_secret_key => $pantheon_secret_value) {
                  $_ENV[$pantheon_secret_key] = $pantheon_secret_value;
              }
          }
          break;
      case Platform::LANDO:
        $this->withDatabase(...$this->getDatabaseFromLandoInfo());
        if (isset($this->landoInfo->index)) {
          $this->withSolr(...$this->getSolrFromLandoInfo());
        }
        $this->withLocalSettings();
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
    $database = $this->landoInfo->database;
    return [
      $database->internal_connection->host,
      $database->internal_connection->port,
      $database->creds->database,
      $database->creds->user,
      $database->creds->password,
    ];
  }

  protected function getSolrFromLandoInfo() {
    $solr = $this->landoInfo->index;
    return [
      $solr->internal_connection->host,
      $solr->internal_connection->port,
      '/solr',
      NULL,
      NULL,
      NULL,
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

  public function withSolr(
    $host,
    $port,
    $path,
    $core,
    $username = NULL,
    $password = NULL
  ) {
    $_ENV['SOLR_HOST'] = $host;
    $_ENV['SOLR_PORT'] = $port;
    $_ENV['SOLR_PATH'] = $path;
    $_ENV['SOLR_CORE'] = $core;
    $_ENV['SOLR_USER'] = $username;
    $_ENV['SOLR_PASSWORD'] = $password;
    return $this;
  }

  public function withRedis($host, $port, $password) {
      // Include the Redis services.yml file. Adjust the path if you installed to a contrib or other subdirectory.
      $this->settings['container_yamls'][] = 'modules/contrib/redis/example.services.yml';

      //phpredis is built into the Pantheon application container.
      $this->settings['redis.connection']['interface'] = 'PhpRedis';
      // These are dynamic variables handled by Pantheon.
      $this->settings['redis.connection']['host'] = $host;
      $this->settings['redis.connection']['port'] = $port;
      $this->settings['redis.connection']['password'] = $password;

      $this->settings['redis_compress_length'] = 100;
      $this->settings['redis_compress_level'] = 1;

      $this->settings['cache']['default'] = 'cache.backend.redis'; // Use Redis as the default cache.
      $this->settings['cache_prefix']['default'] = 'drupal-redis';

      $this->settings['cache']['bins']['form'] = 'cache.backend.database'; // Use the database for forms
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
    return $this;
  }

}
