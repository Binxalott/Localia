<?php
/**
 * Database tables.
 *
 * @package Localia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Localia_DB {

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'localia_' . $name;
	}

	public static function activate() {
		self::install();
		Localia_Settings::ensure_secret();
		Localia_Scheduler::schedule_events();
	}

	public static function maybe_upgrade() {
		if ( get_option( 'localia_db_version' ) !== LOCALIA_DB_VERSION ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$jobs    = self::table( 'jobs' );
		$nonces  = self::table( 'nonces' );
		$tasks   = self::table( 'tasks' );
		$log     = self::table( 'log' );

		// dbDelta is picky: one column per line, two spaces after PRIMARY KEY.
		dbDelta(
			"CREATE TABLE {$jobs} (
  id varchar(40) NOT NULL,
  status varchar(16) NOT NULL,
  priority int(11) NOT NULL DEFAULT 0,
  prompt longtext NULL,
  system_prompt longtext NULL,
  messages longtext NULL,
  model varchar(191) NULL,
  options text NULL,
  meta longtext NULL,
  attempts int(11) NOT NULL DEFAULT 0,
  max_attempts int(11) NOT NULL DEFAULT 2,
  not_before bigint(20) NOT NULL DEFAULT 0,
  agent_id varchar(64) NULL,
  lease_token varchar(64) NULL,
  lease_until bigint(20) NULL,
  response longtext NULL,
  error text NULL,
  result_meta text NULL,
  created_at bigint(20) NOT NULL,
  leased_at bigint(20) NULL,
  completed_at bigint(20) NULL,
  PRIMARY KEY  (id),
  KEY status_priority (status,priority,id)
) {$charset};
CREATE TABLE {$nonces} (
  agent_id varchar(64) NOT NULL,
  nonce varchar(64) NOT NULL,
  ts bigint(20) NOT NULL,
  PRIMARY KEY  (agent_id,nonce),
  KEY ts (ts)
) {$charset};
CREATE TABLE {$tasks} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(191) NOT NULL,
  type varchar(32) NOT NULL,
  enabled tinyint(1) NOT NULL DEFAULT 1,
  config longtext NULL,
  times text NULL,
  days varchar(32) NOT NULL DEFAULT '0,1,2,3,4,5,6',
  state longtext NULL,
  next_run_at bigint(20) NULL,
  last_run_at bigint(20) NULL,
  last_status varchar(16) NULL,
  last_job_id varchar(40) NULL,
  created_at bigint(20) NOT NULL,
  updated_at bigint(20) NOT NULL,
  PRIMARY KEY  (id),
  KEY due (enabled,next_run_at)
) {$charset};
CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at bigint(20) NOT NULL,
  level varchar(10) NOT NULL,
  message text NOT NULL,
  task_id bigint(20) unsigned NULL,
  post_id bigint(20) unsigned NULL,
  job_id varchar(40) NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at)
) {$charset};"
		);

		update_option( 'localia_db_version', LOCALIA_DB_VERSION );
	}
}
