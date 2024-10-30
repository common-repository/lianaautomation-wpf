<?php
/**
 * LianaAutomation WPForms handler
 *
 * PHP Version 7.4
 *
 * @package  LianaAutomation
 * @license  https://www.gnu.org/licenses/gpl-3.0-standalone.html GPL-3.0-or-later
 * @link     https://www.lianatech.com
 */

/**
 * WPForms functionality. Sends the form data to the Automation API.
 *
 * @param mixed $fields    Fields.
 * @param mixed $entry     WPForms param (unused here).
 * @param mixed $form_id   WPForms form id (should be used here somewhere).
 * @param mixed $form_data WPForms structure of the form (unused here).
 *
 * @return null
 */
function lianaautomation_wpf_process_entry_save( $fields, $entry, $form_id, $form_data ) {
	// Gets liana_t tracking cookie if set.
	if ( isset( $_COOKIE['liana_t'] ) ) {
		$liana_t = sanitize_key( $_COOKIE['liana_t'] );
	} else {
		// We shall send the form even without tracking cookie data.
		$liana_t = null;
	}

	// We shall extract the form data to an LianaAutomation compatible array.
	$wpf_array = array();

	/*
	 * Try to find an email address from the form fields data.
	 * ( WPForms is supposed to have a built-in field type 'email'. )
	 */
	$email = null;
	foreach ( $fields as $field ) {
		if ( ! $email && 'email' === $field['type'] ) {
			$email = $field['value'];
		}
		// Fill the wpf_array while iterating the fields.
		$wpf_array[ $field['name'] ] = $field['value'];
	}
	if ( empty( $email ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
			// phpcs:disable WordPress.PHP.DevelopmentFunctions
			error_log( 'ERROR: No /email/i found on form data. Bailing out.' );
			// phpcs:enable
		}
		return false;
	}

	/*
	* Phone number is a WPForms PRO feature
	* (not implemented yet here)
	*
	// Try to find an email address from the form data.
	$sms = null;
	*/

	// Add Gravity Forms 'magic' values for title and id.
	$wpf_array['formtitle'] = $form_data['settings']['form_title'];
	$wpf_array['formid']    = $form_data['id'];

	/**
	* Retrieve Liana Options values (Array of All Options)
	*/
	$lianaautomation_wpf_options = get_option( 'lianaautomation_wpf_options' );

	if ( empty( $lianaautomation_wpf_options ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
			// phpcs:disable WordPress.PHP.DevelopmentFunctions
			error_log( 'lianaautomation_wpf_options was empty' );
			// phpcs:enable
		}
		return false;
	}

	// The user id, integer.
	if ( empty( $lianaautomation_wpf_options['lianaautomation_user'] ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
			// phpcs:disable WordPress.PHP.DevelopmentFunctions
			error_log( 'lianaautomation_options lianaautomation_user was empty' );
			// phpcs:enable
		}
		return false;
	}
	$user = $lianaautomation_wpf_options['lianaautomation_user'];

	// Hexadecimal secret string.
	if ( empty( $lianaautomation_wpf_options['lianaautomation_key'] ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
			// phpcs:disable WordPress.PHP.DevelopmentFunctions
			error_log( 'lianaautomation_wpf_options lianaautomation_key was empty!' );
			// phpcs:enable
		}
		return false;
	}
	$secret = $lianaautomation_wpf_options['lianaautomation_key'];

	// The base url for our API installation.
	if ( empty( $lianaautomation_wpf_options['lianaautomation_url'] ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
			// phpcs:disable WordPress.PHP.DevelopmentFunctions
			error_log( 'lianaautomation_wpf_options lianaautomation_url was empty!' );
			// phpcs:enable
		}
		return false;
	}
	$url = $lianaautomation_wpf_options['lianaautomation_url'];

	// The realm of our API installation, all caps alphanumeric string.
	if ( empty( $lianaautomation_wpf_options['lianaautomation_realm'] ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
			// phpcs:disable WordPress.PHP.DevelopmentFunctions
			error_log( 'lianaautomation_wpf_options lianaautomation_realm was empty!' );
			// phpcs:enable
		}
		return false;
	}
	$realm = $lianaautomation_wpf_options['lianaautomation_realm'];

	// The channel ID of our automation.
	if ( empty( $lianaautomation_wpf_options['lianaautomation_channel'] ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
			// phpcs:disable WordPress.PHP.DevelopmentFunctions
			error_log( 'lianaautomation_wpf_options lianaautomation_channel was empty!' );
			// phpcs:enable
		}
		return false;
	}
	$channel = $lianaautomation_wpf_options['lianaautomation_channel'];

	/**
	* General variables
	*/
	$base_path    = 'rest';             // Base path of the api end points.
	$content_type = 'application/json'; // Content will be send as json.
	$method       = 'POST';             // Method is always POST.

	// Build the identity array!
	$identity = array();
	if ( ! empty( $email ) ) {
		$identity['email'] = $email;
	}
	if ( ! empty( $liana_t ) ) {
		$identity['token'] = $liana_t;
	}
	if ( ! empty( $sms ) ) {
		$identity['sms'] = $sms;
	}

	// Bail out if no identities found!
	if ( empty( $identity ) ) {
		return false;
	}

	// Import Data!
	$path = 'v1/import';

	$data = array(
		'channel'       => $channel,
		'no_duplicates' => false,
		'data'          => array(
			array(
				'identity' => $identity,
				'events'   => array(
					array(
						'verb'  => 'formsend',
						'items' => $wpf_array,
					),
				),
			),
		),
	);

	// Encode our body content data.
	$data = wp_json_encode( $data );
	// Get the current datetime in ISO 8601.
	$date = gmdate( 'c' );
	// md5 hash our body content.
	$content_md5 = md5( $data );
	// Create our signature.
	$signature_content = implode(
		"\n",
		array(
			$method,
			$content_md5,
			$content_type,
			$date,
			$data,
			"/{$base_path}/{$path}",
		),
	);

	$signature = hash_hmac( 'sha256', $signature_content, $secret );

	// Create the authorization header value.
	$auth = "{$realm} {$user}:" . $signature;

	// Create our full stream context with all required headers.
	$ctx = stream_context_create(
		array(
			'http' => array(
				'method'  => $method,
				'header'  => implode(
					"\r\n",
					array(
						"Authorization: {$auth}",
						"Date: {$date}",
						"Content-md5: {$content_md5}",
						"Content-Type: {$content_type}",
					)
				),
				'content' => $data,
			),
		)
	);

	// Build full path, open a data stream, and decode the json response.
	$full_path = "{$url}/{$base_path}/{$path}";

	$fp = fopen( $full_path, 'rb', false, $ctx );

	// If LianaAutomation API settings is invalid or endpoint is not working properly, bail out.
	if ( ! $fp ) {
		return false;
	}
	$response = stream_get_contents( $fp );
	$response = json_decode( $response, true );
}
add_action( 'wpforms_process_entry_save', 'lianaautomation_wpf_process_entry_save', 10, 4 );
