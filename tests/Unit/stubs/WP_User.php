<?php
/**
 * Unit test stand-in for a WordPress core class.
 *
 * @package Florank
 */

/**
 * Minimal WP_User: ID, login, role and capabilities.
 */
class WP_User {
	public $ID         = 0;
	public $user_login = '';
	public $roles      = array();
	public $caps       = array();

	public function __construct( $id = 0, $login = '', $roles = array(), $caps = array() ) {
		$this->ID         = (int) $id;
		$this->user_login = $login;
		$this->roles      = $roles;
		$this->caps       = $caps;
	}

	public function exists() {
		return $this->ID > 0;
	}
}
