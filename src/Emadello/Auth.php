<?php

namespace Emadello;

use Emadello\Api\AuthInterface;
use Emadello\Install\Install;
use \Emadello\Db;
use \Emadello\Validate;
use \DevCoder\DotEnv;
use \Composer\Factory;

class Auth implements AuthInterface {

  CONST COOKIE_EXPIRE = 60*60*24*30;  //30 days by default
  CONST COOKIE_PATH = "/";  //Avaible in whole domain
  CONST USER_TIMEOUT = 10;
  CONST LOGINURL = 'login.php';
  CONST FORCELOGIN = true;
  CONST TIMEZONE = 'Africa/Cairo';

  protected $db;
  protected $validate;
  protected $checkInstalled;
  protected $cookie_token_var;
  protected $cookie_secret_var;
  protected $proj_title;

  //Declarations
  public $logged_in = false;
  public $userinfo = array();  //The array holding all user info
  public $error = "";
  protected $cookie;
  protected $session;
  protected $server;

  public function __construct() {

    // Check installation
    $this->checkInstalled = new Install();

    date_default_timezone_set(self::TIMEZONE);
    $this->db = new Db();
    $this->validate = new Validate();

    if (!isset($_GET['logout']) && strpos($_SERVER['REQUEST_URI'],'auth') == false && strpos($_SERVER['REQUEST_URI'],'_POST') == false && strpos($_SERVER['REQUEST_URI'],'login') == false && strpos($_SERVER['REQUEST_URI'],'post') == false) {
      $_SESSION['ref'] = $_SERVER['REQUEST_URI'];
    }

    $this->cookie = $_COOKIE;
    $this->session = $_SESSION;
    $this->server = $_SERVER;

    $this->applyCookieNames();

    if(isset($_GET['logout'])) $this->logout();
    else $this->checkLogin();

  }

  protected function applyCookieNames() {
    $this->cookie_token_var = str_replace(" ", "", getenv('PROJ_TITLE')).'_AUTH';
    $this->cookie_secret_var = str_replace(" ", "", getenv('PROJ_TITLE')).'_SECRET';
  }

  //Check if user is logged in
  public function checkLogin() {

    if (isset($this->cookie[$this->cookie_token_var])) {

      $chk1 = $this->db->con()->prepare("SELECT * FROM ".AuthInterface::ACCESSTOKENS_TABLE." WHERE token = :token LIMIT 1");
      $chk1->bindValue("token", $this->cookie[$this->cookie_token_var]);
      $chk1->execute();

      if ($chk1->rowCount() > 0) {
        $userdata = $chk1->fetch(\PDO::FETCH_ASSOC);
        if (password_verify($userdata['secret'], $this->cookie[$this->cookie_secret_var])) {

          $this->userinfo = $this->getUserInfo($userdata['user_id']);
          $_SESSION['userinfo'] = $this->userinfo;
          $this->logged_in = true;
          return true;

        } else {
          // Bad cookie :( force logout
          $this->clearUserData();
          return false;
        }

      } else {
        // Bad cookie :( force logout
        $this->clearUserData();
        return false;
      }

    }
    if (isset($this->session['userinfo'])) {
      // Check if the user is still on the system
      $checkUserInfo = $this->getUserInfo($this->session['userinfo']['user_id']);
      if ($checkUserInfo['user_id'] > 0) {

        $this->userinfo = $checkUserInfo;
        $_SESSION['userinfo'] = $this->userinfo;
        $this->logged_in = true;
        return true;

      } else {

        $this->clearUserData();
        return false;
      }
    }

    return false;

  }

  // Login Function
  public function login($username, $password, $rememberme, $referto) {

    if (!$this->checkLogin()) {

      if (!$username || !$password) {
        $this->error = "Incorrect login information provided";
        return false;
      }

      $chk2 = $this->db->con()->prepare("SELECT user_id, password FROM ".AuthInterface::USERS_TABLE." WHERE email = :username OR phone = :username LIMIT 1");
      $chk2->bindValue("username", $username);
      $chk2->execute();

      if ($chk2->rowCount() > 0) {

        $predata = $chk2->fetch(\PDO::FETCH_ASSOC);

        if (password_verify($password, $predata['password'])) {

          $this->userinfo = $this->getUserInfo($predata['user_id']);
          $_SESSION['userinfo'] = $this->userinfo;
          $this->logged_in = true;

          if ($rememberme) {
            $token = $this->GUID();
            $secret = $this->GUID();
            $this->insertToken($this->userinfo['user_id'], $token, $secret);
            setcookie($this->cookie_token_var, $token, time()+self::COOKIE_EXPIRE, self::COOKIE_PATH);
            setcookie($this->cookie_secret_var, password_hash($secret, PASSWORD_DEFAULT), time()+self::COOKIE_EXPIRE, self::COOKIE_PATH);
          }
          if ($referto) {
            header("Location: ".$referto);
            exit();
          }
          return true;

        } else $this->error = "Incorrect login information provided";

      } else $this->error = "User not found in database";

    } else return false;

  }

  // Logout Function
	public function logout() {

    if ($this->checkLogin()) {
      $ref = $this->session['ref'];
      $this->clearUserData();
      header("Location: ".$ref);
      exit();
    }

	}

  // Get User Info
  public function getUserInfo($user_id) {

    $sql = $this->db->con()->prepare("SELECT user_id, name, email, phone, userlevel FROM ".AuthInterface::USERS_TABLE." WHERE user_id = :user_id LIMIT 1");
    $sql->bindValue("user_id", $user_id);
    $sql->execute();
    if ($sql->rowCount() > 0) return $sql->fetch(\PDO::FETCH_ASSOC);
    else return $this->getEmptyUser();
  }

  // Return Empty User Info
  public function getEmptyUser() {
    $user['user_id'] = 0;
    $user['name'] = null;
    $user['email'] = null;
    $user['phone'] = null;
    $user['userlevel'] = null;
    return $user;
  }

  public function GUID()
  {
      if (function_exists('com_create_guid') === true) return com_create_guid();
      return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
  }

  public function insertToken($user_id, $token, $secret){

		$hostname = gethostbyaddr($_SERVER['REMOTE_ADDR']);

		$query = $this->db->con()->prepare("INSERT INTO ".AuthInterface::ACCESSTOKENS_TABLE." VALUES (0, :user_id, :token, :secret, :hostname, ".time().")");
		$query->bindValue("user_id", $user_id);
		$query->bindValue("token", $token);
		$query->bindValue("secret", $secret);
		$query->bindValue("hostname", $hostname);
		$query->execute();
	}

  public function clearUserData() {

    $this->logged_in = false;
    unset($this->userinfo);
    unset($this->session['userinfo']);
    unset($_SESSION['userinfo']);
    setcookie($this->cookie_token_var, "", time()+self::COOKIE_EXPIRE, self::COOKIE_PATH);
    setcookie($this->cookie_secret_var,   "", time()+self::COOKIE_EXPIRE, self::COOKIE_PATH);

  }

  public function checkLoggedIn(array $userlevels) {
    if (!$this->checkLogin() || !in_array($this->userinfo['userlevel'], $userlevels)) {
      if (self::FORCELOGIN) {
        if (basename($_SERVER['PHP_SELF']) != self::LOGINURL) {
          header("Location: ".self::LOGINURL);
          exit();
        }
      } else {
        return false;
      }
    }
    return true;
  }
}

?>
