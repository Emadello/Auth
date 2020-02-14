<?php

namespace Emadello\Api;

/**
 * interface
 */
interface AuthInterface
{
  CONST USERS_TABLE = '_users';
  CONST ACCESSTOKENS_TABLE = '_users_tokens';
  CONST PERMS_TABLE = '_users_perms';
  CONST LOGINATTEMPTS_TABLE = '_login_attempts';

  CONST ADMIN_USERLEVEL = 9;
  CONST MOD_USERLEVEL = 8;
}
