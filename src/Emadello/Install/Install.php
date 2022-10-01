<?php

namespace Emadello\Install;

use Emadello\Api\AuthInterface;
use \Emadello\Db;
use \DevCoder\DotEnv;
use \Composer\Factory;

class Install implements AuthInterface
{
  protected $db;
  protected $getData;
  protected $postData;
  protected $installed = true;
  protected $envFile = '/.env';
  public function __construct()
  {
    $this->getData = $_GET;
    $this->postData = $_POST;
    if (isset($this->postData['newEnvFile']) && $this->postData['newEnvFile'] == 1) $this->generateEnvFile();
    $this->checkEnvFile();
    $this->db = new Db();
    if (isset($this->postData['email']) && isset($this->postData['password']) && isset($this->getData['module']) && $this->getData['module'] == 'Auth' && isset($this->getData['forceInstall']) && $this->getData['forceInstall'] == 1) $this->installAdminUser();
    elseif (isset($this->getData['module']) && $this->getData['module'] == 'Auth' && isset($this->getData['forceInstall']) && $this->getData['forceInstall'] == 1) $this->beginInstall();
    $this->checkIfInstalled();
  }
  protected function checkEnvFile()
  {
    $reflection = new \ReflectionClass(\Composer\Autoload\ClassLoader::class);
    $this->projectPath = dirname($reflection->getFileName(), 3);
    if (!is_file($this->projectPath . '/' . $this->envFile)) {
      if (isset($this->getData['genEnvFile']) && $this->getData['genEnvFile'] == 1) {
        echo $this->drawEnvFileForm();
        exit();
      } else {
        echo '<br /><br /><br /><center>Missing env file<br /><br /><a href="?genEnvFile=1">Generate env file</a></center>';
        exit();
      }
    }
  }
  public function checkIfInstalled()
  {
    if (!$this->tableExists(AuthInterface::LOGINATTEMPTS_TABLE)) $this->forceInstall();
    if (!$this->tableExists(AuthInterface::ACCESSTOKENS_TABLE)) $this->forceInstall();
    if (!$this->tableExists(AuthInterface::USERS_TABLE)) $this->forceInstall();
    if (!$this->tableExists(AuthInterface::PERMS_TABLE)) $this->forceInstall();
    if (!$this->adminExists()) $this->forceInstall();
  }
  public function adminExists()
  {
    $chk = $this->db->con()->query("SELECT user_id FROM " . AuthInterface::USERS_TABLE . " WHERE userlevel = " . AuthInterface::ADMIN_USERLEVEL);
    if ($chk->rowCount() > 0) return true;
    return false;
  }
  public function forceInstall()
  {
    $output = '<center>
    <br /><b>ERROR - One or more of the main tables are missing<br /><br />
    <a href="' . basename($_SERVER['PHP_SELF']) . '?module=Auth&forceInstall=1">Click here to re-install system users</a></b>
    </center>';
    echo $output;
    exit();
  }
  public function tableExists($table)
  {
    try {
      $this->db->con();
      if (!$this->db->connected) {
        header("Location: dberror.php");
        exit();
      }
      $chk = $this->db->con()->query("SHOW TABLES LIKE '$table'");
      if ($chk->rowCount() > 0) return true;
    } catch (\PDOException $e) {
      return false;
    }
    return false;
  }
  public function beginInstall()
  {
    $output = '<center><br /><br />';
    try {
      $adminUserInstall = false;
      $this->db->con()->beginTransaction();
      $output .= '<b>Installing missing / corrupted tables</b><br /><br />';
      if (!$this->tableExists(AuthInterface::LOGINATTEMPTS_TABLE)) {
        // login attempts table does not exists
        $output .= 'Installing _login_attempts table ... ';
        $this->db->con()->query(\file_get_contents(__DIR__ . '/_login_attempts.sql'));
        $output .= 'Done<br />';
      }
      if (!$this->tableExists(AuthInterface::ACCESSTOKENS_TABLE)) {
        // login attempts table does not exists
        $output .= 'Installing _users_tokens table ... ';
        $this->db->con()->query(\file_get_contents(__DIR__ . '/_users_tokens.sql'));
        $output .= 'Done<br />';
      }
      if (!$this->tableExists(AuthInterface::USERS_TABLE)) {
        // users table does not exists
        $adminUserInstall = true;
        $output .= 'Installing _users table ... ';
        $this->db->con()->query(\file_get_contents(__DIR__ . '/_users.sql'));
        $output .= 'Done<br />';
      }
      if (!$this->tableExists(AuthInterface::PERMS_TABLE)) {
        // permissions table does not exists
        $output .= 'Installing _users_perms table ... ';
        $this->db->con()->query(\file_get_contents(__DIR__ . '/_users_perms.sql'));
        $output .= 'Done<br />';
      }
      if (!$this->adminExists(1)) $adminUserInstall = true;
      if (!$adminUserInstall) $output .= "<br /><br /><b>Successfully Installed Tables</b><br /><br /><a href=\"index.php\">Run Web Application</a>";
      else $output .= $this->drawAdminCredsForm();
      $this->db->con()->commit();
    } catch (\PDOException $e) {
      $output .= "<b>ERROR Installing tables: " . $e->getMessage() . "</b><br /><br /><a href=\"index.php\">Back</a>";
      $this->db->con()->rollBack();
    }
    $output .= '</center>';
    echo $output;
    exit();
  }
  public function drawAdminCredsForm()
  {
    $output = '<form method="post">
    <h2>Admin Credentials</h2><br />
    <b>Email</b><br />
    <input type="email" name="email" value="' . ((isset($this->postData) && isset($this->postData['email'])) ? $this->postData['email'] : '') . '" style="padding:5px; width: 300px" /><br /><br />
    <b>Password</b><br />
    <input type="password" name="password" style="padding:5px; width: 300px" /><br /><br />
    <b>Confirm Password</b><br />
    <input type="password" name="cpassword" style="padding:5px; width: 300px" /><br /><br />
    <input type="submit" style="padding:10px; width:300px" value="Continue" />
    </form>';
    return $output;
  }
  public function drawEnvFileForm()
  {
    $output = '<center><form method="post">
    <h2>DB Credentials</h2><br />
    <b>Host</b><br />
    <input type="dbhost" name="dbhost" value="' . ((isset($this->postData) && isset($this->postData['dbhost'])) ? $this->postData['dbhost'] : 'localhost') . '" style="padding:5px; width: 300px" /><br /><br />
    <b>Port</b><br />
    <input type="dbport" name="dbport" value="' . ((isset($this->postData) && isset($this->postData['dbport'])) ? $this->postData['dbport'] : 3306) . '" style="padding:5px; width: 300px" /><br /><br />
    <b>DB Name</b><br />
    <input type="dbname" name="dbname" value="' . ((isset($this->postData) && isset($this->postData['dbname'])) ? $this->postData['dbname'] : '') . '" style="padding:5px; width: 300px" /><br /><br />
    <b>Username</b><br />
    <input type="dbuser" name="dbuser" value="' . ((isset($this->postData) && isset($this->postData['dbuser'])) ? $this->postData['dbuser'] : '') . '" style="padding:5px; width: 300px" /><br /><br />
    <b>Password</b><br />
    <input type="dbpass" name="dbpass" style="padding:5px; width: 300px" /><br /><br />
    <input type="hidden" name="newEnvFile" value=1 />
    <input type="submit" style="padding:10px; width:300px" value="Continue" />
    </form></center>';
    return $output;
  }
  public function installAdminUser()
  {
    $success = true;
    $error = '';
    if (!$this->postData['password']) {
      $success = false;
      $error = '<span style="color:red">Please enter a password</span>';
    }
    if (strlen($this->postData['password']) < 5) {
      $success = false;
      $error = '<span style="color:red">Password must be at least 5 characters long</span>';
    }
    if ($this->postData['password'] != $this->postData['cpassword']) {
      $success = false;
      $error = '<span style="color:red">Passwords do not match</span>';
    }
    if ($success) {
      // Install admin
      $sql = $this->db->con()->prepare("INSERT INTO " . AuthInterface::USERS_TABLE . " VALUES (
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?
        )");
      $sql->execute([0, $this->postData['email'], \password_hash($this->postData['password'], PASSWORD_DEFAULT), 'Administrator', '', 1, AuthInterface::ADMIN_USERLEVEL, 0]);
      echo "<center><br /><br /><b>Successfully Installed Tables</b><br /><br /><a href=\"index.php\">Run Web Application</a></center>";
      exit();
    } else {
      echo '<center>
      <br /><br />' . $error . '<br /><br />' . $this->drawAdminCredsForm() . '
      </center>';
      exit();
    }
  }
  protected function generateEnvFile()
  {
    $success = true;
    $error = '';
    if (!$this->postData['dbhost']) {
      $success = false;
      $error = '<span style="color:red">Please enter Host</span>';
    }
    if (!$this->postData['dbport']) {
      $success = false;
      $error = '<span style="color:red">Please enter Port</span>';
    }
    if (!$this->postData['dbname']) {
      $success = false;
      $error = '<span style="color:red">Please enter DB Name</span>';
    }
    if (!$this->postData['dbuser']) {
      $success = false;
      $error = '<span style="color:red">Please enter Username</span>';
    }
    if ($success) {
      $reflection = new \ReflectionClass(\Composer\Autoload\ClassLoader::class);
      $this->projectPath = dirname($reflection->getFileName(), 3);
      $newEnvFile = fopen($this->projectPath . '/' . $this->envFile, "w");
      $txt = "DBHOST=" . $this->postData['dbhost'] . "\n";
      $txt .= "DBPORT=" . $this->postData['dbport'] . "\n";
      $txt .= "DBNAME=" . $this->postData['dbname'] . "\n";
      $txt .= "DBUSER=" . $this->postData['dbuser'] . "\n";
      $txt .= "DBPASS=" . $this->postData['dbpass'] . "\n";
      $txt .= "DBKEY=\"" . $this->GUID() . "\"\n";
      fwrite($newEnvFile, $txt);
      fclose($newEnvFile);
    } else {
      echo '<center>
      <br /><br />' . $error . '<br /><br />' . $this->drawEnvFileForm() . '
      </center>';
      exit();
    }
  }
  protected function GUID()
  {
    if (function_exists('com_create_guid') === true) {
      return trim(com_create_guid(), '{}');
    }
    return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
  }
}
