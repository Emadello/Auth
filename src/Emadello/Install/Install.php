<?php

namespace Emadello\Install;

use Emadello\Api\AuthInterface;
use \Emadello\Db;
use \PhpDevCommunity\DotEnv;
use \Composer\Factory;

class Install implements AuthInterface
{
  protected $db;
  protected $getData;
  protected $postData;
  protected $installed = true;
  protected $envFile = '/.env';
  protected $projectPath;
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
        echo '<center>';
        if (is_file('images/logo.svg')) {
          echo '<br /><br /><img src="images/logo.svg" style="width: 50%; max-width: 150px" /><br />';
        }
        echo '<b>ePanel</b></center>';
        echo '<br /><br /><br /><center>Missing env file<br /><br /><a href="?genEnvFile=1" style="padding: 20px; background: #EEE; display:inline-block; border: 1px solid #ddd; color: navy; text-decoration: none; ">Generate env file</a></center>';
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
    $output = '<center>';
    if (is_file('images/logo.svg')) {
      $output .= '<br /><br /><img src="images/logo.svg" style="width: 50%; max-width: 150px" /><br />';
    }
    $output .= '<b>ePanel</b></center>';
    $output .= '<center>
    <br /><b>One or more of the main tables are missing<br /><br />
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

    if (is_file('images/logo.svg')) {
      $output .= '<br /><br /><img src="images/logo.svg" style="width: 50%; max-width: 150px" /><br />';
    }
    $output .= '<b>ePanel</b><br /><br />';

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
      if (!$adminUserInstall) {
        $output .= "<br /><br /><b>Successfully Installed Tables</b><br /><br /><a href=\"index.php\">Run Web Application</a>";
      } else $output .= $this->drawAdminCredsForm();
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
    $output = '<center>';

    if (is_file('images/logo.svg')) {
      $output .= '<br /><br /><img src="images/logo.svg" style="width: 50%; max-width: 150px" /><br />';
    }
    $output .= '<b>ePanel</b>';

    $output .= '<form method="post">
    <h2>DB Credentials</h2><br />
    <b>Host</b> <span style="color:red">*</span><br />
    <input type="text" name="dbhost" value="' . ((isset($this->postData) && isset($this->postData['dbhost'])) ? $this->postData['dbhost'] : 'localhost') . '" style="padding:5px; width: 300px" /><br /><br />
    <b>Port</b> <span style="color:red">*</span><br />
    <input type="text" name="dbport" value="' . ((isset($this->postData) && isset($this->postData['dbport'])) ? $this->postData['dbport'] : 3306) . '" style="padding:5px; width: 300px" /><br /><br />
    <b>DB Name</b> <span style="color:red">*</span><br />
    <input type="text" name="dbname" value="' . ((isset($this->postData) && isset($this->postData['dbname'])) ? $this->postData['dbname'] : '') . '" style="padding:5px; width: 300px" /><br /><br />
    <b>Username</b> <span style="color:red">*</span><br />
    <input type="text" name="dbuser" value="' . ((isset($this->postData) && isset($this->postData['dbuser'])) ? $this->postData['dbuser'] : '') . '" style="padding:5px; width: 300px" /><br /><br />
    <b>Password</b><br />
    <input type="password" name="dbpass" style="padding:5px; width: 300px" /><br /><br />
    <h2>Project Settings</h2><br />
    <b>Project Title</b> <span style="color:red">*</span><br />
    <input type="text" name="project_title" value="' . ((isset($this->postData) && isset($this->postData['project_title'])) ? $this->postData['project_title'] : '') . '" style="padding:5px; width: 300px" /><br /><br />
    <b>Web Application URL</b> <span style="color:red">*</span><br />
    <input type="text" name="base_url" value="' . ((isset($this->postData) && isset($this->postData['base_url'])) ? $this->postData['base_url'] : '') . '" style="padding:5px; width: 300px" /><br /><br />
    <b>Media Folder</b> <span style="color:red">*</span><br />
    <input type="text" name="media_folder" value="' . ((isset($this->postData) && isset($this->postData['media_folder'])) ? $this->postData['media_folder'] : 'public/media') . '" style="padding:5px; width: 300px" /><br /><br />
    <b>Email From</b> <span style="color:red">*</span><br />
    <input type="text" name="email_from" value="' . ((isset($this->postData) && isset($this->postData['email_from'])) ? $this->postData['email_from'] : '') . '" style="padding:5px; width: 300px" /><br /><br />
    <b>Email To</b> <span style="color:red">*</span><br />
    <input type="text" name="email_to" value="' . ((isset($this->postData) && isset($this->postData['email_to'])) ? $this->postData['email_to'] : '') . '" style="padding:5px; width: 300px" /><br /><br />
    <b>Email Reply To</b> <span style="color:red">*</span><br />
    <input type="text" name="email_reply_to" value="' . ((isset($this->postData) && isset($this->postData['email_reply_to'])) ? $this->postData['email_reply_to'] : '') . '" style="padding:5px; width: 300px" /><br /><br />
    <b>Email No-Reply</b> <span style="color:red">*</span><br />
    <input type="text" name="email_no_reply" value="' . ((isset($this->postData) && isset($this->postData['email_no_reply'])) ? $this->postData['email_no_reply'] : '') . '" style="padding:5px; width: 300px" /><br /><br />
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
      echo '<center><br /><br />';

      if (is_file('images/logo.svg')) {
        echo '<br /><br /><img src="images/logo.svg" style="width: 50%; max-width: 150px" /><br />';
      }
      echo '<b>ePanel</b><br /><br />';

      
      echo "<br /><br /><b>Successfully Installed Tables</b><br /><br /><a href=\"index.php\">Run Web Application</a></center>";
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
    if (!$this->postData['project_title']) {
      $success = false;
      $error = '<span style="color:red">Please enter Project Title</span>';
    }
    if (!$this->postData['base_url']) {
      $success = false;
      $error = '<span style="color:red">Please enter Base URL</span>';
    }
    if (!$this->postData['media_folder']) {
      $success = false;
      $error = '<span style="color:red">Please enter Media Folder</span>';
    }
    if (!$this->postData['email_from']) {
      $success = false;
      $error = '<span style="color:red">Please enter Email From Address</span>';
    }
    if (!$this->postData['email_to']) {
      $success = false;
      $error = '<span style="color:red">Please enter Email To Address</span>';
    }
    if (!$this->postData['email_reply_to']) {
      $success = false;
      $error = '<span style="color:red">Please enter Email Reply To Address</span>';
    }
    if (!$this->postData['email_no_reply']) {
      $success = false;
      $error = '<span style="color:red">Please enter Email No-Reply Address</span>';
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
      $txt .= "BASE_PATH=\"" . $this->projectPath . "\"\n";
      $txt .= "TEMPLATE=\"native\"\n";
      $txt .= "WEBSITE_BASE_URL=\"" . $this->postData['base_url'] . "\"\n";
      $txt .= "MEDIA_FOLDER=\"" . $this->postData['media_folder'] . "\"\n";
      $txt .= "CP_BASE_URL=\"" . $this->postData['base_url'] . "/cp\"\n";
      $txt .= "PROJ_TITLE=\"" . $this->postData['project_title'] . "\"\n";
      $txt .= "EMAIL_FROM=\"" . $this->postData['email_from'] . "\"\n";
      $txt .= "EMAIL_TO=\"" . $this->postData['email_to'] . "\"\n";
      $txt .= "EMAIL_REPLYTO=\"" . $this->postData['email_reply_to'] . "\"\n";
      $txt .= "EMAIL_NOREPLY=\"" . $this->postData['email_no_reply'] . "\"\n";

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
