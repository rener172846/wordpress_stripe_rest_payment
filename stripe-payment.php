<?php

/**
 * @wordpress-plugin
 * Plugin Name:       Stripe Payment
 * Version:           1.0.0
 * Author:            Rener
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}


class StripePayment{

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 *
	 * @var string The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 *
	 * @var string The current version of the plugin.
	 */
	protected $version;

  public function __construct() {
		$this->plugin_name = 'payment';
		$this->version = '1.0.0';

    // require includes
    require_once('includes/class-stripe-payment.php');
    // require endpoints
    require_once('endpoints/class-stripe-payment.php');
		
		new Endpoint_StripePayment($this->plugin_name, $this->version);
  }
}
new StripePayment();