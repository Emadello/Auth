<?php

namespace Emadello\Install;

use Emadello\Api\AuthInterface;
use \Emadello\Db;

class Install implements AuthInterface {

  protected $db;
  protected $getData;
  protected $postData;
  protected $installed = true;
  public function __construct() {
    $this->db = new Db();
    $this->getData = $_GET;
    $this->postData = $_POST;
    if ($this->postData['email'] && $this->postData['password'] && $this->getData['module'] == 'Auth' && $this->getData['forceInstall'] == 1) $this->installAdminUser();
    elseif ($this->getData['module'] == 'Auth' && $this->getData['forceInstall'] == 1) $this->beginInstall();
    $this->checkIfInstalled();
  }

  public function checkIfInstalled() {
    if (!$this->tableExists(AuthInterface::LOGINATTEMPTS_TABLE)) $this->forceInstall();
    if (!$this->tableExists(AuthInterface::ACCESSTOKENS_TABLE)) $this->forceInstall();
    if (!$this->tableExists(AuthInterface::USERS_TABLE)) $this->forceInstall();
    if (!$this->tableExists(AuthInterface::PERMS_TABLE)) $this->forceInstall();
    if (!$this->adminExists()) $this->forceInstall();
  }

  public function adminExists() {
    $chk = $this->db->con->query("SELECT user_id FROM ".AuthInterface::USERS_TABLE." WHERE userlevel = ".AuthInterface::ADMIN_USERLEVEL);
    if ($chk->rowCount() > 0) return true;

    return false;
  }

  public function forceInstall() {

    $output = '<center>
    <br /><b>ERROR - One or more of the main tables are missing<br /><br />
    <a href="'.basename($_SERVER['PHP_SELF']).'?module=Auth&forceInstall=1">Click here to re-install system users</a></b>
    </center>';

    echo $output;
    exit();

  }

  public function tableExists($table) {

    try {

      $chk = $this->db->con->query("SHOW TABLES LIKE '$table'");
      if ($chk->rowCount() > 0) return true;

    } catch (PDOException $e) {
      return false;
    }

    return false;
  }

  public function beginInstall() {

    $output = '<center><br /><br />';
    try {
      $adminUserInstall = false;
      $this->db->con->beginTransaction();
      $output .= '<b>Installing missing / corrupted tables</b><br /><br />';
      if (!$this->tableExists(AuthInterface::LOGINATTEMPTS_TABLE)) {
        // login attempts table does not exists
        $output .= 'Installing _login_attempts table ... ';
        $this->db->con->query(\file_get_contents(__DIR__.'/_login_attempts.sql'));
        $output .= 'Done<br />';
      }
      if (!$this->tableExists(AuthInterface::ACCESSTOKENS_TABLE)) {
        // login attempts table does not exists
        $output .= 'Installing _users_tokens table ... ';
        $this->db->con->query(\file_get_contents(__DIR__.'/_users_tokens.sql'));
        $output .= 'Done<br />';
      }
      if (!$this->tableExists(AuthInterface::USERS_TABLE)) {
        // users table does not exists
        $adminUserInstall = true;
        $output .= 'Installing _users table ... ';
        $this->db->con->query(\file_get_contents(__DIR__.'/_users.sql'));
        $output .= 'Done<br />';
      }
      if (!$this->tableExists(AuthInterface::PERMS_TABLE)) {
        // permissions table does not exists
        $output .= 'Installing _users_perms table ... ';
        $this->db->con->query(\file_get_contents(__DIR__.'/_users_perms.sql'));
        $output .= 'Done<br />';
      }

      if (!$this->adminExists(1)) $adminUserInstall = true;

      if (!$adminUserInstall) $output .= "<br /><br /><b>Successfully Installed Tables</b><br /><br /><a href=\"index.php\">Run Web Application</a>";
      else $output .= $this->drawAdminCredsForm();
      $this->db->con->commit();
    } catch (PDOException $e) {

      $output .= "<b>ERROR Installing tables: ".$e->getMessage()."</b><br /><br /><a href=\"index.php\">Back</a>";
      $this->db->con->rollBack();

    }
    $output .= '</center>';
    echo $output;
    exit();

  }

  public function drawAdminCredsForm() {
    $output = '<form method="post">
    <h2>Admin Credentials</h2><br />
    <b>Email</b><br />
    <input type="email" name="email" value="'.$this->postData['email'].'" style="padding:5px; width: 300px" /><br /><br />
    <b>Password</b><br />
    <input type="password" name="password" style="padding:5px; width: 300px" /><br /><br />
    <b>Confirm Password</b><br />
    <input type="password" name="cpassword" style="padding:5px; width: 300px" /><br /><br />
    <input type="submit" style="padding:10px; width:300px" value="Continue" />
    </form>';
    return $output;
  }

  public function installAdminUser() {

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
      $sql = $this->db->con->prepare("INSERT INTO ".AuthInterface::USERS_TABLE." VALUES (
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
      <br /><br />'.$error.'<br /><br />'.$this->drawAdminCredsForm().'
      </center>';
      exit();
    }
  }
}
?>
