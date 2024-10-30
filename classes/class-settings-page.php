<?php
/**
 * Registers settings and renders the settings page
 */
class Settings_Page{
	public function __construct() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die('You have insufficient permissions to access this page.');
		}

		add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
		add_action( 'admin_init', array( $this, 'register_plugin_settings' ) );
		$this->settings = get_option( 'code_analyzer_settings');
	}

	public function add_plugin_page() {
		add_options_page(
			'Code Analyzer',
			'Code Analyzer',
			'manage_options',
			'code-analyzer',
			array( $this, 'plugin_page_contents' )
		);
	}

	public function register_plugin_settings() {
		register_setting(
			'code_analyzer_settings_group',
			'code_analyzer_settings',
			array( $this, 'sanitize_settings' )
		);

		add_settings_section(
			'settings',
			'',
			array( $this, 'settings_section_callback' ),
			'code-analyzer'
		);

		add_settings_field(
			'ca-filename-pattern',
			'Filename pattern',
			array( $this, 'filename_pattern_callback' ),
			'code-analyzer',
			'settings',
			array( 'label_for' => 'ca-filename-pattern' )
			);

		add_settings_field(
			'ca-search-patterns',
			'Search patterns',
			array( $this, 'search_patterns_callback' ),
			'code-analyzer',
			'settings',
			array( 'label_for' => 'ca-search-patterns' )
			);

		add_settings_field(
			'ca-used-classes-functions',
			'Used classes and functions',
			array( $this, 'used_classes_functions_callback' ),
			'code-analyzer',
			'settings'
			);

		add_settings_field(
			'ca-results-display-mode',
			'Results display mode',
			array( $this, 'results_display_mode_callback' ),
			'code-analyzer',
			'settings'
			);
	}

	/**
	 * Sanitizes/validates settings, prints error messages
	 *
	 * @param array $input
	 * @param array $output
	 */
	public function sanitize_settings( $input ) {
		$output = array();
		$input['filename_pattern'] = trim( $input['filename_pattern'] );

		if( ! empty( $input['filename_pattern'] ) and @preg_match( $input['filename_pattern'], null ) !== false ) {
			$output['filename_pattern'] = $input['filename_pattern'];
		} else {
			$output['filename_pattern'] = $this->settings['filename_pattern'];
			add_settings_error( 'code_analyzer_settings_group', 'invalid_filename_pattern', 'Filename pattern must be valid.');
		}

		$input['search_patterns'] = trim( $input['search_patterns'] );

		if( !empty( $input['search_patterns'] ) ) {
			$patterns = array();
			$invalid_patterns = 0;
			$input['search_patterns'] = str_getcsv( $input['search_patterns'], "\n" );

			foreach( $input['search_patterns'] as $line ) {
				$fields = str_getcsv( $line );

				if( @preg_match( $fields[0], null ) !== false ) {
					if( empty( $fields[1] ) ) {
						$fields[1] = 'Unnamed pattern';
					}

					$patterns = array_merge( $patterns, array( $fields[0] => $fields[1] ) );
				} else {
					$invalid_patterns++;
				}
			}

			if( $invalid_patterns > 0 ) {
				add_settings_error( 'code_analyzer_settings_group', 'invalid_search_patterns', 'Invalid search patterns (' . $invalid_patterns . ') weren\'t saved.');
			}

			if( ! empty( $patterns ) ) {
				$output['search_patterns'] = $patterns;
			} else {
				$output['search_patterns'] = $this->settings['search_patterns'];
			}
		} else {
			$output['search_patterns'] = $this->settings['search_patterns'];
			add_settings_error( 'code_analyzer_settings_group', 'empty_search_patterns', 'Search patterns can\'t be empty.');
		}

		if( isset( $input['used_classes_functions'] ) ) {
			if( $input['used_classes_functions'] === 'on' ) {
				$output['used_classes_functions'] = '1';
			} else {
				$output['used_classes_functions'] = '0';
			}			
		}

		if( isset( $input['results_display_mode'] ) ) {
			if( $input['results_display_mode'] === '1' ) {
				$output['results_display_mode'] = '1';
			} else {
				$output['results_display_mode'] = '2';
			}			
		}

		add_settings_error( 'code_analyzer_settings_group', 'settings_saved', 'Settings saved.', 'updated' );
		return $output;
	}

	public function settings_section_callback() {
		return;
	}

	public function filename_pattern_callback() {
		echo '<input type="text" id="ca-filename-pattern" name="code_analyzer_settings[filename_pattern]" value="' . esc_attr( $this->settings['filename_pattern'] ) . '" />';
		echo '<p class="description">Only files which names match this regular expression will be analyzed.</p>';
	}

	public function search_patterns_callback() {
		echo '<textarea id="ca-search-patterns" name="code_analyzer_settings[search_patterns]" rows="10" cols="50" class="large-text code" style="white-space: pre;">' . esc_attr( $this->get_patterns() ) . '</textarea>';
		echo '<p class="description">Submit one regex pattern per line in this CSV format: "regular expression","pattern name".</p>';
	}

	public function used_classes_functions_callback() {
		echo '<input type="checkbox" id="ca-used-classes-functions" name="code_analyzer_settings[used_classes_functions]"' . checked( $this->settings['used_classes_functions'], 1, false ) . ' />';
		echo '<label for="ca-used-classes-functions">Generate a list of classes and functions used in analyzed files</label>';
	}

	public function results_display_mode_callback() {
		echo '<input type="radio" name="code_analyzer_settings[results_display_mode]" id="ca-results-display-mode-1" value="1"' . checked( $this->settings['results_display_mode'], 1, false ) . ' /> <label for="ca-results-display-mode-1">Display analysis results on the same page without reloading it</label><br />';
		echo '<input type="radio" name="code_analyzer_settings[results_display_mode]" id="ca-results-display-mode-2" value="2"' . checked( $this->settings['results_display_mode'], 2, false ) . ' /> <label for="ca-results-display-mode-2">Display analysis results on a standalone page</label>';
	}

	/**
	 * Returns search patterns for use in the textarea
	 *
	 * @return string
	 */
	private function get_patterns() {
		$patterns = $this->settings['search_patterns'];
		$items = fopen( 'php://output', 'w' );

		ob_start();

		foreach( $patterns as $pattern => $name ) {
			fputcsv($items, array( $pattern, $name ) );
		}

		fclose( $items );

		return ob_get_clean();
	}

	public function plugin_page_contents() { ?>
		<div class="wrap">
			<h2>Code Analyzer</h2>

			<?php if ( ! isset( $_GET['ca_plugin_dir'] ) ) { ?>
				<form method="post" action="options.php">
					<?php settings_fields( 'code_analyzer_settings_group' ); ?>
					<?php do_settings_sections( 'code-analyzer' ); ?>
					<?php submit_button(); ?>
				</form>

			<?php } else {
				$analyzer = new Analyzer;
				$analyzer->analyze_code();
			} ?>

		</div>
	<?php }
}
