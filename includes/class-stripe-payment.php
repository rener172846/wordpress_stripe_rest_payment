<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly.
}
class Includes_StripePayment {

  public function __construct() {
    $this->load_dependencies();
  }
  private function load_dependencies()
  {
    /**
     * Load dependecies managed by composer.
     */
    require_once plugin_dir_path(dirname(__FILE__)) . 'includes/vendor/autoload.php';
  }
}
new Includes_StripePayment();