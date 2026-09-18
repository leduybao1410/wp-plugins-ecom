<?php
/**
 * Plugin Name: EPIC Email Branding
 * Plugin URI:        https://epicroastery.example/
 * Description:       Adds a branded header bar and a contact-info footer to every WooCommerce email the store sends (order confirmations/shipped, newsletter, wholesale, sample requests, reviews). The header carries the brand wordmark and hotline; the footer carries the café/roastery addresses, hotline, email, website, Instagram and opening hours. Purely presentational and global — it hooks WooCommerce's own email header action and footer-text filter, so it needs no per-email change. All values are filterable (see Epic_Email_Branding::contact for the `epic_email_branding_contact` filter).
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Author:            EPIC Coffee Roaster
 * Text Domain:       epic-email-branding
 * Requires Plugins:  woocommerce
 *
 * @package Epic_Email_Branding
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'EPIC_EMAIL_BRANDING_VERSION', '1.0.0' );
define( 'EPIC_EMAIL_BRANDING_DIR', plugin_dir_path( __FILE__ ) );

require_once EPIC_EMAIL_BRANDING_DIR . 'includes/class-epic-email-branding.php';

Epic_Email_Branding::init();
