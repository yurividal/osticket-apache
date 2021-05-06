<?php
// (C) Campbell Software Solutions 2015
// Portions (C) 2006-2015 osTicket

//Configure settings from environmental variables

require('setup.inc.php');

require_once INC_DIR . 'class.installer.php';

$_SERVER['HTTP_ACCEPT_LANGUAGE'] = getenv("LANGUAGE") ?: "en-us";

$vars = array(
  'name'      => getenv("INSTALL_NAME")  ?: 'My Helpdesk',
  'email'     => getenv("INSTALL_EMAIL") ?: 'helpdesk@example.com',
  'url'       => getenv("INSTALL_URL")   ?: 'http://localhost:8080/',

  'fname'       => getenv("ADMIN_FIRSTNAME") ?: 'Admin',
  'lname'       => getenv("ADMIN_LASTNAME")  ?: 'User',
  'admin_email' => getenv("ADMIN_EMAIL")     ?: 'admin@example.com',
  'username'    => getenv("ADMIN_USERNAME")  ?: 'ostadmin',
  'passwd'      => getenv("ADMIN_PASSWORD")  ?: 'Admin1',
  'passwd2'     => getenv("ADMIN_PASSWORD")  ?: 'Admin1',

  'prefix'   => getenv("MYSQL_PREFIX")              ?: 'ost_',
  'dbhost'   => getenv("MYSQL_HOST")                ?: 'mysql',
  'dbport'   => getenv("MYSQL_PORT")                ?: 3306,
  'dbname'   => getenv("MYSQL_DATABASE")            ?: 'osticket',
  'dbuser'   => getenv("MYSQL_USER")                ?: 'osticket',
  'dbpass'   => getenv("MYSQL_PASSWORD")            ?: getenv("MYSQL_ENV_MYSQL_PASSWORD"),

  'smtp_host'       => getenv("SMTP_HOST")            ?: 'localhost',
  'smtp_port'       => getenv("SMTP_PORT")            ?: 25,
  'smtp_from'       => getenv("SMTP_FROM"),
  'smtp_tls'        => getenv("SMTP_TLS"),
  'smtp_tls_certs'  => getenv("SMTP_TLS_CERTS")       ?: '/etc/ssl/certs/ca-certificates.crt',
  'smtp_user'       => getenv("SMTP_USER"),
  'smtp_pass'       => getenv("SMTP_PASSWORD"),

  'cron_interval'   => getenv("CRON_INTERVAL")        ?: 5,

  'siri'     => getenv("INSTALL_SECRET"),
  'config'   => getenv("INSTALL_CONFIG") ?: '/var/www/html/include/ost-sampleconfig.php'
);

//Script settings
define('CONNECTION_TIMEOUT_SEC', 180);

function err($msg)
{
  fwrite(STDERR, "$msg\n");
  exit(1);
}

function boolToOnOff($v)
{
  return ((bool) $v) ? 'on' : 'off';
}

function convertStrToBool($varName, $default)
{
  global $vars;
  if ($vars[$varName] != '') {
    return $vars[$varName] == '1';
  }
  return $default;
}

// Override Helpdesk URL. Only applied during database installation.
define("URL", $vars['url']);

//Require files (must be done before any output to avoid session start warnings)
// chdir("/var/www/html/setup_hidden");
// require "/var/www/html/setup_hidden/setup.inc.php";
// require_once INC_DIR . 'class.installer.php';


/************************* OSTicket Installation *******************************************/

//Create installer class
define('OSTICKET_CONFIGFILE', '/var/www/html/include/ost-config.php');
$installer = new Installer(OSTICKET_CONFIGFILE); //Installer instance.

//Determine if using linked container
$linked = (bool)getenv("MYSQL_ENV_MYSQL_PASSWORD");

if (!$linked) {
  //Check mandatory connection settings provided
  if (!getenv("MYSQL_HOST")) {
    err('Missing required environmental variable MYSQL_HOST');
  }
  if (!getenv("MYSQL_PASSWORD")) {
    err('Missing required environmental variable: MYSQL_PASSWORD');
  }

  // Always set mysqli.default_port for osTicket db_connect
  ini_set('mysqli.default_port', $vars['dbport']);

  echo "Connecting to external MySQL server on ${vars['dbhost']}:${vars['dbport']}\n";
} else {
  echo "Using linked MySQL container\n";

  # MYSQL_PORT is a TCP uri injected by container linking. Use port specified in MYSQL_PORT_3306_TCP_PORT.
  $vars['dbport'] = getenv("MYSQL_PORT_3306_TCP_PORT");
}

//Wait for database connection
echo "Waiting for database TCP connection to become available...\n";
$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
$t = 0;
while (!@socket_connect($s, $vars['dbhost'], $vars['dbport']) && $t < CONNECTION_TIMEOUT_SEC) {
  $t++;
  if (($t % 15) == 0) {
    echo "Waited for $t seconds...\n";
  }
  sleep(1);
}
if ($t >= CONNECTION_TIMEOUT_SEC) {
  err("Timed out waiting for database TCP connection");
}

//Check database installation status
$db_installed = false;
echo "Connecting to database mysql://${vars['dbuser']}@${vars['dbhost']}/${vars['dbname']}\n";
if (!db_connect($vars['dbhost'], $vars['dbuser'], $vars['dbpass']))
  err(sprintf(__('Unable to connect to MySQL server: %s'), db_connect_error()));
elseif (explode('.', db_version()) < explode('.', $installer->getMySQLVersion()))
  err(sprintf(__('osTicket requires MySQL %s or later!'), $installer->getMySQLVersion()));
elseif (!db_select_database($vars['dbname']) && !db_create_database($vars['dbname'])) {
  err("Database doesn't exist");
} elseif (!db_select_database($vars['dbname'])) {
  err('Unable to select the database');
} else {
  $sql = 'SELECT * FROM `' . $vars['prefix'] . 'config` LIMIT 1';
  if (db_query($sql, false)) {
    $db_installed = true;
    echo "Database already installed\n";
  }
}

//Create secret if not set by env var and not previously stored
DEFINE('SECRET_FILE', '/data/secret.txt');
if (!$vars['siri']) {
  if (file_exists(SECRET_FILE)) {
    echo "Loading installation secret\n";
    $vars['siri'] = file_get_contents(SECRET_FILE);
  } else {
    echo "Generating new installation secret and saving\n";
    //Note that this randomly generated value is not intended to secure production sites!
    $vars['siri'] = substr(str_shuffle("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ01234567890_="), 0, 32);
    file_put_contents(SECRET_FILE, $vars['siri']);
  }
} else {
  echo "Using installation secret from INSTALL_SECRET environmental variable\n";
}

//Always rewrite config file in case MySQL details changed (e.g. ip address)
echo "Updating configuration file\n";
if (!$configFile = file_get_contents($vars['config'])) {
  err("Failed to load configuration file: {$vars['config']}");
};

$configFile = str_replace("define('OSTINSTALLED',FALSE);", "define('OSTINSTALLED',TRUE);", $configFile);
$configFile = str_replace('%ADMIN-EMAIL', $vars['admin_email'], $configFile);
$configFile = str_replace('%CONFIG-DBHOST', $vars['dbhost'] . ':' . $vars['dbport'], $configFile);
$configFile = str_replace('%CONFIG-DBNAME', $vars['dbname'], $configFile);
$configFile = str_replace('%CONFIG-DBUSER', $vars['dbuser'], $configFile);
$configFile = str_replace('%CONFIG-DBPASS', $vars['dbpass'], $configFile);
$configFile = str_replace('%CONFIG-PREFIX', $vars['prefix'], $configFile);
$configFile = str_replace('%CONFIG-SIRI', $vars['siri'], $configFile);

if (!file_put_contents($installer->getConfigFile(), $configFile)) {
  err("Failed to write configuration file");
}

//Perform database installation if required
if (!$db_installed) {
  echo "Installing database. Please wait...\n";
  if (!$installer->install($vars)) {
    $errors = $installer->getErrors();
    echo "Database installation failed. Errors:\n";
    foreach ($errors as $e) {
      echo "  $e\n";
    }
    exit(1);
  } else {
    echo "Database installation successful\n";
  }
}
