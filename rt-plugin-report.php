<?php
/**
 * Plugin Name:       Plugin Report
 * Plugin URI:        https://roytanck.com/?p=277
 * Description:       Provides detailed information about currently installed plugins
 * Version:           1.5
 * Requires at least: 4.6
 * Requires PHP:      5.6
 * Author:            Roy Tanck
 * Author URI:        https://roytanck.com
 * License:           GPLv3
 */

// If called without WordPress, exit.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


if ( is_admin() && ! class_exists( 'RT_Plugin_Report' ) ) {

	class RT_Plugin_Report {

		// CSS class constants.
		const CSS_CLASS_LOW  = 'rt-risk-low';
		const CSS_CLASS_MED  = 'rt-risk-medium';
		const CSS_CLASS_HIGH = 'rt-risk-high';

		// Othe class constants
		const COLS_PER_ROW          = 7;
		const CACHE_LIFETIME        = DAY_IN_SECONDS;
		const CACHE_LIFETIME_NOREPO = WEEK_IN_SECONDS;

		/**
		 * Constructor
		 */
		public function __construct() {
			// Intentionally left blank.
		}


		/**
		 * Set up things like hooks and such
		 */
		public function init() {
			// Hook for the admin page.
			if ( is_multisite() ) {
				add_action( 'network_admin_menu', array( $this, 'register_settings_page' ) );
			} else {
				add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
			}
			// Hook for the admin js.
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_js' ) );
			// Add the AJAX hook.
			add_action( 'wp_ajax_rt_get_plugin_info', array( $this, 'get_plugin_info' ) );
			// Hook into the WP Upgrader to selectively delete cache items.
			add_action( 'upgrader_process_complete', array( $this, 'upgrade_delete_cache_items' ), 10 ,2 );
		}


		/**
		 * Add a new options page to the network admin
		 */
		public function register_settings_page() {
			add_plugins_page(
				esc_html_x( 'Plugin Report', 'Page and menu title', 'plugin-report' ),
				esc_html_x( 'Plugin Report', 'Page and menu title', 'plugin-report' ),
				'manage_options',
				'rt_plugin_report',
				array( $this, 'settings_page' )
			);
		}


		/**
		 * Render the options page
		 */
		public function settings_page() {
			// Check user capabilities, just to be sure.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die();
			}
			// Assemble information we'll need.
			global $wp_version;
			$plugins = get_plugins();

			// Check wether a core update is available.
			$wp_latest = $this->check_core_updates();

			// Refresh the cache, but only if this is a fresh timestamp (not if the page has been refreshed with the timestamp still in the URL).
			if ( isset( $_GET['clear_cache'] ) ) {
				$new_timestamp  = intval( $_GET['clear_cache'] );
				$last_timestamp = intval( get_site_transient( 'rt_plugin_report_cache_cleared' ) );
				if ( ! $last_timestamp || $new_timestamp > $last_timestamp ) {
					$this->clear_cache();
					set_site_transient( 'rt_plugin_report_cache_cleared', $new_timestamp, self::CACHE_LIFETIME );
				}
			}

			// Start the page's output.
			echo '<div class="wrap">';
			echo '<h1>' . esc_html_x( 'Plugin Report', 'Page and menu title', 'plugin-report' ) . '</h1>';
			echo '<p>';
			$version_temp = '<span class="' . $this->get_version_risk_classname( $wp_version, $wp_latest ) . '">' . $wp_version . '</span>';
			/* translators: %1$s: Current WordPress version number, %2$s: Current PHP version number */
			echo sprintf( __( 'Currently running WordPress version %1$s and PHP version %2$s.', 'plugin-report' ), $version_temp, phpversion() );
			if ( version_compare( $wp_version, $wp_latest, '<' ) ) {
				/* translators: %s = Available new version number */
				echo sprintf( ' (' . esc_html__( 'An upgrade to %s is available', 'plugin-report' ) . ')', $wp_latest );
			}
			echo '</p>';
			echo '<p>';
			// Clear cache and reload.
			if ( is_multisite() ) {
				$page_url = 'network/plugins.php?page=rt_plugin_report';
			} else {
				$page_url = 'plugins.php?page=rt_plugin_report';
			}
			echo '<a href="' . admin_url( $page_url . '&clear_cache=' . current_time( 'timestamp' ) ) . '">' . esc_html__( 'Clear cached plugin data and reload', 'plugin-report' ) . '</a>';
			echo '</p>';
			echo '<h3>' . esc_html__( 'Currently installed plugins', 'plugin-report' ) . '</h3>';
			echo '<p id="rt-plugin-report-progress"></p>';
			echo '<p>';

			// The report's main table.
			echo '<table id="rt-plugin-report-table" class="wp-list-table widefat fixed striped">';
			echo '<thead>';
			echo '<tr>';
			echo '<th>' . esc_html__( 'Name', 'plugin-report' ) . '</th>';
			echo '<th>' . esc_html__( 'Author', 'plugin-report' ) . '</th>';
			echo '<th>' . esc_html__( 'Activated', 'plugin-report' ) . '</th>';
			echo '<th>' . esc_html__( 'Installed version', 'plugin-report' ) . '</th>';
			echo '<th>' . esc_html__( 'Last update', 'plugin-report' ) . '</th>';
			echo '<th>' . esc_html__( 'Tested up to WP version', 'plugin-report' ) . '</th>';
			echo '<th>' . esc_html__( 'Rating', 'plugin-report' ) . '</th>';
			echo '</tr>';
			echo '</thead>';
			echo '<tbody>';

			foreach ( $plugins as $key => $plugin ) {
				$slug      = $this->get_plugin_slug( $key );
				$cache_key = $this->create_cache_key( $slug );
				$cache     = get_site_transient( $cache_key );
				if ( $cache ) {
					// Use the cached report to create a table row.
					echo $this->render_table_row( $cache );
				} else {
					// Render a special table row that's used as a signal to the front-end js that new data is needed.
					echo '<tr class="rt-plugin-report-row-temp-' . $slug . '"><td colspan="' . self::COLS_PER_ROW . '">' . esc_html__( 'Loading...', 'plugin-report' ) . '</td></tr>';
				}
			}

			echo '</tbody>';
			echo '</table>';
			echo '</p>';

			echo '<p id="rt-plugin-report-buttons"></p>';

			// Wrap up.
			echo '</div>';
		}


		/**
		 * Enqueue admin javascript
		 */
		public function enqueue_js( $hook ) {
			// Check if we're on the right screen.
			if ( 'plugins_page_rt_plugin_report' != $hook ) {
				return;
			}
			// register the plugin's admin js, and require jquery
			wp_enqueue_script( 'rt-plugin-report-js', plugins_url( '/js/rt-plugin-report.js', __FILE__ ), array( 'jquery' ) );
			// add some variables to the page, to be used by the javascript
			$slugs     = $this->get_plugin_slugs();
			$slugs_str = implode( ',', $slugs );
			$vars      = array(
				'plugin_slugs' => $slugs_str,
				'ajax_nonce'   => wp_create_nonce( 'rt_plugin_report_nonce' ),
				'export_btn'   => __( 'Export .xls file', 'plugin-report' ),
			);
			wp_localize_script( 'rt-plugin-report-js', 'rt_plugin_report_vars', $vars );
			// Enqueue admin CSS file.
			wp_enqueue_style( 'rt-plugin-report-css', plugin_dir_url( __FILE__ ) . 'css/rt-plugin-report.css' );
		}


		/**
		 * Get the slugs for all currently installed plugins
		 */
		private function get_plugin_slugs() {
			$plugins = get_plugins();
			$slugs   = array();
			foreach ( $plugins as $key => $plugin ) {
				$slugs[] = $this->get_plugin_slug( $key );
			}
			return $slugs;
		}


		/**
		 * Convert a plugin's file path into its slug
		 */
		private function get_plugin_slug( $file ) {
			if ( strpos( $file, '/' ) !== false ) {
				$parts = explode( '/', $file );
			} else {
				$parts = explode( '.', $file );
			}
			return sanitize_title( $parts[0] );
		}


		/**
		 * AJAX handler
		 * Returns a full html table row with the plugin's data
		 */
		public function get_plugin_info() {

			// Check the ajax nonce, display an error if the check fails.
			if ( ! check_ajax_referer( 'rt_plugin_report_nonce', 'nonce', false ) ) {
				wp_die();
			}

			// Check user capabilites, just to be sure.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die();
			}

			// Check if get_plugins() function exists.
			if ( ! function_exists( 'plugins_api' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			}

			$slug = sanitize_title( $_POST['slug'] );

			$report = $this->assemble_plugin_report( $slug );

			if ( $report ) {
				$table_row = $this->render_table_row( $report );
			} else {
				$table_row = $this->render_error_row( esc_html__( 'No plugin data available.', 'plugin-report' ) );
			}

			// Formulate a response.
			$response = array(
				'html'    => $table_row,
				'message' => 'Success!',
			);
			// Return the response.
			echo json_encode( $response );

			wp_die();
		}


		/**
		 * Gather all the info we can get about a plugin.
		 * Uses transient caching to avoid doing repo API calls on every page visit
		 */
		private function assemble_plugin_report( $slug ) {
			if ( ! empty( $slug ) ) {
				$report    = array();
				$cache_key = $this->create_cache_key( $slug );
				$cache     = get_site_transient( $cache_key );
				$plugins   = get_plugins();

				if ( empty( $cache ) ) {

					// Add the plugin's slug to the report.
					$report['slug'] = $slug;

					// Get the locally available info, and add it  to the report.
					foreach ( $plugins as $key => $plugin ) {
						if ( $this->get_plugin_slug( $key ) == $slug ) {
							$report['local_info'] = $plugin;
							$report['file_path'] = $key;
							break;
						}
					}

					// Use the wordpress.org repository API to get detailed information.
					$args = array(
						'slug'   => $slug,
						'fields' => array(
							'description'   => false,
							'sections'      => false,
							'tags'          => false,
							'version'       => true,
							'tested'        => true,
							'requires'      => true,
							'requires_php'  => true,
							'compatibility' => true,
							'author'        => true,
						),
					);
					$returned_object = plugins_api( 'plugin_information', $args );

					// Add the repo info to the report.
					if ( ! is_wp_error( $returned_object ) ) {
						$report['repo_info'] = maybe_unserialize( $returned_object );
						// Cache the report.
						set_site_transient( $cache_key, $report, self::CACHE_LIFETIME );
					} else {
						// Store the error code and message in the report.
						$report['repo_error_code'] = $returned_object->get_error_code();
						$report['repo_error_message'] = $returned_object->get_error_message();
						// Cache for an extra long time when the plugin is not in the repo.
						set_site_transient( $cache_key, $report, self::CACHE_LIFETIME_NOREPO );
					}
				} else {
					$report = $cache;
				}

				return $report;

			} else {
				return null;
			}

		}


		/**
		 * From a report, generate an HTML table row with relevant data for the plugin
		 */
		private function render_table_row( $report ) {
			// Get the latest WP release version number.
			$wp_latest = $this->check_core_updates();
			// Check if the report is valid.
			if ( $report == null ) {
				$html = $this->render_error_row( esc_html__( 'No plugin data available.', 'plugin-report' ) );
			} else {
				// Start the new table row.
				$html = '<tr class="rt-plugin-report-row-' . $report['slug'] . '">';
				// Name.
				if( isset( $report['local_info']['PluginURI'] ) && !empty( $report['local_info']['PluginURI'] ) ){
					$html .= '<td><a href="' . $report['local_info']['PluginURI'] . '"><strong>' . $report['local_info']['Name'] . '</strong></a></td>';
				} else {
					$html .= '<td><strong>' . $report['local_info']['Name'] . '</strong></td>';
				}
				// Author.
				if( isset( $report['local_info']['AuthorURI'] ) && !empty( $report['local_info']['AuthorURI'] ) ){
					$html .= '<td><a href="' . $report['local_info']['AuthorURI'] . '">' . $report['local_info']['Author'] . '</a></td>';
				} else {
					$html .= '<td>' . $report['local_info']['Author'] . '</td>';
				}
				// Activated.
				$active = __( 'Please clear cache to update', 'plugin-report' );
				$css_class = self::CSS_CLASS_MED;
				if( is_multisite() ){
					$activation_status = $this->get_multisite_activation( $report['file_path'] );
					if( $activation_status['network'] === true ){
						$css_class = self::CSS_CLASS_LOW;
						$html .= '<td class="' . $css_class . '">' . __( 'Network activated', 'plugin-report' ) . '</td>';
					} else {
						$css_class = ( $activation_status['active'] > 0 ) ? self::CSS_CLASS_LOW : self::CSS_CLASS_HIGH;
						$html .= '<td class="' . $css_class . '">' . $activation_status['active'] . '/' . $activation_status['sites'] . '</td>';
					}
				} else {
					if( isset( $report['file_path'] ) ){
						$active    = is_plugin_active( $report['file_path'] ) ? __( 'Yes', 'plugin-report' ) : __( 'No', 'plugin-report' );
						$css_class = is_plugin_active( $report['file_path'] ) ? self::CSS_CLASS_LOW : self::CSS_CLASS_HIGH;
					}
					$html .= '<td class="' . $css_class . '">' . $active . '</td>';
				}
				// Installed / available version.
				if( isset( $report['repo_info'] ) ){
					$css_class = $this->get_version_risk_classname( $report['local_info']['Version'], $report['repo_info']->version );
					$html .= '<td class="' . $css_class . '">';
					$html .= $report['local_info']['Version'];
					// Check is current version is not the stabel version from the repo.
					if ( $report['local_info']['Version'] != $report['repo_info']->version ) {
						// Get current version of the platform.
						global $wp_version;
						$php_installed = '5.1';//phpversion();
						$wp_installed = '4.5';//$wp_version;
						// Any upgrades needed?
						$needs_php_upgrade = version_compare( $php_installed, $report['repo_info']->requires_php, '<' );
						$needs_wp_upgrade = version_compare( $wp_installed, $report['repo_info']->requires, '<' );
						// Create the additional message.
						if( $needs_wp_upgrade && $needs_php_upgrade ){
							/* translators: %1$s: Plugin version number, %2$s: WP version number, %3$s: PHP version number */
							$html .= ' <span class="rt-additional-info">' . sprintf( esc_html__( '(%1$s available, requires WP %2$s and PHP %3$s)', 'plugin-report'), $report['repo_info']->version, $report['repo_info']->requires, $report['repo_info']->requires_php ) . '</span>';
						} elseif( $needs_wp_upgrade ) {
							/* translators: %1$s: Plugin version number, %2$s: WP version number. */
							$html .= ' <span class="rt-additional-info">' . sprintf( esc_html__( '(%1$s available, requires WP %2$s)', 'plugin-report'), $report['repo_info']->version, $report['repo_info']->requires ) . '</span>';
						} elseif( $needs_php_upgrade ){
							/* translators: %1$s: Plugin version number, %2$s: PHP version number. */
							$html .= ' <span class="rt-additional-info">' . sprintf( esc_html__( '(%1$s available, requires PHP %2$s)', 'plugin-report'), $report['repo_info']->version, $report['repo_info']->requires_php ) . '</span>';
						} else {
							/* translators: %s: Plugin version number. */
							$html .= ' <span class="rt-additional-info">' . sprintf( esc_html__( '(%s available)', 'plugin-report'), $report['repo_info']->version ) . '</span>';
						}
					}
					$html .= '</td>';
				} else {
					$html .= '<td>' . $report['local_info']['Version'] . '</td>';
				}
				// Last updates.
				if( isset( $report['repo_info'] ) ){
					$time_update = new DateTime( $report['repo_info']->last_updated );
					$time_diff   = human_time_diff( $time_update->getTimestamp(), current_time( 'timestamp' ) );
					$css_class   = $this->get_timediff_risk_classname( current_time( 'timestamp' ) - $time_update->getTimestamp() );
					$html       .= '<td class="' . $css_class . '">' . $time_diff . '</td>';
				} else {
					$html .= $this->render_error_cell();
				}
				// Tested up to.
				if( isset( $report['repo_info'] ) ){
					$css_class = $this->get_version_risk_classname( $report['repo_info']->tested, $wp_latest );
					$html .= '<td class="' . $css_class . '">' . $report['repo_info']->tested . '</td>';
				} else {
					$html .= $this->render_error_cell();
				}
				// Overall user rating.
				if( isset( $report['repo_info'] ) ){
					$css_class  = ( intval( $report['repo_info']->num_ratings ) > 0 ) ? $this->get_percentage_risk_classname( intval( $report['repo_info']->rating ) ) : '';
					$value_text = ( ( intval( $report['repo_info']->num_ratings ) > 0 ) ? $report['repo_info']->rating . '%' : esc_html__( 'No data available', 'plugin-report' ) );
					$html      .= '<td class="' . $css_class . '">' . $value_text . '</td>';
				} else {
					$html .= $this->render_error_cell();
				}
				// Close the new table row.
				$html .= '</tr>';
			}
			return $html;
		}


		/**
		 * Format an error message as a table row, so we can return it to javascript
		 */
		private function render_error_row( $message ) {
			return '<tr class="rt-pluginreport-row-error"><td colspan="' . self::COLS_PER_ROW . '">' . $message . '</td></tr>';
		}


		/**
		 * Format an error message as a table cell, so we can return it to javascript
		 */
		private function render_error_cell( $message = null ) {
			if( !$message ){
				$message = esc_html__( 'No data available', 'plugin-report' );
			}
			return '<td class="rt-pluginreport-cell-error">' . $message . '</td>';
		}


		/**
		 * Figure out what CSS class to use based on current and optimal version numbers
		 */
		private function get_version_risk_classname( $available, $optimal ) {
			// If the version match, indicate low risk.
			if ( version_compare( $available, $optimal, '==' ) ) {
				return self::CSS_CLASS_LOW;
			}
			// If major version match, indicate medium risk.
			if ( version_compare( $this->major_release_version_nr( $available ), $this->major_release_version_nr( $optimal ), '==' ) ) {
				return self::CSS_CLASS_MED;
			}
			// Else, indicate high risk.
			return self::CSS_CLASS_HIGH;
		}


		/**
		 * Assess the risk associated with low ratings or poor compatibility feedback, return corresponding CSS class
		 */
		private function get_percentage_risk_classname( $perc ) {
			if ( $perc < 70 ) {
				return self::CSS_CLASS_HIGH;
			}
			if ( $perc < 90 ) {
				return self::CSS_CLASS_MED;
			}
			return self::CSS_CLASS_LOW;
		}


		/**
		 * Assess the risk associated with low ratings or poor compatibility feedback, return corresponding CSS class
		 */
		private function get_timediff_risk_classname( $time_diff ) {
			$days = $time_diff / ( DAY_IN_SECONDS );
			if ( $days > 365 ) {
				return self::CSS_CLASS_HIGH;
			}
			if ( $days > 90 ) {
				return self::CSS_CLASS_MED;
			}
			return self::CSS_CLASS_LOW;
		}


		/**
		 * Get the latest available WordPress version using WP core functions
		 * This way, we don't need to do any API calls. WP check this periodically anyway.
		 */
		private function check_core_updates() {
			global $wp_version;
			$update = get_preferred_from_update_core();
			// Bail out of no valid response, or false.
			if ( ! $update || $update == false ) {
				return $wp_version;
			}
			// If latest, return current version number.
			if ( $update->response == 'latest' ) {
				return $wp_version;
			}
			// Return the preferred update's version number.
			return $update->version;
		}


		/**
		 * Extract the major release number from a WP version nr
		 */
		private function major_release_version_nr( $version ) {
			$parts = explode( '.', $version );
			$parts = array_slice( $parts, 0, 2 );
			return implode( '.', $parts );
		}


		/**
		 * Gather statistics about a plugin's activation on a multisite install
		 */
		private function get_multisite_activation( $path ){
			// Create an array to contain the return values.
			$activation_status = array(
				'network' => false,
				'active'  => 0,
				'sites'   => 1,
			);
			// Check if the plugin is network activated.
			$network_plugins = get_site_option( 'active_sitewide_plugins', null );
			if( array_key_exists( $path, $network_plugins ) ){
				$activation_status['network'] = true;
			} else {
				// Get a list of all sites in the multisite install.
				$args = array(
					'number' => 9999,
					'fields' => 'ids',
				);
				$sites = get_sites( $args );
				// Add the total number of sites to the return array.
				$activation_status['sites'] = count( $sites );
				// Loop through the sites to find where the plugin is active.
				foreach( $sites as $site_id ){
					$plugins = get_blog_option( $site_id, 'active_plugins', null );
					if( $plugins ){
						foreach( $plugins as $plugin_path ){
							if( $plugin_path === $path ){
								$activation_status['active']++;
							}
						}
					}
				}
			}
			// Return the data we gathered.
			return $activation_status;
		}


		/**
		 * Create a cache key that is unique to the provided plugin slug.
		 */
		private function create_cache_key( $slug ) {
			// Create a hash for the plugiin slug.
			$slug_hash = hash( 'sha256', $slug );
			// Prefix and limit the string to 40 characters to avoid issues with long keys.
			$cache_key = 'rtpr_' . substr( $slug_hash, 0, 35 );
			// Return the key.
			return $cache_key;
		}


		/**
		 * Clear all cached plugin info
		 */
		private function clear_cache() {
			// Request a list of all plugins.
			$plugins = get_plugins();
			// Loop through the plugins array, and delete cache items.
			foreach ( $plugins as $key => $plugin ) {
				$slug = $this->get_plugin_slug( $key );
				$this->clear_cache_item( $slug );
			}
		}


		/**
		 * Remove the cache item for a single plugin
		 */
		private function clear_cache_item( $slug ) {
			if ( isset( $slug ) ) {
				$cache_key = $this->create_cache_key( $slug );
				delete_site_transient( $cache_key );
			}			
		}


		/**
		 * Selectively delete cache for plugins that have been updated.
		 */
		public function upgrade_delete_cache_items( $upgrader, $data ) {
			// Check if plugins have been upgraded by WP.
			if ( isset( $data ) && isset( $data['plugins'] ) && is_array( $data['plugins'] ) ) {
				// Loop through the plugins, and delete the associated cache items.
				foreach ( $data['plugins'] as $key => $value ) {
					$slug = $this->get_plugin_slug( $value );
					$this->clear_cache_item( $slug );
				}
			}
		}

	}

	// Instantiate the class.
	$rt_plugin_report_instance = new RT_Plugin_Report();
	$rt_plugin_report_instance->init();

}
