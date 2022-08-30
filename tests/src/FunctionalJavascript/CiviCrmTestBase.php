<?php

namespace Drupal\Tests\civicrm\FunctionalJavascript;

use Drupal\Core\Database\Database;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for FunctionalJavascript tests.
 */
abstract class CiviCrmTestBase extends WebDriverTestBase {

  protected $defaultTheme = 'classy';

  protected static $modules = [
    'block',
    'civicrm',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalPlaceBlock('page_title_block');
    $this->drupalPlaceBlock('local_tasks_block');
    $this->drupalPlaceBlock('local_actions_block');
  }

  /**
   * {@inheritdoc}
   */
  protected function cleanupEnvironment() {
    parent::cleanupEnvironment();
    $civicrm_test_conn = Database::getConnection('default', 'civicrm_test');
    // Disable foreign key checks so that tables can be dropped.
    $civicrm_test_conn->query('SET FOREIGN_KEY_CHECKS = 0;')->execute();
    $civicrm_schema = $civicrm_test_conn->schema();
    $tables = $civicrm_schema->findTables('%');
    // Comment out if you want to view the tables/contents before deleting them
    // throw new \Exception(var_export($tables, TRUE));
    foreach ($tables as $table) {
      if ($civicrm_schema->dropTable($table)) {
        unset($tables[$table]);
      }
    }
    $civicrm_test_conn->query('SET FOREIGN_KEY_CHECKS = 1;')->execute();
  }

  /**
   * @inheritdoc
   */
  protected function prepareDatabasePrefix() {
    if ($this->snapshotExists()) {
      $this->databasePrefix = file_get_contents($this->getHtmlOutputDirectory() . '/snapshot.prefix');
      $test_db = new \Drupal\Core\Test\TestDatabase($this->databasePrefix);
      $this->siteDirectory = $test_db->getTestSitePath();
    }
    else {
      parent::prepareDatabasePrefix();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function changeDatabasePrefix() {
    parent::changeDatabasePrefix();
    $connection_info = Database::getConnectionInfo('default');
    // CiviCRM does not leverage table prefixes, so we unset it. This way any
    // `civicrm_` tables are more easily cleaned up at the end of the test.
    // @todo - this maybe used to do something, but does it do anything now since it seems like the
    // connection gets removed and replaced later anyway. The assert farther below is still useful.
    $civicrm_connection_info = $connection_info['default'];
    unset($civicrm_connection_info['prefix']);
    Database::addConnectionInfo('civicrm_test', 'default', $civicrm_connection_info);
    Database::addConnectionInfo('civicrm', 'default', $civicrm_connection_info);

    // Assert that there are no `civicrm_` tables in the test database.
    $connection = Database::getConnection('default', 'civicrm_test');
    $schema = $connection->schema();
    $tables = $schema->findTables('civicrm_%');
    if (count($tables) > 0) {
      throw new \RuntimeException('The provided database connection in SIMPLETEST_DB contains CiviCRM tables, use a different database.');
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function prepareSettings() {
    parent::prepareSettings();

    // Set the test environment variables for CiviCRM.
    $filename = $this->siteDirectory . '/settings.php';
    chmod($filename, 0666);

    $constants = <<<CONSTANTS

if (!defined('CIVICRM_CONTAINER_CACHE')) {
  define('CIVICRM_CONTAINER_CACHE', 'never');
}
if (!defined('CIVICRM_TEST')) {
  define('CIVICRM_TEST', 1);
}

CONSTANTS;

    file_put_contents($filename, $constants, FILE_APPEND);
  }

  /**
   * @inheritdoc
   */
  protected function installDefaultThemeFromClassProperty(ContainerInterface $container) {
    if (!$this->snapshotExists()) {
      parent::installDefaultThemeFromClassProperty($container);
    }
  }

  /**
   * @inheritdoc
   */
  protected function installModulesFromClassProperty(ContainerInterface $container) {
    if (!$this->snapshotExists()) {
      parent::installModulesFromClassProperty($container);
    }
  }

  /**
   * @inheritdoc
   */
  public function installDrupal() {
    // The parent will call doInstall(), which we override below.
    parent::installDrupal();
    if (!$this->snapshotExists()) {
      $this->createSnapshot();
    }
  }

  /**
   * @inheritdoc
   */
  protected function doInstall() {
    // This happens during installDrupal()
    if (!$this->loadSnapshot()) {
      parent::doInstall();
    }
  }

  protected function snapshotExists(): bool {
    return file_exists($this->getHtmlOutputDirectory() . "/snapshot.sql");
  }

  protected function loadSnapshot(): bool {
    $snapshotFile = $this->getHtmlOutputDirectory() . "/snapshot.sql";
    if (file_exists($snapshotFile)) {
      // Because tearDown() cleans up all the tables, we can assume if we're here on a second or later
      // test that the db will be empty. So just go ahead and load it. We've already done a check earlier
      // that we're not accidentally overwriting a db we might want.
      $connection_info = Database::getConnectionInfo('default');
      $connection_info = $this->getEscapedDbArgs($connection_info['default']);
      $path = escapeshellarg(realpath($snapshotFile));
      // @todo Is there a drupal function to load a dump?
      $cmd = sprintf('mysql -u %s %s %s %s %s < %s',
        $connection_info['username'],
        empty($connection_info['password']) ? '' : "--password={$connection_info['password']}",
        empty($connection_info['host']) ? '' : "--host={$connection_info['host']}",
        empty($connection_info['port']) ? '' : "--port={$connection_info['port']}",
        $connection_info['database'],
        $path
      );
      exec($cmd);
      copy($this->getHtmlOutputDirectory() . '/snapshot.settings', $this->siteDirectory . '/settings.php');
      copy($this->getHtmlOutputDirectory() . '/snapshot.civicrm.settings', $this->siteDirectory . '/civicrm.settings.php');
      return TRUE;
    }
    return FALSE;
  }

  protected function createSnapshot(): void {
    $connection_info = Database::getConnectionInfo('default');
    $connection_info = $this->getEscapedDbArgs($connection_info['default']);
    $path = realpath($this->getHtmlOutputDirectory());
    if ($path) {
      $path = escapeshellarg($path . DIRECTORY_SEPARATOR . 'snapshot.sql');
      // @todo Is there a drupal function to do a dump?
      $cmd = sprintf('mysqldump --no-tablespaces --triggers --routines -u %s --result-file=%s %s %s %s %s %s',
        $connection_info['username'],
        $path,
        empty($connection_info['password']) ? '' : "--password={$connection_info['password']}",
        empty($connection_info['host']) ? '' : "--host={$connection_info['host']}",
        empty($connection_info['port']) ? '' : "--port={$connection_info['port']}",
        $this->doWeNeedStatistics(),
        $connection_info['database']
      );
      exec($cmd);
      file_put_contents($this->getHtmlOutputDirectory() . '/snapshot.prefix', $this->databasePrefix);
      copy($this->siteDirectory . '/settings.php', $this->getHtmlOutputDirectory() . '/snapshot.settings');
      copy($this->siteDirectory . '/civicrm.settings.php', $this->getHtmlOutputDirectory() . '/snapshot.civicrm.settings');
    }
  }

  /**
   * Convenience function
   * @param array $c A database connection array like in settings.php
   * @return array
   */
  private function getEscapedDbArgs(array $c): array {
    $c['database'] = escapeshellarg($c['database']);
    $c['username'] = escapeshellarg($c['username']);
    $c['password'] = empty($c['password']) ? '' : escapeshellarg($c['password']);
    $c['host'] = empty($c['host']) ? '' : escapeshellarg($c['host']);
    $c['port'] = empty($c['port']) ? '' : escapeshellarg($c['port']);
    return $c;
  }

  /**
   * On mysqldump 8 it seems there's a parameter you have to give because if the server is mariadb
   * or mysql 5 then it fails, but the parameter doesn't exist for mysqldump 5.
   */
  private function doWeNeedStatistics(): string {
    $version = `mysqldump --version`;
    if (strpos($version, 'Ver 8') !== FALSE) {
      return '--column-statistics=0';
    }
    return '';
  }

  /**
   * At the point we need $this->htmlOutputDirectory, it isn't initialized yet. But it's hardcoded
   * in the core trait anyway so we know what it is.
   * @return string
   */
  private function getHtmlOutputDirectory(): string {
    return DRUPAL_ROOT . '/sites/simpletest/browser_output';
  }

}
