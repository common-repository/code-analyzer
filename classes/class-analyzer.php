<?php
/**
 * Analyzes code and prints links on the Plugins screen
 */
class Analyzer {
	public function __construct() {
		if ( current_user_can( 'install_plugins' ) ) {
			$this->settings = get_option( 'code_analyzer_settings' );
			add_action( 'wp_ajax_analyze_code', array( $this, 'analyze_code' ) );

			if( ( $GLOBALS['pagenow'] === 'update.php' and ( $_GET['action'] === 'install-plugin' or $_GET['action'] === 'upload-plugin' ) ) or $GLOBALS['pagenow'] === 'plugins.php' ) {
				// Retrieves old plugins when installing a new plugin
				if( $_GET['action'] === 'install-plugin' or $_GET['action'] === 'upload-plugin' ) {
					add_action( 'admin_init', array( $this, 'get_current_plugins' ) );
				}

				// Loads jQuery when necessary
				if( $this->settings['results_display_mode'] === '1' ) {
					add_action( 'wp_head', 'load_jquery' );
				}

				add_action( 'admin_print_footer_scripts', array( $this, 'plugin_analysis_script' ) );
				add_action( 'admin_enqueue_scripts', array( $this, 'analyzer_style' ) );
			}

			if( $GLOBALS['pagenow'] === 'options-general.php' and $this->settings['results_display_mode'] === '2' and isset( $_GET['ca_plugin_dir'] ) ) {
				add_action( 'admin_print_footer_scripts', array( $this, 'plugin_analysis_script' ) );
				add_action( 'admin_enqueue_scripts', array( $this, 'analyzer_style' ) );
			}

			if( $GLOBALS['pagenow'] === 'plugin-editor.php' and isset( $_GET['file'] ) and isset( $_GET['ca_line'] ) ) {
				add_action( 'admin_print_footer_scripts', array( $this, 'plugin_editor_line_selection_script' ) );
			}
		}
	}

	/**
	 * Loads jQuery
	 */
	public function load_jquery() {
		wp_enqueue_script( 'jquery' );
	}

	/**
	 * Loads CSS
	 */
	public function analyzer_style() {
		wp_enqueue_style( 'ca_analyzer_style', PLUGIN_URL . 'includes/analyzer.css' );
	}

	/**
	 * Retrieves an array of currently installed plugins
	 */
	public function get_current_plugins() {
		$this->old_plugins = get_plugins();
	}

	/**
	 * Analyzes all files in a directory
	 */
	public function analyze_code() {
		$this->all_files_summary = array();
		$this->all_files_classes_functions = array();
		$this->results = array();
		$this->file_count = 0;

		$this->analyze_plugin();
		$this->print_results();

		// Script should not die when results are displayed on a standalone page
		if( $this->settings['results_display_mode'] === '1' ) {
			wp_die();
		}
	}

	/**
	 * Prints analysis results
	 */
	private function print_results() {
		// Prints a heading and the "ca-results" div if results are displayed on a standalone page
		if( $this->settings['results_display_mode'] === '2' ) {
			echo '<h3>Analyzed directory: ' . $this->directory_path . '</h3>';
			echo '<div id="ca-results">';
		}

		if( ! empty( $this->all_files_summary ) ) {
			ksort( $this->all_files_summary );
			$summary = $this->get_pattern_summary( $this->all_files_summary );
		} else {
			$summary = '<span class="ca-green">No matches found</span>';
		}

		if( $this->settings['used_classes_functions'] === '1' ) {
			if( ! empty( $this->all_files_classes_functions ) ) {
				$this->all_files_classes_functions = array_unique( $this->all_files_classes_functions );
				sort( $this->all_files_classes_functions );
				$classes_functions_count = count( $this->all_files_classes_functions );
				$classes_functions = '<code>' . implode( '</code>, <code>', $this->all_files_classes_functions ) . '</code>';
			} else {
				$classes_functions_count = 0;
				$classes_functions = 'No items to show.';
			}
		}

		if( ! empty( $this->results ) ) {
			$this->sort_results();
			$results = implode( "\n", $this->results );
		} else {
			$results = 'No items to show.';
		}

		echo '<p><strong>Analysis summary:</strong> ' . $summary . '</p>';

		if( $this->settings['used_classes_functions'] === '1' ) {
			echo '<p><strong>Used classes and functions<span class="ca-smaller"> (' . $classes_functions_count . ')</span>:</strong> [<a id="ca-functions-link" onclick="ca_toggleFunctions();" class="ca-link ca-nowrap">Show details</a>] <span id="ca-functions" class="ca-gray" style="display: none;">' . $classes_functions . '</span></p>';
		}

		echo '<p><strong>Analysis results<span class="ca-smaller"> (' . $this->file_count . ' files)</span>:</strong></p>';
		echo '<ul>' . $results . '</ul>';

		// Closes the "ca-results" div if results are displayed on a standalone page
		if( $this->settings['results_display_mode'] === '2' ) {
			echo '</div>';
		}
	}

	/**
	 * Recursively analyzes all plugin files that match the filename pattern
	 */
	public function analyze_plugin() {
		if( $this->settings['results_display_mode'] === '1' ) {
			if( ! wp_verify_nonce( $_POST['nonce'], 'ca_analysis_nonce' ) or ! isset( $_POST['plugin_dir'] ) ) {
				die( '<p>Unable to analyze code.</p>' );
			}

			$this->directory_path = $_POST['plugin_dir'];
		} else {
			if( ! wp_verify_nonce( $_GET['ca_nonce'], 'ca_analysis_nonce' ) or ! isset( $_GET['ca_plugin_dir'] ) ) {
				die( '<p>Unable to analyze code.</p>' );
			}

			$this->directory_path = $_GET['ca_plugin_dir'];
		}

		$directory = new RecursiveDirectoryIterator( $this->directory_path );
		$iterator = new RecursiveIteratorIterator( $directory );
		$files = new RegexIterator( $iterator, $this->settings['filename_pattern'], RecursiveRegexIterator::GET_MATCH );

		foreach( $files as $file ) {
			$this->analyze_file( $file );
			$this->file_count++;
		}
	}

	/**
	 * Analyzes a single file
	 *
	 * @param string $file_path
	 */
	private function analyze_file( $file_path ) {
		$local_classes_functions = array();
		$file_results = array();
		$lines_results = array();
		$analyzed_lines = '';
		$line_number = 1;
		$file = file( $file_path[0] );

		foreach ( $file as $line ) {
			foreach( $this->settings['search_patterns'] as $pattern => $name ) {
				$matches = preg_match_all( $pattern, $line, $matched_patterns );

				if( $matches > 0 ) {
					if( empty( $name ) ) {
						$name = 'Unnamed pattern';
					}

					$file_results[$name] = $this->get_file_summary( $file_results, $name, $matches );
					$line_modified = htmlspecialchars( $line );
					$matched_patterns[0] = array_unique( $matched_patterns[0] );
					$matched_patterns[0] = array_map( 'htmlspecialchars', $matched_patterns[0] );

					if( ! isset( $lines_results[$line_number] ) ) {
						foreach( $matched_patterns[0] as $match ) {
							$line_modified = str_replace( $match, '<span class="ca-highlighted">' . $match . '</span>', $line_modified, $matches );
						}

						$lines_results[$line_number] = array( 'names' => array( $name => $matches ), 'line' => $line_modified );
					} else {
						if( ! array_key_exists( $name, $lines_results[$line_number]['names'] ) ) {
							$lines_results[$line_number]['names'][$name] = $matches;
						}

						foreach( $matched_patterns[0] as $match ) {
							$lines_results[$line_number]['line'] = str_replace( $match, '<span class="ca-highlighted">' . $match . '</span>', $lines_results[$line_number]['line'], $matches );
						}
					}
				}
			}

			$line_number++;
		}

		$filename = str_replace( $this->directory_path, '', $file_path[0] );
		preg_match( '/^.+[^\/]\/(|.+?\/' . preg_quote( $filename, '/' ) . ')$/', $this->directory_path . $filename, $matched_plugin_file_path );

		ob_start();
		echo '<li>File <a href="' . admin_url( 'plugin-editor.php?file=' . $matched_plugin_file_path[1] ) . '" title="Show &quot;' . $matched_plugin_file_path[1] . '&quot; in the plugin editor">' . $filename . '</a>: ';

		if( ! empty( $file_results ) ) {
			ksort( $file_results );

			echo $this->get_pattern_summary( $file_results ) . ' [<a onclick="ca_toggleResults(\'' . $file_path[0] . '\');" id="ca-details-link[\'' . $file_path[0] . '\']" class="ca-link ca-nowrap">Show details</a>]';
			echo '<ul id="ca-details[\'' . $file_path[0] . '\']" style="display: none;">' . $this->get_analyzed_lines( $lines_results, $matched_plugin_file_path[1] ) . '</ul>';

			$this->all_files_summary = $this->get_all_files_summary( $this->all_files_summary, $file_results );
			$result = 1;
		} else {
			$result = 0;
			echo '<span class="ca-green ca-smaller">No matches found</span>';
		}

		echo '</li>';

		$this->results = array_merge( $this->results, array( ob_get_clean() => $result ) );

		if( $this->settings['used_classes_functions'] === '1' ) {
			$file_contents = file_get_contents( $file_path[0] );
			$local_classes_functions = array_merge( $local_classes_functions, array_values( wp_doc_link_parse( $file_contents ) ) );
			$this->all_files_classes_functions = array_merge( $this->all_files_classes_functions, $local_classes_functions );
		}
	}

	/**
	 * Returns the number of matches of a pattern in a file
	 *
	 * @param array $file_results
	 * @param string $name
	 * @param int $matches
	 * @return int
	 */
	private function get_file_summary( $file_results, $name, $matches ) {
		if( ! array_key_exists( $name, $file_results ) ) {
			return $matches;
		} else {
			return $file_results[$name] + $matches;
		}
	}

	/**
	 * Returns an array with pattern names and number of matches
	 *
	 * @param array $all_files_summary
	 * @param array $file_results
	 * @return array $all_files_summary
	 */
	private function get_all_files_summary( $all_files_summary, $file_results ) {
		foreach( $file_results as $name => $count ) {
			if( ! array_key_exists( $name, $all_files_summary ) ) {
				$all_files_summary[$name] = $count;
			} else {
				$all_files_summary[$name] += $count;
			}
		}

		return $all_files_summary;
	}

	/**
	 * Returns the pattern name and the number of matches
	 *
	 * @param array $items
	 * @return string $items_summary
	 */
	private function get_pattern_summary( $items ) {
		foreach( $items as $name => $count ) {
			if( $count > 1 ) {
				$count_displayed = ' <span class="ca-gray ca-smaller">(' . $count . ')</span>';
			} else {
				$count_displayed = '';
			}
			$items_summary .= '<span class="ca-red ca-nowrap">' . $name . $count_displayed . '</span>, ';
		}

		$items_summary = rtrim( $items_summary, ', ' );
		return $items_summary;
	}

	/**
	 * Returns a list of analyzed lines in a single file
	 *
	 * @param array $lines_results
	 * @param string $matched_plugin_file_path
	 * @return string $analyzed_lines
	 */
	private function get_analyzed_lines( $lines_results, $matched_plugin_file_path ) {
		foreach( $lines_results as $line_number => $line_details ) {
			$analyzed_lines .= '<li class="ca-gray">Line <a href="' . admin_url( 'plugin-editor.php?file=' . $matched_plugin_file_path . '&ca_line=' . $line_number ) . '" title="Show &quot;' . $matched_plugin_file_path . '&quot; in the plugin editor and highlight line ' . $line_number . '">' . $line_number . '</a> - ' . $this->get_pattern_summary( $line_details['names'] ) . ': <code>' . $line_details['line'] . '</code></li>';
		}

		return $analyzed_lines;
	}

	/**
	 * Alphabetically sorts the results array and puts files with no matches at the end of the array
	 */
	private function sort_results( ) {
		$files_with_matches = array();
		$files_without_matches = array();

		foreach( $this->results as $result => $matches ) {
			if( $matches > 0 ) {
				array_push( $files_with_matches, $result );
			} else {
				array_push( $files_without_matches, $result );
			}
		}

		sort( $files_with_matches );
		sort( $files_without_matches );

		$this->results = array_merge( $files_with_matches, $files_without_matches );
	}

	/**
	 * Scripts used to display analysis results
	 */
	public function plugin_analysis_script() {
		?><script type="text/javascript">
			function ca_toggleResults( filePath ) {
				details = document.getElementById( "ca-details[\'"+filePath+"\']" );
				details_link = document.getElementById( "ca-details-link[\'"+filePath+"\']" );

				if( details.style.display === "none" ) {
					details.style.display = "block";
				} else {
					details.style.display = "none";
				}

				if( details_link.innerHTML === "Show details" ) {
					details_link.innerHTML = "Hide details";
				} else {
					details_link.innerHTML = "Show details";
				}
			}

			function ca_toggleFunctions() {
				functions = document.getElementById( "ca-functions" );
				functions_link = document.getElementById( "ca-functions-link" );

				if( functions.style.display === "none" ) {
					functions.style.display = "block";
				} else {
					functions.style.display = "none";
				}

				if( functions_link.innerHTML === "Show details" ) {
					functions_link.innerHTML = "Hide details";
				} else {
					functions_link.innerHTML = "Show details";
				}
			}
		</script><?php

		$nonce = wp_create_nonce( 'ca_analysis_nonce' );

		if( $GLOBALS['pagenow'] == 'update.php' ) {
			$current_plugins = get_plugins();
			$plugin_path = array_values( array_diff( array_keys( $current_plugins ), array_keys( $this->old_plugins ) ) );
			$plugin_path_full = WP_PLUGIN_DIR . '/' . plugin_dir_path( $plugin_path[0] );

			if( $GLOBALS['pagenow'] == 'update.php' and $_GET['action'] === 'install-plugin' ) {
				$paragraph_number = '4';
			} elseif( $GLOBALS['pagenow'] == 'update.php' and $_GET['action'] === 'upload-plugin' ) {
				$paragraph_number = '3';
			}

			if( !empty( $plugin_path ) ) {
				?><script type="text/javascript">
				<?php if( $this->settings['results_display_mode'] === '1' ) { ?>
					ca_analyze_code_link = '<a href="#" onclick="ca_analyzeCode( \'<?php echo $plugin_path_full; ?>\' );" class="ca-link">Analyze code</a> | ';
				<?php } else { ?>
					ca_analyze_code_link = '<a href="<?php echo admin_url( 'options-general.php?page=code-analyzer&ca_nonce=' . $nonce . '&ca_plugin_dir=' . $plugin_path_full ); ?>">Analyze code</a> | ';
				<?php } ?>

				ca_contents = document.getElementsByClassName( "wrap" )[0].getElementsByTagName( "p" )[<?php echo $paragraph_number; ?>].innerHTML;
				document.getElementsByClassName( "wrap" )[0].getElementsByTagName( "p" )[<?php echo $paragraph_number; ?>].innerHTML = ca_analyze_code_link + ca_contents;

				<?php if( $this->settings['results_display_mode'] === '1' ) { ?>
					function ca_analyzeCode( ca_pluginDir ) {
						jQuery( function ( $ ) {
							resultsPage = '<div id="ca-results-page"><div id="ca-close"><a class="ca-link" title="Click to hide analysis results" onclick="ca_hideResults();">Hide</a></div><h2 id="ca-heading">Code analysis in progress<span class="ca-dots"><span>.</span><span>.</span><span>.</span></span>​</h2><div id="ca-results"></div>';

							if ( ! $( "#ca-results-page" ).length ) {
								$( ".wrap" ).append( resultsPage );
							} else {
								$( "#ca-results-page" ).remove();
								$( ".wrap" ).append( resultsPage );
							}

							var data = {
								"action": "analyze_code",
								"plugin_dir": ca_pluginDir,
								"nonce": "<?php echo $nonce; ?>"
							};

							$.post(ajaxurl, data, function( response ) {
								$( "#ca-heading" ).html( "Code analysis results" );

								if( response == 0 ) {
									response = "<p>Unable to analyze code.</p>";
								}

								$( "#ca-results" ).html( response );
							})
							.fail( function() {
								$( "#ca-results-page" ).remove();
								alert( "Unable to analyze code." );
							})
						});
					}

					function ca_hideResults() {
						jQuery( "#ca-results-page" ).remove();
					}

				<?php } ?>
				</script><?php
			}
		} elseif( $GLOBALS['pagenow'] == 'plugins.php' ) { ?>
			<script type="text/javascript">
				ca_links = document.getElementsByClassName( "row-actions" );
				ca_pluginIDs = document.getElementsByName( "checked[]" );
				ca_pluginNames = document.getElementsByClassName( "plugin-title" );

				for ( i = 0; i < ca_links.length; i++ ){
					ca_nameString = ca_pluginNames[i].innerHTML;
					ca_nameRegex = /^<strong>(.+)<\/strong>.+$/;
					ca_pluginName = escapeHtml( ca_nameString.match(ca_nameRegex)[1] );
					ca_pluginFile = ca_pluginIDs[i].value;
					ca_dirRegex = /^(.+\/).+$/;
					ca_pluginDir = ca_pluginFile.match(ca_dirRegex)[1];

				<?php if( $this->settings['results_display_mode'] === '1' ) { ?>
					ca_links[i].innerHTML += '<span> | <a onclick="ca_analyzeCode( \''+ca_pluginName+'\', \'<?php echo WP_PLUGIN_DIR; ?>/'+ca_pluginDir+'\' );" class="ca-link">Analyze code</a></span>';
				<?php } else { ?>

					ca_links[i].innerHTML += '<span> | <a href="<?php echo admin_url( 'options-general.php?page=code-analyzer&ca_nonce=' . $nonce . '&ca_plugin_dir=' . WP_PLUGIN_DIR . '/' ); ?>'+ca_pluginDir+'">Analyze code</a></span>';
				<?php } ?>
				}

				<?php if( $this->settings['results_display_mode'] === '1' ) { ?>
					function ca_analyzeCode( ca_pluginName, ca_pluginDir ) {
						jQuery( function ( $ ) {
							resultsWindow = $( '<div id="ca-results-window"><div id="ca-close"><a class="ca-link" title="Click to hide analysis results" onclick="ca_hideResults();">Hide</a></div><h2 id="ca-heading">'+ca_pluginName+': Code analysis in progress<span class="ca-dots"><span>.</span><span>.</span><span>.</span></span>​</h2><div id="ca-results"></div></div>' );

							if ( ! $( "#ca-results-window" ).length ) {
								$( "body" ).append( resultsWindow );
							} else {
								$( "#ca-results-window" ).remove();
								$( "body" ).append( resultsWindow );
							}

							var data = {
								"action": "analyze_code",
								"plugin_dir": ca_pluginDir,
								"nonce": "<?php echo $nonce; ?>"
							};

							$.post(ajaxurl, data, function( response ) {
								$( "#ca-heading" ).html( ca_pluginName+": Code analysis results" );

								if( response == 0 ) {
									response = "<p>Unable to analyze code.</p>";
								}

								$( "#ca-results" ).html( response );
							})
							.fail( function() {
								$( "#ca-results-window" ).remove();
								alert( "Unable to analyze code." );
							})
						});
					}

					function ca_hideResults() {
						jQuery( "#ca-results-window" ).remove();
					}
				<?php } ?>

				// Credit for this code goes to Kip (https://stackoverflow.com/questions/1787322/htmlspecialchars-equivalent-in-javascript#answer-4835406) - thanks!
				function escapeHtml( text ) {
					var map = {
						'&': '&amp;',
						'<': '&lt;',
						'>': '&gt;',
						'"': '&quot;',
						"'": '&#039;'
					};

					return text.replace(/[&<>"']/g, function(m) { return map[m]; });
				}
			</script>
		<?php }
	}

	/**
	 * Script used to select a line in the plugin editor
	 */
	public function plugin_editor_line_selection_script() { ?>
		<script type="text/javascript">
			// Credit for this code goes to Lostsource (http://lostsource.com/2012/11/30/selecting-textarea-line.html) - thanks!
			function ca_selectTextareaLine( tarea, lineNum ) {
				lineNum--;
				var lines = tarea.value.split( "\n" );

				var startPos = 0, endPos = tarea.value.length;
				for( var x = 0; x < lines.length; x++ ) {
					if( x == lineNum ) {
						break;
					}
					startPos += ( lines[x].length+1 );
				}

				var endPos = lines[lineNum].length+startPos;

				if( typeof( tarea.selectionStart ) != "undefined" ) {
					tarea.focus();
					tarea.selectionStart = startPos;
					tarea.selectionEnd = endPos;
					return true;
				}

				if( document.selection && document.selection.createRange ) {
					tarea.focus();
					tarea.select();
					var range = document.selection.createRange();
					range.collapse( true );
					range.moveEnd( "character", endPos );
					range.moveStart( "character", startPos );
					range.select();
					return true;
				}

				return false;
			}

			window.onload = function () {
				ca_selectTextareaLine( document.getElementById( 'newcontent' ), <?php echo $_GET['ca_line']; ?> );
			}
		</script>
	<?php }
}
