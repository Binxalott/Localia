<?php
/**
 * Plugin Name:       Localia – Use Your Own AI
 * Plugin URI:        https://github.com/binxalott/localia
 * Description:       Write and update posts with the AI model running on your own home PC (Ollama, LM Studio and others). No API bills, no port forwarding.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Localia
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       localia
 *
 * @package Localia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LOCALIA_VERSION', '0.1.0' );
define( 'LOCALIA_DB_VERSION', '1' );
define( 'LOCALIA_FILE', __FILE__ );
define( 'LOCALIA_DIR', plugin_dir_path( __FILE__ ) );
define( 'LOCALIA_URL', plugin_dir_url( __FILE__ ) );

// Where the "Get Pro" buttons point. Change this one value once the website exists.
if ( ! defined( 'LOCALIA_PRO_URL' ) ) {
	define( 'LOCALIA_PRO_URL', 'https://example.com/localia-pro/' );
}

require_once LOCALIA_DIR . 'includes/class-localia-settings.php';
require_once LOCALIA_DIR . 'includes/class-localia-lang.php';
require_once LOCALIA_DIR . 'includes/class-localia-db.php';
require_once LOCALIA_DIR . 'includes/class-localia-log.php';
require_once LOCALIA_DIR . 'includes/class-localia-queue.php';
require_once LOCALIA_DIR . 'includes/class-localia-auth.php';
require_once LOCALIA_DIR . 'includes/class-localia-content.php';
require_once LOCALIA_DIR . 'includes/class-localia-tasks.php';
require_once LOCALIA_DIR . 'includes/class-localia-scheduler.php';
require_once LOCALIA_DIR . 'includes/class-localia-actions.php';
require_once LOCALIA_DIR . 'includes/class-localia-rest.php';
require_once LOCALIA_DIR . 'includes/class-localia-agent-download.php';
require_once LOCALIA_DIR . 'includes/class-localia-editor.php';
require_once LOCALIA_DIR . 'includes/class-localia-admin.php';

register_activation_hook( __FILE__, array( 'Localia_DB', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Localia_Scheduler', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Localia_DB', 'maybe_upgrade' ) );
Localia_Scheduler::init();
Localia_REST::init();
Localia_Agent_Download::init();
Localia_Editor::init();
Localia_Admin::init();

/**
 * Fires once Localia is loaded. Add-ons (such as Localia Pro) hook in here.
 */
do_action( 'localia_loaded' );
