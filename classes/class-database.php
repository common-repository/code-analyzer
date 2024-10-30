<?php
/**
 * Takes care of database-related stuff
 */
class Database {
	public function __construct() {
		if ( current_user_can( 'manage_options' ) ) {
			if( $GLOBALS['pagenow'] === 'update-core.php' or $GLOBALS['pagenow'] === 'plugins.php' or ( $GLOBALS['pagenow'] === 'options-general.php' and $_GET['page'] === 'code-analyzer' ) ) {
				$this->settings = get_option( 'code_analyzer_settings' );
				add_action( 'wp_loaded', array( $this, 'plugin_update' ) );
			}
		}
	}

	/**
	 * Creates a new option after installation
	 */
	public function plugin_activation() {
		if( get_option( 'code_analyzer_settings' ) === false ) {
			add_option( 'code_analyzer_settings', Database::default_settings(), '', 'no' );
		}
	}

	/**
	 * Unregisters plugin settings after deactivation
	 */
	public function plugin_deactivation(){
		unregister_setting( 'code_analyzer_settings_group', 'code_analyzer_settings' );
	}

	/**
	 * Updates database settings
	 */
	public function plugin_update() {
		if( $this->plugin_version() !== $this->settings['version'] ){
			$this->sync_settings( 'remove_superfluous' );
			$this->sync_settings( 'add_missing' );
			wp_redirect( $_SERVER['REQUEST_URI'] );
			exit;
		}
	}

	/**
	 * Syncs keys in the database option with the default settings array and updates the plugin version
	 *
	 * @param string $operation
	 */
	private function sync_settings( $operation ) {
		if( $operation === 'remove_superfluous' ) {
			foreach( $this->settings as $key => $value ) {
				if( ! array_key_exists( $key, $this->default_settings() ) ){
					unset( $this->settings[$key] );
				}
			}
		} elseif( $operation === 'add_missing' ) {
			foreach( $this->default_settings() as $key => $value ) {
				if( ! array_key_exists( $key, $this->settings ) ){
					$this->settings[$key] = $value;
				}
			}
		}

		$this->settings['version'] = Database::plugin_version();
		update_option( 'code_analyzer_settings', $this->settings );
	}

	/**
	 * Returns the plugin version
	 *
	 * @return string
	 */
	public static function plugin_version(){
		if( ! function_exists( 'get_plugin_data' ) ){
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		}

		$main_file = dirname( dirname( __FILE__ ) ) . '/code-analyzer.php';
		$plugin_data = get_plugin_data( $main_file, false, false );
		return $plugin_data['Version'];
	}

	/**
	 * Returns default settings
	 *
	 * @return array
	 */
	public static function default_settings() {
		$default_filename_pattern = '/^.+\.(php|js|html|htm)$/i';

		$re_function_start = '/(?<=^|[^\"\'\w])';
		$re_function_end = '(?=\s*\()/i';
		$re_tag_start = '/<\s*';
		$re_method_start = '/\.';
		$regex_start = '/';
		$regex_end = '/i';

		$default_search_patterns = array(
			$re_function_start . '(assert|create_function|eval)' . $re_function_end => 'Code evaluation',
			$re_function_start . 'preg_replace\s*\(\s*(\"|\')([^a-z\s]).*\2[imsxadsuj]?e[imsxadsuj]?\1' . $regex_end => 'Code evaluation ("e" modifier)',
			$re_function_start . '(exec|passthru|pcntl_exec|popen|proc_open|shell_exec|show_source|system)' . $re_function_end => 'Command execution',
			$re_function_start . 'init_set' . $re_function_end => 'init_set()',
			$re_function_start . 'fopen' . $re_function_end => 'fopen()',
			$re_function_start . '(base64_decode|convert_uudecode|atob)' . $re_function_end => 'Deobfuscation',
			$re_function_start . '(str_rot13|strrev)' . $re_function_end => 'Obfuscation',
			$re_function_start . '(curl_exec|curl_init|fetch_feed|fsockopen|pfsockopen|stream_socket_client|trackback|weblog_ping|wp_get_http_headers|wp_remote_fopen|wp_remote_get|wp_remote_head|wp_remote_post|wp_remote_request|wp_remote_retrieve_body|wp_remote_retrieve_header|wp_remote_retrieve_headers|wp_remote_retrieve_response_code|wp_remote_retrieve_response_message|wp_safe_remote_get|wp_safe_remote_head|wp_safe_remote_post|wp_safe_remote_request)' . $re_function_end => 'Remote request',
			$re_function_start . '(XMLHttpRequest|HttpRequest|WP_Http)\s*(::|\(|;)' . $regex_end => 'Remote request (class/object)',
			$re_function_start . '(mail|wp_mail)' . $re_function_end => 'Remote request (mail sending)',
			$re_function_start . '(chgrp|chmod|chown|file_put_contents|fwrite|rmdir|touch|unlink|WP_Filesystem)' . $re_function_end => 'Filesystem modification',
			$re_function_start . '(\$(bbdb|db|wpdb)|(mssql|mysql|mysqli)(_[a-z]+_?)?)\s*(::|->|_)\s*query' . $re_function_end => 'Direct database query',
			$re_function_start . 'wp_create_user' . $re_function_end => 'User creation',
			$re_function_start . 'wp_enqueue_script' . $re_function_end => 'Script (enqueued)',
			$re_tag_start . 'script' . $regex_end => 'Script (inline)',
			$re_tag_start . '(iframe|frame)' . $regex_end => 'Iframe',
			$re_tag_start . '(embed|object)' . $regex_end => 'Embedded object',
			$re_tag_start . 'applet' . $regex_end => 'Java applet',
			$re_method_start . 'write(ln)?' . $re_function_end => '.write()',
			$re_method_start . 'fromCharCode' . $re_function_end => '.fromCharCode()',
			$re_method_start . 'fromCodePoint' . $re_function_end => '.fromCodePoint()',
			$re_method_start . 'createElement' . $re_function_end => '.createElement()',
			$regex_start . '(\\\\\d+|\\\\[ux][0-9a-f]+)' . $regex_end => 'Escaped character literal',
			$regex_start . '(?<=^|\W)(0((x[0-9a-f]+)|b[10]+|o\d+))' . $regex_end => 'Integer literal',
			$regex_start . '(?<=\"|\')(https?:)?\/\/[^\s\/$.?#].[^\s]*?(?=\"|\')'. $regex_end => 'URL',
			$regex_start . 'swf'. $regex_end => 'swf',
			$regex_start . '(?<=\"|\')UA-[0-9]+-[0-9]+(?=\"|\')'. $regex_end => 'Google Analytics ID',
			$regex_start . '(?<=\"|\')(ca-)?pub-[0-9]+(?=\"|\')'. $regex_end => 'Google AdSense publisher ID'
		);

		return 	array(
					'version' => Database::plugin_version(),
					'filename_pattern' => $default_filename_pattern,
					'search_patterns' => $default_search_patterns,
					'used_classes_functions' => '0',
					'results_display_mode' => '1'
				);
	}
}
