<?php
/*
  Plugin Name: Limit Login Attempts
  Plugin URI: http://devel.kostdoktorn.se/limit-login-attempts
  Description: Limit rate of login attempts, including by way of cookies, for each IP.
  Author: Johan Eenfeldt
  Author URI: http://devel.kostdoktorn.se
  Version: 2.0beta3

  Copyright 2008, 2009 Johan Eenfeldt

  Thanks to Michael Skerwiderski for reverse proxy handling.

  Licenced under the GNU GPL:

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License as published by
  the Free Software Foundation; either version 2 of the License, or
  (at your option) any later version.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program; if not, write to the Free Software
  Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

/*
 * Constants
 */

/* Different ways to get remote address: direct & behind proxy */
define('LIMIT_LOGIN_DIRECT_ADDR', 'REMOTE_ADDR');
define('LIMIT_LOGIN_PROXY_ADDR', 'HTTP_X_FORWARDED_FOR');

/* Notify value checked against these in limit_login_sanitize_options() */
define('LIMIT_LOGIN_LOCKOUT_NOTIFY_ALLOWED', 'log,email');

/*
 * Variables
 *
 * Assignments are for default value -- change in admin page.
 */

$limit_login_options =
	array(
		  /* Are we behind a proxy? */
		  'client_type' => LIMIT_LOGIN_DIRECT_ADDR

		  /* Lock out after this many tries */
		  , 'allowed_retries' => 4

		  /* Lock out for this many seconds */
		  , 'lockout_duration' => 1200 // 20 minutes

		  /* Long lock out after this many lockouts */
		  , 'allowed_lockouts' => 4

		  /* Long lock out for this many seconds */
		  , 'long_duration' => 86400 // 24 hours

		  /* Reset failed attempts after this many seconds */
		  , 'valid_duration' => 86400 // 24 hours

		  /* Also limit malformed/forged cookies?
		   *
		   * NOTE: Only works in WP 2.7+, as necessary actions were added then.
		   */
		  , 'cookies' => true

		  /* Notify on lockout. Values: '', 'log', 'email', 'log,email' */
		  , 'lockout_notify' => 'log'

		  /* If notify by email, do so after this number of lockouts */
		  , 'notify_email_after' => 4

		  /* Enforce limit on new user registrations for IP */
		  , 'register_enforce' => true

		  /* Allow this many new user registrations ... */
		  , 'register_allowed' => 3

		  /* ... during this time */
		  , 'register_duration' => 86400 // 24 hours

		  /* Allow password reset using login name?
		   *
		   * NOTE: Only works in WP 2.6.5+, as necessary filter was added then.
		   */
		  , 'disable_pwd_reset_username' => true

		  /* ... for capability level_xx or higher */
		  , 'pwd_reset_username_limit' => 1

		  /* Allow password resets at all?
		   *
		   * NOTE: Only works in WP 2.6.5+, as necessary filter was added then.
		   */
		  , 'disable_pwd_reset' => false

		  /* ... for capability level_xx or higher */
		  , 'pwd_reset_limit' => 1
		  );

$limit_login_my_error_shown = false; /* have we shown our stuff? */
$limit_login_just_lockedout = false; /* started this pageload??? */
$limit_login_nonempty_credentials = false; /* user and pwd nonempty */

/* Level of the different roles. Used for descriptive purposes only */
$limit_login_level_role =
	array(0 => __('Subscriber','limit-login-attempts')
		  , 1 => __('Contributor','limit-login-attempts')
		  , 2 => __('Author','limit-login-attempts')
		  , 7 => __('Editor','limit-login-attempts')
		  , 10 => __('Administrator','limit-login-attempts'));

/*
 * Startup
 */

limit_login_setup();


/*
 * Functions start here
 */

/* Get options and setup filters & actions */
function limit_login_setup() {
	load_plugin_textdomain('limit-login-attempts'
						   , PLUGINDIR.'/'.dirname(plugin_basename(__FILE__)));

	limit_login_setup_options();

	/* Filters and actions */
	add_action('wp_login_failed', 'limit_login_failed');
	if (limit_login_option('cookies')) {
		add_action('plugins_loaded', 'limit_login_handle_cookies', 99999);
		add_action('auth_cookie_bad_hash', 'limit_login_failed_cookie');
		add_action('auth_cookie_bad_username', 'limit_login_failed_cookie');
	}
	add_filter('wp_authenticate_user', 'limit_login_wp_authenticate_user', 99999, 2);
	add_action('wp_authenticate', 'limit_login_track_credentials', 10, 2);
	add_action('login_head', 'limit_login_add_error_message');
	add_action('login_errors', 'limit_login_fixup_error_messages');
	add_action('admin_menu', 'limit_login_admin_menu');
	if (limit_login_option('register_enforce')) {
		add_filter('registration_errors', 'limit_login_filter_registration');
		add_filter('login_message', 'limit_login_filter_login_message');
	}
	if (limit_login_option('disable_pwd_reset') || limit_login_option('disable_pwd_reset_username')) {
		add_filter('allow_password_reset', 'limit_login_filter_pwd_reset', 10, 2);
	}
}


/* Get correct remote address */
function limit_login_get_address($type_name = '') {
	$type = $type_name;
	if (empty($type)) {
		$type = limit_login_option('client_type');
	}

	if (isset($_SERVER[$type])) {
		return $_SERVER[$type];
	}

	/*
	 * Not found. Did we get proxy type from option?
	 * If so, try to fall back to direct address.
	 */
	if ( empty($type_name) && $type == LIMIT_LOGIN_PROXY_ADDR
		 && isset($_SERVER[LIMIT_LOGIN_DIRECT_ADDR])) {

		/*
		 * NOTE: Even though we fall back to direct address -- meaning you
		 * can get a mostly working plugin when set to PROXY mode while in
		 * fact directly connected to Internet it is not safe!
		 *
		 * Client can itself send HTTP_X_FORWARDED_FOR header fooling us
		 * regarding which IP should be banned.
		 */

		return $_SERVER[LIMIT_LOGIN_DIRECT_ADDR];
	}
	
	return '';
}


/* Helpfunction to check ip in time array (lockout/valid)
 *
 * Returns true if array exists, ip is key in array, and value (time) is not
 * past.
 */
function limit_login_check_time($check_array, $ip = null) {
	if (!$ip)
		$ip = limit_login_get_address();

	return (is_array($check_array) && isset($check_array[$ip])
			&& time() <= $check_array[$ip]);
}


/* Is it ok to login? */
function is_limit_login_ok() {
	/* Test that there is not a (still valid) lockout on ip in lockouts array */
	return !limit_login_check_time(limit_login_get_array('lockouts'));
}


/* Check if it is ok to register new user */
function is_limit_login_reg_ok() {
	if (!limit_login_option('register_enforce')) {
		return true;
	}

	$ip = limit_login_get_address();

	/* not too many (valid) registrations? */
	$valid = limit_login_get_array('registrations_valid');
	$regs = limit_login_get_array('registrations');
	$allowed = limit_login_option('register_allowed');
	return (!limit_login_check_time($valid, $ip)
			|| !isset($regs[$ip]) || $regs[$ip] < $allowed);
}


/* Filter: allow login attempt? (called from wp_authenticate()) */
function limit_login_wp_authenticate_user($user, $password) {
	if (is_wp_error($user) || is_limit_login_ok() ) {
		return $user;
	}

	global $limit_login_my_error_shown;
	$limit_login_my_error_shown = true;

	$error = new WP_Error();
	$error->add('too_many_retries', limit_login_error_msg());
	return $error;
}


/*
 * Action: called in plugin_loaded (really early) to make sure we do not allow
 * auth cookies while locked out.
 */
function limit_login_handle_cookies() {
	if (is_limit_login_ok()) {
		return;
	}

	if (empty($_COOKIE[AUTH_COOKIE]) && empty($_COOKIE[SECURE_AUTH_COOKIE])
		&& empty($_COOKIE[LOGGED_IN_COOKIE])) {
		return;
	}

	wp_clear_auth_cookie();

	if (!empty($_COOKIE[AUTH_COOKIE])) {
		$_COOKIE[AUTH_COOKIE] = '';
	}
	if (!empty($_COOKIE[SECURE_AUTH_COOKIE])) {
		$_COOKIE[SECURE_AUTH_COOKIE] = '';
	}
	if (!empty($_COOKIE[LOGGED_IN_COOKIE])) {
		$_COOKIE[LOGGED_IN_COOKIE] = '';
	}
}


/* Action: failed cookie login wrapper for limit_login_failed() */
function limit_login_failed_cookie($arg) {
	limit_login_failed($arg);
	wp_clear_auth_cookie();
}

/*
 * Action when login attempt failed
 *
 * Increase nr of retries (if necessary). Reset valid value. Setup
 * lockout if nr of retries are above threshold. And more!
 */
function limit_login_failed($arg) {
	$ip = limit_login_get_address();

	$lockouts = limit_login_get_array('lockouts');
	if (limit_login_check_time($lockouts)) {
		/* if currently locked-out, do not add to retries */
		return;
	}

	/* Get the arrays with retries and retries-valid information */
	$retries = limit_login_get_array('retries');
	$valid = limit_login_get_array('retries_valid');

	/* Check validity and add one to retries */
	if (isset($retries[$ip]) && isset($valid[$ip]) && time() < $valid[$ip]) {
		$retries[$ip] ++;
	} else {
		$retries[$ip] = 1;
	}
	$valid[$ip] = time() + limit_login_option('valid_duration');

	/* lockout? */
	if($retries[$ip] % limit_login_option('allowed_retries') == 0) {
		global $limit_login_just_lockedout;

		$limit_login_just_lockedout = true;

		/* setup lockout, reset retries as needed */
		$retries_long = limit_login_option('allowed_retries')
			* limit_login_option('allowed_lockouts');
		if ($retries[$ip] >= $retries_long) {
			/* long lockout */
			$lockouts[$ip] = time() + limit_login_option('long_duration');
			unset($retries[$ip]);
			unset($valid[$ip]);
		} else {
			/* normal lockout */
			$lockouts[$ip] = time() + limit_login_option('lockout_duration');
		}

		/* try to find username which failed */
		$user = '';
		if (is_string($arg)) {
			/* action: wp_login_failed */
			$user = $arg;
		} elseif (is_array($arg) && array_key_exists('username', $arg)) {
			/* action: auth_cookie_bad_* */
			$user = $arg['username'];
		}

		/* do housecleaning and save values */
		limit_login_cleanup($retries, $lockouts, $valid);

		/* do any notification */
		limit_login_notify($user);

		/* increase statistics */
		$total = get_option('limit_login_lockouts_total');
		if ($total === false) {
			add_option('limit_login_lockouts_total', 1, '', 'no');
		} else {
			update_option('limit_login_lockouts_total', $total + 1);
		}
	} else {
		/* not lockout (yet!), do housecleaning and save values */
		limit_login_cleanup($retries, null, $valid);
	}
}


/* Clean up any old lockouts and old retries and save arrays */
function limit_login_cleanup($retries = null, $lockouts = null, $valid = null) {
	$now = time();
	$lockouts = !is_null($lockouts) ? $lockouts : limit_login_get_array('lockouts');

	/* remove old lockouts */
	foreach ($lockouts as $ip => $lockout) {
		if ($lockout < $now) {
			unset($lockouts[$ip]);
		}
	}
	limit_login_save_array('lockouts', $lockouts);

	/* remove retries that are no longer valid */
	$valid = !is_null($valid) ? $valid : limit_login_get_array('retries_valid');
	$retries = !is_null($retries) ? $retries : limit_login_get_array('retries');
	if (!empty($valid) && !empty($retries)) {
		foreach ($valid as $ip => $lockout) {
			if ($lockout < $now) {
				unset($valid[$ip]);
				unset($retries[$ip]);
			}
		}

		/* go through retries directly, if for some reason they've gone out of sync */
		foreach ($retries as $ip => $retry) {
			if (!isset($valid[$ip])) {
				unset($retries[$ip]);
			}
		}

		limit_login_save_array('retries', $retries);
		limit_login_save_array('retries_valid', $valid);
	}

	/* do the same for the registration arrays, if necessary */
	$valid = limit_login_get_array('registrations_valid');
	$regs = limit_login_get_array('registrations');
	if (!empty($valid) && !empty($regs)) {
		foreach ($valid as $ip => $until) {
			if ($until < $now) {
				unset($valid[$ip]);
				unset($regs[$ip]);
			}
		}

		/* go through registrations directly, if for some reason they've gone out of sync */
		foreach ($regs as $ip => $reg) {
			if (!isset($valid[$ip])) {
				unset($regs[$ip]);
			}
		}

		limit_login_save_array('registrations', $regs);
		limit_login_save_array('registrations_valid', $valid);
	}
}

/*
 * Handle bookkeeping when new user is registered
 *
 * Increase nr of registrations and reset valid value.
 */
function limit_login_reg_add() {
	if (!limit_login_option('register_enforce')) {
		return;
	}

	$ip = limit_login_get_address();

	/* Get the arrays with registrations and valid information */
	$regs = limit_login_get_array('registrations');
	$valid = limit_login_get_array('registrations_valid');

	/* Check validity and add one registration */
	if (isset($regs[$ip]) && isset($valid[$ip]) && time() < $valid[$ip]) {
		$regs[$ip] ++;
	} else {
		$regs[$ip] = 1;
	}
	$valid[$ip] = time() + limit_login_option('register_duration');

	limit_login_save_array('registrations', $regs);
	limit_login_save_array('registrations_valid', $valid);

	/* increase statistics? */
	if ($regs[$ip] >= limit_login_option('register_allowed')) {
		$total = get_option('limit_login_reg_lockouts_total');
		if ($total === false) {
			add_option('limit_login_reg_lockouts_total', 1, '', 'no');
		} else {
			update_option('limit_login_reg_lockouts_total', $total + 1);
		}
	}

	/* do housecleaning */
	limit_login_cleanup();
}


/* 
 * Filter: check if new registration is allowed, and filter error messages
 * to remove possibility to brute force user login
 */
function limit_login_filter_registration($errors) {
	global $limit_login_my_error_shown;

	$limit_login_my_error_shown = true;

	if (!is_limit_login_reg_ok()) {
		$errors = new WP_Error();
		$errors->add('lockout', limit_login_reg_error_msg());
		return $errors;
	}

	/*
	 * Not locked out. Now enforce error msg filter and, count attempt if there
	 * are no errors.
	 */

	if (!is_wp_error($errors)) {
		limit_login_reg_add();
		return $errors;
	}

	$codes = $errors->get_error_codes();

	if (count($codes) <= 1) {
		if (count($codes) == 0) {
			limit_login_reg_add();
		}
		return $errors;
	}

	/*
	 * If more than one error message (meaning both login and email was
	 * invalid) we strip any 'username_exists' message.
	 *
	 * This is to stop someone from trying different usernames with a known
	 * bad / empty email address.
	 */

	$key = array_search('username_exists', $codes);

	if ($key !== false) {
		unset($codes[$key]);

		$old_errors = $errors;
		$errors = new WP_Error();
		foreach ($codes as $key => $code) {
			$errors->add($code, $old_errors->get_error_message($code));
		}
	}

	return $errors;
}


/* Check if user have level capability */
function limit_login_user_has_level($userid, $level) {
	$userid = intval($userid);
	$level = intval($level);

	if ($userid <= 0) {
		return false;
	}

	$user = new WP_User($userid);

	return ($user && $user->has_cap($level));
}


/* Filter: enforce that password reset is allowed */
function limit_login_filter_pwd_reset($b, $userid) {
	$limit = null;

	/* What limit (max privilege level) to use, if any */
	if (limit_login_option('disable_pwd_reset')) {
		/* limit on all pwd resets */
		$limit = limit_login_option('pwd_reset_limit');
	}

	if (limit_login_option('disable_pwd_reset_username') && !strpos($_POST['user_login'], '@')) {
		/* limit on pwd reset using user name */
		$limit_username = limit_login_option('pwd_reset_username_limit');

		/* use lowest limit */
		if (is_null($limit) || $limit > $limit_username) {
			$limit = $limit_username;
		}
	}

	if (is_null($limit)) {
		/* Current reset not limited */
		return $b;
	}

	/* Test if user have this level */
	if (!limit_login_user_has_level($userid, $limit)) {
		return $b;
	}

	/* Not allowed -- use same error as retrieve_password() */
	$error = new WP_Error();
	$error->add('invalidcombo', __('<strong>ERROR</strong>: Invalid username or e-mail.', 'limit-login-attempts'));
	return $error;
}


/*
 * Notification functions
 */

/* Email notification of lockout to admin (if configured) */
function limit_login_notify_email($user) {
	$ip = limit_login_get_address();
	$retries = limit_login_get_array('retries');

	/* Check if we are at the right nr to do notification
	 * 
	 * Todo: this always sends notification on long lockout (when $retries[$ip]
	 * is reset).
	 */
	if ( isset($retries[$ip])
		 && ( ($retries[$ip] / limit_login_option('allowed_retries'))
			  % limit_login_option('notify_email_after') ) != 0 ) {
		return;
	}

	/* Format message. First current lockout duration */
	if (!isset($retries[$ip])) {
		/* longer lockout */
		$count = limit_login_option('allowed_retries')
			* limit_login_option('allowed_lockouts');
		$lockouts = limit_login_option('allowed_lockouts');
		$time = round(limit_login_option('long_duration') / 3600);
		$when = sprintf(__ngettext('%d hour', '%d hours', $time, 'limit-login-attempts'), $time);
	} else {
		/* normal lockout */
		$count = $retries[$ip];
		$lockouts = floor($count / limit_login_option('allowed_retries'));
		$time = round(limit_login_option('lockout_duration') / 60);
		$when = sprintf(__ngettext('%d minute', '%d minutes', $time, 'limit-login-attempts'), $time);
	}

	$subject = sprintf(__("[%s] Too many failed login attempts", 'limit-login-attempts')
					   , get_option('blogname'));
	$message = sprintf(__("%d failed login attempts (%d lockout(s)) from IP: %s"
						  , 'limit-login-attempts') . "\r\n\r\n"
					   , $count, $lockouts, $ip);
	if ($user != '') {
		$message .= sprintf(__("Last user attempted: %s", 'limit-login-attempts')
							 . "\r\n\r\n" , $user);
	}
	$message .= sprintf(__("IP was blocked for %s", 'limit-login-attempts'), $when);

	@wp_mail(get_option('admin_email'), $subject, $message);
}


/* Logging of lockout (if configured) */
function limit_login_notify_log($user) {
	$log = limit_login_get_array('logged');
	$ip = limit_login_get_address();

	/* can be written much simpler, if you do not mind php warnings */
	if (isset($log[$ip])) {
		if (isset($log[$ip][$user])) {	
			$log[$ip][$user]++;
		} else {
			$log[$ip][$user] = 1;
		}
	} else {
		$log[$ip] = array($user => 1);
	}
	limit_login_save_array('logged', $log);
}


/* Handle notification in event of lockout */
function limit_login_notify($user) {
	$args = explode(',', limit_login_option('lockout_notify'));

	if (empty($args)) {
		return;
	}

	foreach ($args as $mode) {
		switch (trim($mode)) {
		case 'email':
			limit_login_notify_email($user);
			break;
		case 'log':
			limit_login_notify_log($user);
			break;
		}
	}
}


/*
 * Handle (och filter) messages and errors shown
 */

/* Construct message for registration lockout */
function limit_login_reg_error_msg() {
	$msg = __('<strong>ERROR</strong>: Too many new user registrations.', 'limit-login-attempts') . ' ';
	return limit_login_error_msg('registrations_valid', $msg);
}


/* Filter: remove other registration error messages */
function limit_login_filter_login_message($content) {
	if (is_limit_login_reg_page() && !is_limit_login_reg_ok()) {
		return '';
	}

	return $content;
}


/* Construct informative error message */
function limit_login_error_msg($lockout_option = 'lockouts', $msg = '') {
	$ip = limit_login_get_address();
	$lockouts = limit_login_get_array($lockout_option);

	if ($msg == '') {
		$msg = __('<strong>ERROR</strong>: Too many failed login attempts.', 'limit-login-attempts') . ' ';
	}

	if (!isset($lockouts[$ip]) || time() >= $lockouts[$ip]) {
		/* Huh? No lockout? */
		$msg .= __('Please try again later.', 'limit-login-attempts');
		return $msg;
	}

	$when = ceil(($lockouts[$ip] - time()) / 60);
	if ($when > 60) {
		$when = ceil($when / 60);
		$msg .= sprintf(__ngettext('Please try again in %d hour.', 'Please try again in %d hours.', $when, 'limit-login-attempts'), $when);
	} else {
		$msg .= sprintf(__ngettext('Please try again in %d minute.', 'Please try again in %d minutes.', $when, 'limit-login-attempts'), $when);
	}

	return $msg;
}


/* Construct retries remaining message */
function limit_login_retries_remaining_msg() {
	$ip = limit_login_get_address();
	$retries = limit_login_get_array('retries');
	$valid = limit_login_get_array('retries_valid');

	/* Should we show retries remaining? */
	if (!isset($retries[$ip]) || !isset($valid[$ip]) || time() > $valid[$ip]) {
		/* no: no valid retries */
		return '';
	}
	if (($retries[$ip] % limit_login_option('allowed_retries')) == 0 ) {
		/* no: already been locked out for these retries */
		return '';
	}

	$remaining = max((limit_login_option('allowed_retries') - ($retries[$ip] % limit_login_option('allowed_retries'))), 0);
	return sprintf(__ngettext("<strong>%d</strong> attempt remaining.", "<strong>%d</strong> attempts remaining.", $remaining, 'limit-login-attempts'), $remaining);
}


/* Return current (error) message to show, if any */
function limit_login_get_message() {
	if (!is_limit_login_ok()) {
		return limit_login_error_msg();
	}

	return limit_login_retries_remaining_msg();
}


/* Should we show errors and messages on this page? */
function should_limit_login_show_msg() {
	if (isset($_GET['key'])) {
		/* reset password */
		return false;
	}

	$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

	return ( $action != 'lostpassword' && $action != 'retrievepassword'
			 && $action != 'resetpass' && $action != 'rp'
			 && $action != 'register' );
}


/* Should we show errors and messages on this page? */
function is_limit_login_reg_page() {
	if (isset($_GET['key'])) {
		/* reset password */
		return false;
	}

	$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

	return ( $action == 'register' );
}


/* Fix up the error message before showing it */
function limit_login_fixup_error_messages($content) {
	global $limit_login_just_lockedout, $limit_login_nonempty_credentials, $limit_login_my_error_shown;

	if (!should_limit_login_show_msg()) {
		return $content;
	}

	/*
	 * During lockout we do not want to show any other error messages (like
	 * unknown user or empty password) -- unless this was the attempt that
	 * locked us out.
	 */
	if (!is_limit_login_ok() && !$limit_login_just_lockedout) {
		return limit_login_error_msg();
	}

	/*
	 * We want to filter the messages 'Invalid username' and 'Invalid password'
	 * as that is an information leak regarding user account names.
	 *
	 * Also, if there are more than one error message, put an extra <br /> tag
	 * between them.
	 */
	$msgs = explode("<br />\n", $content);

	if (strlen(end($msgs)) == 0) {
		/* remove last entry empty string */
		array_pop($msgs);
	}

	$count = count($msgs);
	$my_warn_count = $limit_login_my_error_shown ? 1 : 0;

	if ($limit_login_nonempty_credentials && $count > $my_warn_count) {
		/* Replace error message, including ours if necessary */
		$content = __('<strong>ERROR</strong>: Incorrect username or password.', 'limit-login-attempts') . "<br />\n";
		if ($limit_login_my_error_shown) {
			$content .= "<br />\n" . limit_login_get_message() . "<br />\n";
		}
		return $content;
	} elseif ($count <= 1) {
		return $content;
	}

	$new = '';
	while ($count-- > 0) {
		$new .= array_shift($msgs) . "<br />\n";
		if ($count > 0) {
			$new .= "<br />\n";
		}
	}

	return $new;
}


/* Add a message to login page when necessary */
function limit_login_add_error_message() {
	global $error, $limit_login_my_error_shown;

	if (is_limit_login_reg_page() && !is_limit_login_reg_ok()
		&& !$limit_login_my_error_shown) {
		$error = limit_login_reg_error_msg();
		return;
	}

	if (!should_limit_login_show_msg() || $limit_login_my_error_shown) {
		return;
	}

	$msg = limit_login_get_message();

	if ($msg != '') {
		$limit_login_my_error_shown = true;
		$error .= $msg;
	}

	return;
}


/* Keep track of if user or password are empty, to filter errors correctly */
function limit_login_track_credentials($user, $password) {
	global $limit_login_nonempty_credentials;

	$limit_login_nonempty_credentials = (!empty($user) && !empty($password));
}


/* Does wordpress version support cookie option? */
function limit_login_support_cookie_option() {
	global $wp_version;
	return (version_compare($wp_version, '2.7', '>='));
}


/* Does wordpress version support password reset options? */
function limit_login_support_pwd_reset_options() {
	global $wp_version;
	return (version_compare($wp_version, '2.6.5', '>='));
}


/*
 * Handle plugin options
 */

/* Get current option value */
function limit_login_option($option_name) {
	global $limit_login_options;

	if (isset($limit_login_options[$option_name])) {
		return $limit_login_options[$option_name];
	} else {
		return null;
	}
}


/* Only change var if option exists */
function limit_login_get_option($option, $var_name) {
	$a = get_option($option);

	if ($a !== false) {
		global $limit_login_options;

		/* Make sure type is correct */
		if (is_bool($limit_login_options[$var_name])) {
			$a = !!$a;
		} elseif (is_numeric($limit_login_options[$var_name])) {
			$a = intval($a);
		} else {
			$a = (string) $a;
		}

		$limit_login_options[$var_name] = $a;
	}
}


/* Setup global variables from options */
function limit_login_setup_options() {
	global $limit_login_options;

	foreach ($limit_login_options as $name => $value) {
		limit_login_get_option('limit_login_' . $name, $name);
	}

	limit_login_sanitize_options();
}


/* Update options in db from global variables */
function limit_login_update_options() {
	global $limit_login_options;

	foreach ($limit_login_options as $name => $value) {
		if (is_bool($value)) {
			$value = $value ? '1' : '0';
		}
		update_option('limit_login_' . $name, $value);
	}
}


/* Make sure the variables make sense */
function limit_login_sanitize_options() {
	global $limit_login_options;

	$notify_email_after = max(1, intval(limit_login_option('notify_email_after')));
	$limit_login_options['notify_email_after'] = min(limit_login_option('allowed_lockouts'), $notify_email_after);

	$args = explode(',', limit_login_option('lockout_notify'));
	$args_allowed = explode(',', LIMIT_LOGIN_LOCKOUT_NOTIFY_ALLOWED);
	$new_args = array();
	foreach ($args as $a) {
		if (in_array($a, $args_allowed)) {
			$new_args[] = $a;
		}
	}
	$limit_login_options['lockout_notify'] = implode(',', $new_args);

	$cookies = limit_login_option('cookies')
		&& limit_login_support_cookie_option() ? true : false;

	$limit_login_options['cookies'] = $cookies;

	if ( limit_login_option('client_type') != LIMIT_LOGIN_DIRECT_ADDR
		 && limit_login_option('client_type') != LIMIT_LOGIN_PROXY_ADDR ) {
		$limit_login_options['client_type'] = LIMIT_LOGIN_DIRECT_ADDR;
	}

	$pwd_reset_func_supported = limit_login_support_pwd_reset_options();
	$pwd_reset_username = limit_login_option('disable_pwd_reset_username')
		&& $pwd_reset_func_supported;
	$pwd_reset = limit_login_option('disable_pwd_reset')
		&& $pwd_reset_func_supported;

	$limit_login_options['disable_pwd_reset_username'] = $pwd_reset_username;
	$limit_login_options['disable_pwd_reset'] = $pwd_reset;
}


/* Get stored array -- add if necessary */
function limit_login_get_array($array_name) {
	$real_array_name = 'limit_login_' . $array_name;

	$a = get_option($real_array_name);

	if ($a === false) {
		$a = array();
		add_option($real_array_name, $a, '', 'no'); /* no autoload */
	}

	return $a;
}


/* Store array  */
function limit_login_save_array($array_name, $a) {
	$real_array_name = 'limit_login_' . $array_name;
	update_option($real_array_name, $a);
}


/*
 * Admin page stuff
 */

/* Add admin options page */
function limit_login_admin_menu() {
	add_options_page('Limit Login Attempts', 'Limit Login Attempts', 8, 'limit-login-attempts', 'limit_login_option_page');

	if ( $_GET['page'] == "limit-login-attempts" ) {	
		wp_enqueue_script('jquery');
	}
}


/* Make a guess if we are behind a proxy or not */
function limit_login_guess_proxy() {
	return isset($_SERVER[LIMIT_LOGIN_PROXY_ADDR])
		? LIMIT_LOGIN_PROXY_ADDR : LIMIT_LOGIN_DIRECT_ADDR;
}


/* Show log on admin page */
function limit_login_show_log($log) {
	if (!is_array($log) || count($log) == 0) {
		return;
	}

	echo('<tr><th scope="col">' . _c("IP|Internet address", 'limit-login-attempts') . '</th><th scope="col">' . __('Tried to log in as', 'limit-login-attempts') . '</th></tr>');
	foreach ($log as $ip => $arr) {
		echo('<tr><td class="limit-login-ip">' . $ip . '</td><td class="limit-login-max">');
		$first = true;
		foreach($arr as $user => $count) {
			$count_desc = sprintf(__ngettext('%d lockout', '%d lockouts', $count, 'limit-login-attempts'), $count);
			if (!$first) {
				echo(', ' . $user . ' (' .  $count_desc . ')');
			} else {
				echo($user . ' (' .  $count_desc . ')');
			}
			$first = false;
		}
		echo('</td></tr>');
	}
}


/* Remove space and - characters before comparing (because of how user_nicename
 * is constructed from user_login) */
function limit_login_fuzzy_cmp($s1, $s2) {
	$remove = array(' ', '-');

	return strcasecmp(str_replace($remove, '', $s1), str_replace($remove, '', $s2));
}


/* Show privileged users various names, and warn if equal to login name */
function limit_login_show_users() {
	global $wpdb;

	$sql = "SELECT u.ID, u.user_login, u.user_nicename, u.display_name"
		. " , um.meta_value AS role, um2.meta_value AS nickname"
		. " FROM $wpdb->users u"
		. " INNER JOIN $wpdb->usermeta um ON u.ID = um.user_id"
		. " LEFT JOIN $wpdb->usermeta um2 ON u.ID = um2.user_id"
		. " WHERE um.meta_key = '{$wpdb->prefix}capabilities'"
		. " AND NOT (um.meta_value LIKE '%subscriber%'"
		. "          OR um.meta_value LIKE '%unapproved%')"
		. " AND um2.meta_key = 'nickname'";

	$users = $wpdb->get_results($sql);

	if (!$users || count($users) == 0) {
		return;
	}

	$r = '';
	$bad_count = 0;
	foreach ($users as $user) {
		$login_ok = limit_login_fuzzy_cmp($user->user_login, 'admin');
		$display_ok = limit_login_fuzzy_cmp($user->user_login, $user->display_name);
		$nicename_ok = limit_login_fuzzy_cmp($user->user_login, $user->user_nicename);
		$nickname_ok = limit_login_fuzzy_cmp($user->user_login, $user->nickname);

		if (!($login_ok && $display_ok && $nicename_ok && $nickname_ok)) {
			$bad_count++;
		}

		$edit = "user-edit.php?user_id={$user->ID}";
		$nicename_input = '<input type="text" size="20" maxlength="45"'
			. " value=\"{$user->user_nicename}\" name=\"nicename-{$user->ID}\""
			. ' class="warning-disabled" disabled="true" />';

		$role = implode(',', array_keys(maybe_unserialize($user->role)));
		$login = limit_login_show_maybe_warning(!$login_ok, $user->user_login, $edit
					, __("Account named admin should not have privileges", 'limit-login-attempts'));
		$display = limit_login_show_maybe_warning(!$display_ok, $user->display_name, $edit
					, __("Make display name different from login name", 'limit-login-attempts'));
		$nicename = limit_login_show_maybe_warning(!$nicename_ok, $nicename_input, ''
					, __("Make url name different from login name", 'limit-login-attempts'));
		$nickname = limit_login_show_maybe_warning(!$nickname_ok, $user->nickname, $edit
					, __("Make nickname different from login name", 'limit-login-attempts'));

		$r .= '<tr><td>' . $edit_link . $login . '</a></td>'
			. '<td>' . $role . '</td>'
			. '<td>' . $display . '</td>'
			. '<td>' . $nicename . '</td>'
			. '<td>' . $nickname . '</td>'
			. '</tr>';
	}


	if (!$bad_count) {
		echo(sprintf('<p><i>%s</i></p>'
					 , __("Privileged usernames, display names, url names and nicknames are ok", 'limit-login-attempts')));
	}

	echo('<table class="widefat"><thead><tr class="thead">' 
		 . '<th scope="col">'
		 . __("User Login", 'limit-login-attempts')
		 . '</th><th scope="col">'
		 . __('Role', 'limit-login-attempts')
		 . '</th><th scope="col">'
		 . __('Display Name', 'limit-login-attempts')
		 . '</th><th scope="col">'
		 . __('URL Name <small>("nicename")</small>', 'limit-login-attempts')
		 . ' <a href="http://wordpress.org/extend/plugins/limit-login-attempts/faq/"'
		 . ' title="' . __('What is this?', 'limit-login-attempts') . '">?</a>'
		 . '</th><th scope="col">'
		 . __('Nickname', 'limit-login-attempts')
		 . '</th></tr></thead>'
		 . $r
		 . '</table>');
}


function limit_login_nicenames_from_post() {
	$match = 'nicename-'; /* followed by user id */
	$changed = '';

	foreach ($_POST as $name => $val) {
		if (strncmp($name, $match, strlen($match)))
			continue;

		/* Get user ID */
		$a = explode('-', $name);
		$id = intval($a[1]);
		if (!$id)
			continue;

		/*
		 * To be safe we use the same functions as when an original nicename is
		 * constructed from user login name.
		 */
		$nicename = sanitize_title(sanitize_user($val, true));

		if (empty($nicename))
			continue;

		/* Check against original user */
		$user = get_userdata($id);

		if (!$user)
			continue;

		/* nicename changed? */
		if (!strcmp($nicename, $user->user_nicename))
			continue;

		$userdata = array('ID' => $id, 'user_nicename' => $nicename);
		wp_update_user($userdata);

		wp_cache_delete($user->user_nicename, 'userlugs');

		if (!empty($changed))
			$changed .= ', ';
		$changed .= "'{$user->user_login}' nicename {$user->user_nicename} => $nicename";
	}

	if (!empty($changed)) {
		echo '<div id="message" class="updated fade"><p>'
			. __('URL names changed', 'limit-login-attempts')
			. '<br />' . $changed
			. '</p></div>';
	} else {
		echo '<div id="message" class="updated fade"><p>'
			. __('No names changed', 'limit-login-attempts')
			. '</p></div>';
	}
}


function limit_login_show_maybe_warning($is_warn, $name, $edit_url, $title) {
	static $alt, $bad_img_url;

	if (!$is_warn) {
		return $name;
	}

	if (empty($alt)) {
		$alt = __("bad name", 'limit-login-attempts');
	}

	if (empty($bad_img_url)) {
		if ( !defined('WP_PLUGIN_URL') )
			$plugin_url = get_option('siteurl') . '/wp-content/plugins';
		else
			$plugin_url = WP_PLUGIN_URL;

		$bad_img_url = $plugin_url . '/limit-login-attempts/images/icon_bad.gif';
	}

	$s = "<img src=\"$bad_img_url\" alt=\"$alt\" title=\"$title\" />";
	if (!empty($edit_url))
		$s .= "<a href=\"$edit_url\" title=\"$title\">";
	$s .= $name;
	if (!empty($edit_url))
		$s .= '</a>';

	return $s;
}


/* Count ip currently locked out from registering new users */
function limit_login_count_reg_lockouts() {
	$valid = limit_login_get_array('registrations_valid');
	$regs = limit_login_get_array('registrations');
	$allowed = limit_login_option('register_allowed');

	$now = time();
	$total = 0;

	foreach ($valid as $ip => $until) {
		if ($until >= $now && isset($regs[$ip]) && $regs[$ip] >= $allowed)
			$total++;
	}

	return $total;
}


/* Show all role levels <select> */
function limit_login_select_level($current) {
	global $limit_login_level_role;

	for ($i = 0; $i <= 10; $i++) {
		$selected = ($i == $current) ? ' SELECTED ' : '';
		$name = (array_key_exists($i, $limit_login_level_role)) ? ' - ' . $limit_login_level_role[$i] : '';
		echo("<option value=\"$i\" $selected>$i$name</option>");
	}
}


/* Get options from $_POST[] and update global options variable */
function limit_login_get_options_from_post() {
	global $limit_login_options;

	$option_multiple =
		array('lockout_duration' => 60, 'valid_duration' => 3600
			  , 'long_duration' => 3600, 'register_duration' => 3600);

	foreach ($limit_login_options as $name => $oldvalue) {
		if (is_bool($oldvalue)) {
			$value = isset($_POST[$name]) && $_POST[$name] == '1';
		} else {
			if (!isset($_POST[$name])) {
				continue;
			}

			$value = $_POST[$name];
			if (is_numeric($oldvalue)) {
				$value = intval($value);
			}
			if (array_key_exists($name, $option_multiple)) {
				$value = $value * $option_multiple[$name];
			}
		}

		$limit_login_options[$name] = $value;
	}

	/* Special handling for lockout_notify */
	$v = array();
	if (isset($_POST['lockout_notify_log'])) {
		$v[] = 'log';
	}
	if (isset($_POST['lockout_notify_email'])) {
		$v[] = 'email';
	}
	$limit_login_options['lockout_notify'] = implode(',', $v);
}


/* Actual admin page */
function limit_login_option_page()	{	
	limit_login_cleanup();

	if (!current_user_can('manage_options')) {
		wp_die('Sorry, but you do not have permissions to change settings.');
	}

	/* Make sure post was from this page */
	if (count($_POST) > 0) {
		check_admin_referer('limit-login-attempts-options');
	}
		
	/* Should we clear log? */
	if (isset($_POST['clear_log'])) {
		update_option('limit_login_logged', '');
		echo '<div id="message" class="updated fade"><p>'
			. __('Cleared IP log', 'limit-login-attempts')
			. '</p></div>';
	}
		
	/* Should we reset counter? */
	if (isset($_POST['reset_total'])) {
		update_option('limit_login_lockouts_total', 0);
		echo '<div id="message" class="updated fade"><p>'
			. __('Reset lockout count', 'limit-login-attempts')
			. '</p></div>';
	}

	/* Should we restore current lockouts? */
	if (isset($_POST['reset_current'])) {
		update_option('limit_login_lockouts', array());
		echo '<div id="message" class="updated fade"><p>'
			. __('Cleared current lockouts', 'limit-login-attempts')
			. '</p></div>';
	}
		
	/* Should we reset registration counter? */
	if (isset($_POST['reset_reg_total'])) {
		update_option('limit_login_reg_lockouts_total', 0);
		echo '<div id="message" class="updated fade"><p>'
			. __('Reset registration lockout count', 'limit-login-attempts')
			. '</p></div>';
	}

	/* Should we restore current registration lockouts? */
	if (isset($_POST['reset_reg_current'])) {
		update_option('limit_login_registrations', array());
		update_option('limit_login_registrations_valid', array());
		echo '<div id="message" class="updated fade"><p>'
			. __('Cleared current registration lockouts', 'limit-login-attempts')
			. '</p></div>';
	}

	/* Should we update options? */
	if (isset($_POST['update_options'])) {
		limit_login_get_options_from_post();
		limit_login_sanitize_options();
		limit_login_update_options();
		echo '<div id="message" class="updated fade"><p>'
			. __('Options changed', 'limit-login-attempts')
			. '</p></div>';
	}

	/* Should we change user nicenames?? */
	if (isset($_POST['users_submit'])) {
		limit_login_nicenames_from_post();
	}

	$lockouts_total = get_option('limit_login_lockouts_total', 0);
	$lockouts_now = count(limit_login_get_array('lockouts'));
	$reg_lockouts_total = get_option('limit_login_reg_lockouts_total', 0);
	$reg_lockouts_now = limit_login_count_reg_lockouts();

	if (!limit_login_support_cookie_option()) {
		$cookies_disabled = ' DISABLED ';
		$cookies_note = ' <br /> '
			. sprintf(__('<strong>NOTE:</strong> Only works in Wordpress %s or later'
						 , 'limit-login-attempts'), '2.7');
	} else {
		$cookies_disabled = '';
		$cookies_note = '';
	}
	$cookies_yes = limit_login_option('cookies') ? ' checked ' : '';

	$client_type = limit_login_option('client_type');
	$client_type_direct = $client_type == LIMIT_LOGIN_DIRECT_ADDR ? ' checked ' : '';
	$client_type_proxy = $client_type == LIMIT_LOGIN_PROXY_ADDR ? ' checked ' : '';

	$client_type_guess = limit_login_guess_proxy();

	if ($client_type_guess == LIMIT_LOGIN_DIRECT_ADDR) {
		$client_type_message = sprintf(__('It appears the site is reached directly (from your IP: %s)','limit-login-attempts'), limit_login_get_address(LIMIT_LOGIN_DIRECT_ADDR));
	} else {
		$client_type_message = sprintf(__('It appears the site is reached through a proxy server (proxy IP: %s, your IP: %s)','limit-login-attempts'), limit_login_get_address(LIMIT_LOGIN_DIRECT_ADDR), limit_login_get_address(LIMIT_LOGIN_PROXY_ADDR));
	}
	$client_type_message .= '<br />';

	$client_type_warning = '';
	if ($client_type != $client_type_guess) {
		$faq = 'http://wordpress.org/extend/plugins/limit-login-attempts/faq/';

		$client_type_warning = '<br /><br />' . sprintf(__('<strong>Current setting appears to be invalid</strong>. Please make sure it is correct. Further information can be found <a href="%s" title="FAQ">here</a>','limit-login-attempts'), $faq);
	}

	$v = explode(',', limit_login_option('lockout_notify')); 
	$log_checked = in_array('log', $v) ? ' checked ' : '';
	$email_checked = in_array('email', $v) ? ' checked ' : '';


	if (!limit_login_support_pwd_reset_options()) {
		$pwd_reset_options_disabled = ' DISABLED ';
		$pwd_reset_options_note = ' <br /> '
			. sprintf(__('<strong>NOTE:</strong> Only works in Wordpress %s or later'
						 , 'limit-login-attempts'), '2.6.5');
	} else {
		$pwd_reset_options_disabled = '';
		$pwd_reset_options_note = '';
	}

	$disable_pwd_reset_username_yes = limit_login_option('disable_pwd_reset_username') ? ' checked ' : '';
	$disable_pwd_reset_yes = limit_login_option('disable_pwd_reset') ? ' checked ' : '';

	$register_enforce_yes = limit_login_option('register_enforce') ? ' checked ' : '';

	?>
    <script type="text/javascript">
jQuery(document).ready(function(){
   jQuery("#warning_checkbox").click(function(event){
	   if (jQuery(this).attr("checked")) {
		   jQuery("input.warning-disabled").removeAttr("disabled");
	   } else {
		   jQuery("input.warning-disabled").attr("disabled", "disabled");
	   }
   });
});
    </script>
	<style type="text/css" media="screen">
		table.limit-login {
			width: 100%;
			border-collapse: collapse;
		}
		.limit-login th {
			font-size: 12px;
			font-weight: bold;
			text-align: left;
			padding: 0;
		}
		.limit-login td {
			font-size: 11px;
			line-height: 12px;
			padding: 1px 5px 1px 0;
		}
		td.limit-login-ip {
			font-family:  "Courier New", Courier, monospace;
			vertical-align: top;
		}
		td.limit-login-max {
			width: 100%;
		}
	</style>
	<div class="wrap">
	  <h2><?php echo __('Limit Login Attempts Settings','limit-login-attempts'); ?></h2>
	  <h3><?php echo __('Statistics','limit-login-attempts'); ?></h3>
	  <form action="options-general.php?page=limit-login-attempts" method="post">
		<?php wp_nonce_field('limit-login-attempts-options'); ?>
	    <table class="form-table">
		  <tr>
			<th scope="row" valign="top"><?php echo __('Total lockouts','limit-login-attempts'); ?></th>
			<td>
			  <?php if ($lockouts_total > 0) { ?>
			  <input name="reset_total" value="<?php echo __('Reset Counter','limit-login-attempts'); ?>" type="submit" />
			  <?php echo sprintf(__ngettext('%d lockout since last reset', '%d lockouts since last reset', $lockouts_total, 'limit-login-attempts'), $lockouts_total); ?>
			  <?php } else { echo __('No lockouts yet','limit-login-attempts'); } ?>
			</td>
		  </tr>
		  <?php if ($lockouts_now > 0) { ?>
		  <tr>
			<th scope="row" valign="top"><?php echo __('Active lockouts','limit-login-attempts'); ?></th>
			<td>
			  <input name="reset_current" value="<?php echo __('Restore Lockouts','limit-login-attempts'); ?>" type="submit" />
			  <?php echo sprintf(__('%d IP is currently blocked from trying to log in','limit-login-attempts'), $lockouts_now); ?> 
			</td>
		  </tr>
		  <?php } ?>
		  <?php if ($reg_lockouts_total > 0) { ?>
		  <tr>
			<th scope="row" valign="top"><?php echo __('Total registration lockouts','limit-login-attempts'); ?></th>
			<td>
			  <input name="reset_reg_total" value="<?php echo __('Reset Counter','limit-login-attempts'); ?>" type="submit" />
			  <?php echo sprintf(__ngettext('%d registration lockout since last reset', '%d registration lockouts since last reset', $reg_lockouts_total, 'limit-login-attempts'), $reg_lockouts_total); ?>
			</td>
		  </tr>
		  <?php } ?>
		  <?php if ($reg_lockouts_now > 0) { ?>
		  <tr>
			<th scope="row" valign="top"><?php echo __('Active registration lockouts','limit-login-attempts'); ?></th>
			<td>
			  <input name="reset_reg_current" value="<?php echo __('Restore Lockouts','limit-login-attempts'); ?>" type="submit" />
			  <?php echo sprintf(__('%d IP is currently blocked from registering new users','limit-login-attempts'), $reg_lockouts_now); ?> 
			</td>
		  </tr>
		  <?php } ?>
		</table>
	  </form>
	  <h3><?php echo __('Options','limit-login-attempts'); ?></h3>
	  <form action="options-general.php?page=limit-login-attempts" method="post">
		<?php wp_nonce_field('limit-login-attempts-options'); ?>
	    <table class="form-table">
		  <tr>
			<th scope="row" valign="top"><?php echo __('Lockout','limit-login-attempts'); ?></th>
			<td>
			  <input type="text" size="3" maxlength="4" value="<?php echo(limit_login_option('allowed_retries')); ?>" name="allowed_retries" /> <?php echo __('allowed retries','limit-login-attempts'); ?> <br />
			  <input type="text" size="3" maxlength="4" value="<?php echo(limit_login_option('lockout_duration')/60); ?>" name="lockout_duration" /> <?php echo __('minutes lockout','limit-login-attempts'); ?> <br />
			  <input type="text" size="3" maxlength="4" value="<?php echo(limit_login_option('allowed_lockouts')); ?>" name="allowed_lockouts" /> <?php echo __('lockouts increase lockout time to','limit-login-attempts'); ?> <input type="text" size="3" maxlength="4" value="<?php echo(limit_login_option('long_duration')/3600); ?>" name="long_duration" /> <?php echo __('hours','limit-login-attempts'); ?> <br />
			  <input type="text" size="3" maxlength="4" value="<?php echo(limit_login_option('valid_duration')/3600); ?>" name="valid_duration" /> <?php echo __('hours until retries are reset','limit-login-attempts'); ?>
			</td>
		  </tr>
		  <tr>
			<th scope="row" valign="top"><?php echo __('User cookie login','limit-login-attempts'); ?></th>
			<td>
			  <label><input type="checkbox" name="cookies" <?php echo $cookies_disabled . $cookies_yes; ?> value="1" /> <?php echo __('Handle cookie login','limit-login-attempts'); ?></label>
			  <?php echo $cookies_note ?>
			</td>
		  </tr>
		  <tr>
			<th scope="row" valign="top"><?php echo __('Site connection','limit-login-attempts'); ?></th>
			<td>
			  <?php echo $client_type_message; ?>
			  <label>
				<input type="radio" name="client_type" 
					   <?php echo $client_type_direct; ?> value="<?php echo LIMIT_LOGIN_DIRECT_ADDR; ?>" /> 
					   <?php echo __('Direct connection','limit-login-attempts'); ?> 
			  </label>
			  <label>
				<input type="radio" name="client_type" 
					   <?php echo $client_type_proxy; ?> value="<?php echo LIMIT_LOGIN_PROXY_ADDR; ?>" /> 
				  <?php echo __('From behind a reversy proxy','limit-login-attempts'); ?>
			  </label>
			  <?php echo $client_type_warning; ?>
			</td>
		  </tr>
		  <tr>
			<th scope="row" valign="top"><?php echo __('Notify on lockout','limit-login-attempts'); ?></th>
			<td>
			  <input type="checkbox" name="lockout_notify_log" <?php echo $log_checked; ?> value="log" /> <?php echo __('Log IP','limit-login-attempts'); ?><br />
			  <input type="checkbox" name="lockout_notify_email" <?php echo $email_checked; ?> value="email" /> <?php echo __('Email to admin after','limit-login-attempts'); ?> <input type="text" size="3" maxlength="4" value="<?php echo(limit_login_option('notify_email_after')); ?>" name="email_after" /> <?php echo __('lockouts','limit-login-attempts'); ?>
			</td>
		  </tr>
		  <tr>
			<th scope="row" valign="top"><?php echo __('Password reset','limit-login-attempts'); ?></th>
			<td>						
			  <label><input type="checkbox" name="disable_pwd_reset_username" <?php echo $pwd_reset_options_disabled . $disable_pwd_reset_username_yes; ?> value="1" /> <?php echo __('Disable password reset using login name for user this level or higher','limit-login-attempts'); ?></label> <select name="pwd_reset_username_limit" <?php echo $pwd_reset_options_disabled; ?> ><?php limit_login_select_level(limit_login_option('pwd_reset_username_limit')); ?></select>
			  <br />
			  <label><input type="checkbox" name="disable_pwd_reset" <?php echo $pwd_reset_options_disabled . $disable_pwd_reset_yes; ?> value="1" /> <?php echo __('Disable password reset for users this level or higher','limit-login-attempts'); ?></label> <select name="pwd_reset_limit" <?php echo $pwd_reset_options_disabled; ?> ><?php limit_login_select_level(limit_login_option('pwd_reset_limit')); ?></select>
			  <?php echo $pwd_reset_options_note; ?>
			</td>
		  </tr>
		  <tr>
			<th scope="row" valign="top"><?php echo __('New user registration','limit-login-attempts'); ?></th>
			<td>
			  <input type="checkbox" name="register_enforce" <?php echo $register_enforce_yes; ?> value="1" /> <?php echo __('Only allow','limit-login-attempts'); ?> <input type="text" size="3" maxlength="4" value="<?php echo(limit_login_option('register_allowed')); ?>" name="register_allowed" /> <?php echo __('new user registrations every','limit-login-attempts'); ?> <input type="text" size="3" maxlength="4" value="<?php echo(limit_login_option('register_duration')/3600); ?>" name="register_duration" /> <?php echo __('hours','limit-login-attempts'); ?>
			</td>
		  </tr>
		</table>
		<p class="submit">
		  <input name="update_options" value="<?php echo __('Change Options','limit-login-attempts'); ?>" type="submit" />
		</p>
	  </form>
	  <h3><?php echo __('Privileged users','limit-login-attempts'); ?></h3>
	  <form action="options-general.php?page=limit-login-attempts" method="post" name="form_users">
		<?php wp_nonce_field('limit-login-attempts-options'); ?>

		<?php limit_login_show_users(); ?>
		<div class="tablenav actions">
		  <input type="checkbox" id="warning_checkbox" name="warning_danger" value="1" name="users_warning_check" /> <?php echo sprintf(__('I <a href="%s">understand</a> the problems involved', 'limit-login-attempts'), 'http://wordpress.org/extend/plugins/limit-login-attempts/faq/'); ?></a> <input type="submit" class="button-secondary action warning-disabled" value="<?php echo __('Change Names', 'limit-login-attempts'); ?>" name="users_submit" disabled="true" />
		</div>
	  </form>
	  <?php
		$log = limit_login_get_array('logged');

		if (is_array($log) && count($log) > 0) {
	  ?>
	  <h3><?php echo __('Lockout log','limit-login-attempts'); ?></h3>
	  <div class="limit-login">
		<table>
		  <?php limit_login_show_log($log); ?>
		</table>
	  </div>
	  <form action="options-general.php?page=limit-login-attempts" method="post">
		<?php wp_nonce_field('limit-login-attempts-options'); ?>
		<input type="hidden" value="true" name="clear_log" />
		<p class="submit">
		  <input name="submit" value="<?php echo __('Clear Log','limit-login-attempts'); ?>" type="submit" />
		</p>
	  </form>
	  <?php
		} /* if showing $log */
	  ?>
	</div>	
	<?php		
}
?>
