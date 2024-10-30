<?php
/*
Plugin Name: Code Analyzer
Plugin URI: https://wordpress.org/plugins/code-analyzer/
Description: Simple search tool using regular expressions to find unwanted code in plugins.
Version: 0.2
Author: evilkitteh
Author URI: http://evilkitteh.cf
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

if( ! defined( 'ABSPATH' ) ) {
	exit;
}

new Code_Analyzer();

class Code_Analyzer {
	public function __construct() {
		require_once( 'classes/class-database.php' );

		register_activation_hook( __FILE__, array( 'Database', 'plugin_activation' ) );
		register_deactivation_hook( __FILE__, array( 'Database', 'plugin_deactivation' ) );

		add_action( 'init', array( $this, 'plugin_loader' ) );
	}

	public function plugin_loader() {
		if( is_admin() ) {
			define( 'PLUGIN_URL', plugin_dir_url( __FILE__ ) );

			require_once( 'classes/class-settings-page.php' );
			require_once( 'classes/class-analyzer.php' );

			new Database;
			new Settings_Page;
			new Analyzer;
		}
	}
}
