<?php 
/*
Plugin Name: 	CF7 to Spreadsheet
Plugin URI: 	http://www.brainstormforce.com
Description: 	Save your Contact Form 7 data to Google Spreadsheet.
Version: 		1.0
Author: 		Brainstorm Force     
Author URI:		https://www.brainstormforce.com/
Text Domain: 	cf-7-to-spreadsheet
*/

//Block direct access to plugin files

defined( 'ABSPATH' ) or die();

require_once plugin_dir_path( __FILE__ ).'lib/autoload.php';
require_once plugin_dir_path( __FILE__ ).'lib/php-google-oauth/Google_Client.php';
require ( plugin_dir_path( __FILE__ ). "lib/class-google-spreadsheet.php" );

if( !class_exists( "Cf7_to_Spreadsheet" ) ) {
	
	class Cf7_to_Spreadsheet {
		public function __construct() {
			//Access code pre-authentication
			add_action( 'init', array ( $this, 'pre_authentication' ) );
			// Add settings to admin menu.
			add_action( 'admin_menu', array ( $this, 'register_setting' ) );
			// Register your settings
			add_action( 'admin_init', array ( $this, 'cf7_to_spreadsheet_register_setting' ) );
			// Load plugin textdomain
			add_action( 'init', array ( $this, 'load_textdomain' ) );
			// Add admin notice for Contact Form 7 inactive
			add_action( 'admin_notices', array ( $this,'confirm_cf7_activate' ) );
			// Add new tab to contact form 7 editors panel
			add_filter( 'wpcf7_editor_panels', array ( $this, 'editor_panel' ) );
			// Save spreadsheet settings from Contact Form 7 after submit
			add_action('wpcf7_after_save', array ( $this,'save_settings' ) );
			// Add data to spreadsheet after Contact Form 7 mail sent
			add_action( 'wpcf7_mail_sent', array ( $this,'send_data' ) );
		}

		/**
		* Function Name: register_setting
		* Function Description: Add plugin in admin setting menu
		*/

		public function register_setting() {
			add_submenu_page(
				'options-general.php',								// Parent menu item slug
				__( 'CF7 to Spreadsheet','cf-7-to-spreadsheet' ),	// Page Title
				__( 'CF7 to Spreadsheet','cf-7-to-spreadsheet' ),	// Menu Title
				'manage_options',									// Capability
				'cf7-to-spreadsheet',								// Menu Slug
				array ($this,'setting_page')						// Callback function
			);

			// Register and enqueue our stylesheet.
			wp_register_style( 'cf7_to_gs_style', plugins_url( 'css/cf7-to-sheet-style.css',__FILE__ ) );
			wp_enqueue_style( 'cf7_to_gs_style' );

			// Register and enqueue our custom script.
			wp_enqueue_script('jquery');
			wp_register_script('custom-script', plugin_dir_url(__FILE__).'js/script.js');
			wp_enqueue_script('custom-script');
		}
	

		/**
		* Function Name: confirm_cf7_activate
		* Function Description: Notice for contact form activation
		*/
		public function confirm_cf7_activate() {
			if ( !function_exists( 'wpcf7' ) ) {
				$network_url = network_admin_url( 'plugin-install.php?s=contact+form+7&tab=search&type=term' );
				echo '<div class="error"><p>CF7 to Spreadsheet requires <a href=".$network_url."> Contact Form7</a> is installed and activated. </p></div>';
			}	
		}

		/**
		* Function Name: register_setting
		* Function Description: Register our settings.
		*/
		public function cf7_to_spreadsheet_register_setting() {
			register_setting( 'cf7_to_spreadsheet_plugin_setting', 'cf7_to_spreadsheet_google_code' );
		}

		/**
		* Function Name: load_textdomain
		* Function Description: Load plugin textdomain.
		* @since 1.0.0
		*/
		public function load_textdomain() {
			load_plugin_textdomain( 'cf-7-to-spreadsheet' ); 
		}

		/**
		* Function Name: save_settings
		* Function Description: Saving google spreadsheet data in db post meta table
		* @param object $post
		*/
		public function save_settings( $post ) {
			update_post_meta( $post->id(), 'cf7_to_spreadsheet_data', $_POST[ 'cf7-sheet' ] );
		}
			
		/**
		* Function Name: editor_panel
		* Function Description: Add new tab to contact form 7 editors panel
		* @since 1.0
		* @param array $panels
		* @return array $panels
		*/
		public function editor_panel( $panels ) {
			$panels[ 'google_sheets' ] = array(
				'title'    => __( 'CF7 to Spreadsheet', 'cf-7-to-spreadsheet' ),
				'callback' =>array ( $this,'spreadsheet_editor_panel' )
		  	);
		  	return $panels;
		}

		/**
		* Function Name: setting_page
		* Function Description: Register google access code
		*/
		public function setting_page( $post ) {
			ob_start();
			require ( plugin_dir_path( __FILE__ ).'include/cf7-setting-page-html.php' );
			$setting_page_html = ob_get_contents();
			ob_end_clean();
			echo $setting_page_html;
		}

		/**
		* Function Name: pre_authentication
		* Function Description: Pre-authentication for generating access code
		*/
		public function pre_authentication() {
			if ( isset ( $_GET['settings-updated'] ) && get_option( 'cf7_to_spreadsheet_google_code' ) != '' ) {
				//call method to preauthontication
				Cf7_Google_Spreadsheet::google_pre_authentication( get_option( 'cf7_to_spreadsheet_google_code' ) );
				update_option( 'cf7_to_spreadsheet_google_code', null );
			}
		}

		/**
		* Function Name: spreadsheet_editor_panel
		* Function Description: Saving google spreadsheet data
		* @param object $post
		*/
		public function spreadsheet_editor_panel( $post ) {
			ob_start();
			include_once ( plugin_dir_path( __FILE__ ).'include/cf7-editor-panel-html.php' );
			$editor_panel_html = ob_get_contents();
			ob_end_clean();
			echo $editor_panel_html;
		}


		/**
		* Function Name: send_data
		* Function Description: Sending contact form 7 data to google spreadsheet.
		* @param object $cf7data
		*/
		public function send_data( $cf7data ) {
			$submission      	= WPCF7_Submission::get_instance();
			$cf7_form_id     	= $cf7data->id();
			$speadsheet_data 	= get_post_meta( $cf7_form_id, 'cf7_to_spreadsheet_data' );
			$sheet_name      	= $speadsheet_data[0][ 'sheet-name' ];
			$sheet_tab_name  	= $speadsheet_data[0][ 'sheet-tab-name' ];
			$cf7_toggle_button  = $speadsheet_data[0][ 'checked' ];
			$cf7_form_data 	 	= array();
			if ( $submission && isset( $cf7_toggle_button )) {
				$posted_data = $submission->get_posted_data();
				$doc = new Cf7_Google_Spreadsheet();
				$doc->google_authentication();
				// Get spreadsheet by title				
				$spreadsheetService    = new Google\Spreadsheet\SpreadsheetService();
				$spreadsheetFeed       = $spreadsheetService->getSpreadsheetFeed();
				$spreadsheet           = $spreadsheetFeed->getByTitle( $sheet_name );
				// Get particular worksheet of the selected spreadsheet
				$worksheetFeed         = $spreadsheet->getWorksheets();
				$worksheet             = $worksheetFeed->getByTitle( $sheet_tab_name );
				// adding date coloumn to  your spreadsheet
				$cf7_form_data['date'] = date('j F Y');
				foreach ( $posted_data as $key => $value ) {
					// exclude the default wpcf7 fields in object
					// handle strings and array elements
						if ( is_array( $value ) ) {
							$cf7_form_data[$key] = implode( ',', $value );	
						} else {
							$cf7_form_data[$key] = $value;
						}					
					}
				// Inserting data to spreadsheet.
				$listFeed = $worksheet->getListFeed();
				$listFeed->insert( $cf7_form_data );
			}
		}
	}
	new Cf7_to_Spreadsheet();
}