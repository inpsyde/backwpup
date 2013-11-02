<?php
/**
 * Plugin Name: BackWPup
 * Plugin URI: https://marketpress.com/product/backwpup-pro/
 * Description: WordPress Backup Plugin
 * Author: Inpsyde GmbH
 * Author URI: http://inpsyde.com
 * Version: 3.0.14-beta2
 * Text Domain: backwpup
 * Domain Path: /languages/
 * Network: true
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0
 * Slug: backwpup
 */

/**
 *	Copyright (C) 2012-2013 Inpsyde GmbH (email: info@inpsyde.com)
 *
 *	This program is free software; you can redistribute it and/or
 *	modify it under the terms of the GNU General Public License
 *	as published by the Free Software Foundation; either version 2
 *	of the License, or (at your option) any later version.
 *
 *	This program is distributed in the hope that it will be useful,
 *	but WITHOUT ANY WARRANTY; without even the implied warranty of
 *	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *	GNU General Public License for more details.
 *
 *	You should have received a copy of the GNU General Public License
 *	along with this program; if not, write to the Free Software
 *	Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

if ( ! class_exists( 'BackWPup' ) ) {

	// Don't activate on anything less than PHP 5.2.4 or WordPress 3.1
	if ( version_compare( PHP_VERSION, '5.2.6', '<' ) || version_compare( get_bloginfo( 'version' ), '3.2', '<' ) || ! function_exists( 'spl_autoload_register' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		deactivate_plugins( basename( __FILE__ ) );
		if ( isset( $_GET['action'] ) && ( $_GET['action'] == 'activate' || $_GET['action'] == 'error_scrape' ) )
			die( __( 'BackWPup requires PHP version 5.2.6 with spl extension or greater and WordPress 3.2 or greater.', 'backwpup' ) );
	}

	//Start Plugin
	if ( function_exists( 'add_filter' ) )
		add_action( 'plugins_loaded', array( 'BackWPup', 'get_instance' ), 11 );

	/**
	 * Main BackWPup Plugin Class
	 */
	final class BackWPup {

		private static $instance = NULL;
		private static $plugin_data = array();
		private static $destinations = array();
		private static $registered_destinations = array();
		private static $job_types = array();
		private static $wizards = array();

		/**
		 * Set needed filters and actions and load
		 */
		private function __construct() {

			// Nothing else matters if we're not on the main site
			if ( ! is_main_site() )
				return;
			//auto loader
			spl_autoload_register( array( $this, 'autoloader' ) );
			//Options
			new BackWPup_Option();
			//start upgrade if needed
			if ( get_site_option( 'backwpup_version' ) != self::get_plugin_data( 'Version' ) )
				BackWPup_Install::activate();
			//load pro features
			if ( file_exists( dirname( __FILE__ ) . '/inc/pro/class-pro.php' ) )
				require dirname( __FILE__ ) . '/inc/pro/class-pro.php';
			//WP-Cron
			if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
				//early disable caches
				if ( ! empty( $_GET[ 'backwpup_run' ] ) && class_exists( 'BackWPup_Job' ) )
					BackWPup_Job::disable_caches();
				// add normal cron actions
				add_action( 'backwpup_cron', array( 'BackWPup_Cron', 'run' ) );
				add_action( 'backwpup_check_cleanup', array( 'BackWPup_Cron', 'check_cleanup' ) );
				// add action for doing thinks if cron active
				// must done in int before wp-cron control
				add_action( 'init', array( 'BackWPup_Cron', 'cron_active' ), 1 );
				// if in cron the rest must not needed
				return;
			}
			//deactivation hook
			register_deactivation_hook( __FILE__, array( 'BackWPup_Install', 'deactivate' ) );
			//Things that must do in plugin init
			add_action( 'init', array( $this, 'plugin_init' ) );
			//only in backend
			if ( is_admin() && class_exists( 'BackWPup_Admin' ) )
				BackWPup_Admin::get_instance();
			//work with wp-cli
			if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) && class_exists( 'BackWPup_WP_CLI' ) )
				WP_CLI::addCommand( 'backwpup', 'BackWPup_WP_CLI' );
		}

		/**
		 * @static
		 *
		 * @return self
		 */
		public static function get_instance() {

			if (NULL === self::$instance) {
				self::$instance = new self;
			}
			return self::$instance;
		}


		private function __clone() {}

		/**
		 * get information about the Plugin
		 *
		 * @param string $name Name of info to get or NULL to get all
		 * @return string|array
		 */
		public static function get_plugin_data( $name = NULL ) {

			if ( $name )
				$name = strtolower( $name );

			if ( empty( self::$plugin_data ) ) {
				self::$plugin_data = get_file_data( __FILE__, array(
																   'name'        => 'Plugin Name',
																   'pluginuri'   => 'Plugin URI',
																   'version'     => 'Version',
																   'description' => 'Description',
																   'author'      => 'Author',
																   'authoruri'   => 'Author URI',
																   'textdomain'  => 'Text Domain',
																   'domainpath'  => 'Domain Path',
																   'slug'  		 => 'Slug',
																   'license'     => 'License',
																   'licenseuri'  => 'License URI'
															  ), 'plugin' );
				//Translate some vars
				self::$plugin_data[ 'name' ]        = trim( self::$plugin_data[ 'name' ] );
				self::$plugin_data[ 'pluginuri' ]   = trim( self::$plugin_data[ 'pluginuri' ] );
				self::$plugin_data[ 'description' ] = trim( self::$plugin_data[ 'description' ] );
				self::$plugin_data[ 'author' ]      = trim( self::$plugin_data[ 'author' ] );
				self::$plugin_data[ 'authoruri' ]   = trim( self::$plugin_data[ 'authoruri' ] );
				//set some extra vars
				self::$plugin_data[ 'basename' ] = plugin_basename( dirname( __FILE__ ) );
				self::$plugin_data[ 'mainfile' ] = __FILE__ ;
				self::$plugin_data[ 'plugindir' ] = untrailingslashit( dirname( __FILE__ ) ) ;
				self::$plugin_data[ 'hash' ] = get_site_option( 'backwpup_cfg_hash' );
				if ( empty( self::$plugin_data[ 'hash' ] ) || strlen( self::$plugin_data[ 'hash' ] ) < 6 || strlen( self::$plugin_data[ 'hash' ] ) > 12 ) {
					update_site_option( 'backwpup_cfg_hash', substr( md5( md5( BackWPup::get_plugin_data( "mainfile" ) ) ), 14, 6 ) );
					self::$plugin_data[ 'hash' ] = get_site_option( 'backwpup_cfg_hash' );
				}
				if ( defined( 'WP_TEMP_DIR' ) && is_dir( WP_TEMP_DIR ) ) {
					self::$plugin_data[ 'temp' ] = trailingslashit( str_replace( '\\', '/', realpath( WP_TEMP_DIR ) ) . '/backwpup-' . self::$plugin_data[ 'hash' ] );
				} else {
					$upload_dir = wp_upload_dir();
					self::$plugin_data[ 'temp' ] = trailingslashit( str_replace( '\\', '/', realpath( $upload_dir[ 'basedir' ] ) ) . '/backwpup-' . self::$plugin_data[ 'hash' ] . '-temp' );
				}
				self::$plugin_data[ 'running_file' ] = self::$plugin_data[ 'temp' ] . 'backwpup-working.php';
				self::$plugin_data[ 'url' ] = plugins_url( '', __FILE__ );
				//get unmodified WP Versions
				include ABSPATH . WPINC . '/version.php';
				/** @var $wp_version string */
				self::$plugin_data[ 'wp_version' ] = $wp_version;
				//Build User Agent
				self::$plugin_data[ 'user-agent' ] = self::$plugin_data[ 'name' ].'/' . self::$plugin_data[ 'version' ] . '; WordPress/' . self::$plugin_data[ 'wp_version' ] . '; ' . home_url();
			}

			if ( ! empty( $name ) )
				return self::$plugin_data[ $name ];
			else
				return self::$plugin_data;
		}


		/**
		 * include not existing classes automatically
		 *
		 * @param string $class_name Class to load from file
		 */
		private function autoloader( $class_name ) {

			$class_name = strtolower( $class_name );
			if ( strstr( $class_name, 'backwpup_' ) ) {
				$dir = dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'inc' . DIRECTORY_SEPARATOR;
				$class_file_name = 'class-' . str_replace( array( 'backwpup_', '_' ), array( '', '-' ), $class_name ) . '.php';
				if ( strstr( $class_name, 'backwpup_pro_' ) ) {
					$dir .=  'pro' . DIRECTORY_SEPARATOR;
					$class_file_name = str_replace( '-pro','', $class_file_name );
				}
				if ( file_exists( $dir . $class_file_name ) )
					require $dir . $class_file_name;
			}
		}

		/**
		 * Plugin init function
		 *
		 * @return void
		 */
		public function plugin_init() {

			//Add Admin Bar
			if ( ! defined( 'DOING_CRON' ) && current_user_can( 'backwpup' ) && current_user_can( 'backwpup' ) && is_admin_bar_showing() && get_site_option( 'backwpup_cfg_showadminbar' ) )
				BackWPup_Adminbar::get_instance();
		}

		/**
		 * Get a array of instances for Backup Destination's
		 *
		 * @param $key string Key of Destination where get class instance from
		 * @return array BackWPup_Destinations
		 */
		public static function get_destination( $key ) {

			$key  = strtoupper( $key );

			if ( isset( self::$destinations[ $key ] ) && is_object( self::$destinations[ $key ] ) )
				return self::$destinations[ $key ];

			$reg_dests = self::get_registered_destinations();
			if ( ! empty( $reg_dests[ $key ][ 'class' ] ) ) {
				self::$destinations[ $key ] = new $reg_dests[ $key ][ 'class' ];
			} else {
				return NULL;
			}

			return self::$destinations[ $key ];
		}

		/**
		 * Get a array of registered Destination's for Backups
		 *
		 * @return array BackWPup_Destinations
		 */
		public static function get_registered_destinations() {

			//only run it one time
			if ( ! empty( self::$registered_destinations ) )
				return self::$registered_destinations;

			//add BackWPup Destinations
			// to folder
			self::$registered_destinations[ 'FOLDER' ] 	= array(
								'class' => 'BackWPup_Destination_Folder',
								'info'	=> array(
									'ID'        	=> 'FOLDER',
									'name'       	=> __( 'Folder', 'backwpup' ),
									'description' 	=> __( 'Backup to Folder', 'backwpup' ),
								),
								'can_sync' => FALSE,
								'needed' => array(
									'php_version'	=> '',
									'functions'	=> array(),
									'classes'	=> array()
								)
							);
			// backup with mail
			self::$registered_destinations[ 'EMAIL' ] 	= array(
								'class' => 'BackWPup_Destination_Email',
								'info'	=> array(
									'ID'        	=> 'EMAIL',
									'name'       	=> __( 'E-Mail', 'backwpup' ),
									'description' 	=> __( 'Backup sent by e-mail', 'backwpup' ),
								),
								'can_sync' => FALSE,
								'needed' => array(
									'php_version'	=> '',
									'functions'	=> array(),
									'classes'	=> array()
								)
							);
			// backup to ftp
			self::$registered_destinations[ 'FTP' ] 	= array(
								'class' => 'BackWPup_Destination_Ftp',
								'info'	=> array(
									'ID'        	=> 'FTP',
									'name'       	=> __( 'FTP', 'backwpup' ),
									'description' 	=> __( 'Backup to FTP', 'backwpup' ),
								),
								'can_sync' => FALSE,
								'needed' => array(
									'mphp_version'	=> '',
									'functions'	=> array( 'ftp_login' ),
									'classes'	=> array()
								)
							);
			// backup to dropbox
			self::$registered_destinations[ 'DROPBOX' ] 	= array(
								'class' => 'BackWPup_Destination_Dropbox',
								'info'	=> array(
									'ID'        	=> 'DROPBOX',
									'name'       	=> __( 'Dropbox', 'backwpup' ),
									'description' 	=> __( 'Backup to Dropbox', 'backwpup' ),
								),
								'can_sync' => FALSE,
								'needed' => array(
									'php_version'	=> '',
									'functions'	=> array( 'curl_exec' ),
									'classes'	=> array()
								)
							);
			// Backup to S3
			if ( version_compare( PHP_VERSION, '5.3.3', '>=' ) )
				self::$registered_destinations[ 'S3' ] 	= array(
									'class' => 'BackWPup_Destination_S3',
									'info'	=> array(
										'ID'        	=> 'S3',
										'name'       	=> __( 'S3 Service', 'backwpup' ),
										'description' 	=> __( 'Backup to an S3 Service', 'backwpup' ),
									),
									'can_sync' => FALSE,
									'needed' => array(
										'php_version'	=> '5.3.3',
										'functions'	=> array( 'curl_exec' ),
										'classes'	=> array()
									)
								);
			else
				self::$registered_destinations[ 'S3' ] 	= array(
									'class' => 'BackWPup_Destination_S3_V1',
									'info'	=> array(
										'ID'        	=> 'S3',
										'name'       	=> __( 'S3 Service', 'backwpup' ),
										'description' 	=> __( 'Backup to an S3 Service v1', 'backwpup' ),
									),
									'can_sync' => FALSE,
									'needed' => array(
										'php_version'	=> '',
										'functions'	=> array( 'curl_exec' ),
										'classes'	=> array()
									)
								);

			// backup to MS Azure
			self::$registered_destinations[ 'MSAZURE' ] 	= array(
								'class' => 'BackWPup_Destination_MSAzure',
								'info'	=> array(
									'ID'        	=> 'MSAZURE',
									'name'       	=> __( 'MS Azure', 'backwpup' ),
									'description' 	=> __( 'Backup to Microsoft Azure (Blob)', 'backwpup' ),
								),
								'can_sync' => FALSE,
								'needed' => array(
									'php_version'	=> '5.3.2',
									'functions'	=> array(),
									'classes'	=> array()
								)
							);
			// backup to Rackspace Cloud
			self::$registered_destinations[ 'RSC' ] 	= array(
								'class' => 'BackWPup_Destination_RSC',
								'info'	=> array(
									'ID'        	=> 'RSC',
									'name'       	=> __( 'RSC', 'backwpup' ),
									'description' 	=> __( 'Backup to Rackspace Cloud Files', 'backwpup' ),
								),
								'can_sync' => FALSE,
								'needed' => array(
									'php_version'	=> '5.3.3',
									'functions'	=> array( 'curl_exec' ),
									'classes'	=> array()
								)
							);
			// backup to Sugarsync
			self::$registered_destinations[ 'SUGARSYNC' ] 	= array(
								'class' => 'BackWPup_Destination_SugarSync',
								'info'	=> array(
									'ID'        	=> 'SUGARSYNC',
									'name'       	=> __( 'SugarSync', 'backwpup' ),
									'description' 	=> __( 'Backup to SugarSync', 'backwpup' ),
								),
								'can_sync' => FALSE,
								'needed' => array(
									'php_version'	=> '',
									'functions'	=> array( 'curl_exec' ),
									'classes'	=> array()
								)
							);

			//Hook for adding Destinations like above
			self::$registered_destinations = apply_filters( 'backwpup_register_destination', self::$registered_destinations );

			//check BackWPup Destinations
			foreach ( self::$registered_destinations as $dest_key => $dest ) {
				self::$registered_destinations[ $dest_key ][ 'error'] = '';
				// check PHP Version
				if ( ! empty( $dest[ 'needed' ][ 'php_version' ] ) && version_compare( PHP_VERSION, $dest[ 'needed' ][ 'php_version' ], '<' ) ) {
					self::$registered_destinations[ $dest_key ][ 'error' ] .= sprintf( __( 'PHP Version %1$s is to low you need Version %2$s or above.', 'backwpup' ), PHP_VERSION, $dest[ 'needed' ][ 'php_version' ] ) . ' ';
					self::$registered_destinations[ $dest_key ][ 'class' ] = NULL;
				}
				//check functions exists
				if ( ! empty( $dest[ 'needed' ][ 'functions' ] ) ) {
					foreach ( $dest[ 'needed' ][ 'functions' ] as $function_need ) {
						if ( ! function_exists( $function_need ) ) {
							self::$registered_destinations[ $dest_key ][ 'error' ] .= sprintf( __( 'Missing function "%s".', 'backwpup' ), $function_need ) . ' ';
							self::$registered_destinations[ $dest_key ][ 'class' ] = NULL;
						}
					}
				}
				//check classes exists
				if ( ! empty( $dest[ 'needed' ][ 'classes' ] ) ) {
					foreach ( $dest[ 'needed' ][ 'classes' ] as $class_need ) {
						if ( ! class_exists( $class_need ) ) {
							self::$registered_destinations[ $dest_key ][ 'error' ] .= sprintf( __( 'Missing class "%s".', 'backwpup' ), $class_need ) . ' ';
							self::$registered_destinations[ $dest_key ][ 'class' ] = NULL;
						}
					}
				}
			}

			return self::$registered_destinations;
		}


		/**
		 * Gets a array of instances from Job types
		 *
		 * @return array BackWPup_JobTypes
		 */
		public static function get_job_types() {

			if ( !empty( self::$job_types ) )
				return self::$job_types;

			self::$job_types[ 'DBDUMP' ]	= new BackWPup_JobType_DBDump;
			self::$job_types[ 'FILE' ] 		= new BackWPup_JobType_File;
			self::$job_types[ 'WPEXP' ] 	= new BackWPup_JobType_WPEXP;
			self::$job_types[ 'WPPLUGIN' ]  = new BackWPup_JobType_WPPlugin;
			self::$job_types[ 'DBCHECK' ]   = new BackWPup_JobType_DBCheck;

			self::$job_types = apply_filters( 'backwpup_job_types', self::$job_types );

			//remove types can't load
			foreach ( self::$job_types as $key => $job_type ) {
				if ( empty( $job_type ) || ! is_object( $job_type ) )
					unset( self::$job_types[ $key ] );
			}

			return self::$job_types;
		}


		/**
		 * Gets a array of instances from Wizards
		 *
		 * @return array BackWPup_Pro_Wizards
		 */
		public static function get_wizards() {

			if ( !empty( self::$wizards ) )
				return self::$wizards;

			self::$wizards  = apply_filters( 'backwpup_pro_wizards', self::$wizards );

			//remove wizards can't load
			foreach ( self::$wizards as $key => $wizard ) {
				if ( empty( $wizard ) || ! is_object( $wizard ) )
					unset( self::$wizards[ $key ] );
			}

			return self::$wizards;

		}

	}

}
