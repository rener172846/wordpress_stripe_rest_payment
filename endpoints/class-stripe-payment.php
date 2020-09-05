<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly.
}

class Endpoint_StripePayment {

  public function __construct($plugin_name, $version) {
    $this->plugin_name = $plugin_name;
    $this->version = $version;
    $this->namespace = $this->plugin_name . '/v' . intval($this->version);
    
    add_action( 'rest_api_init', array( $this, 'register_api_hooks'));
  }

  public function register_api_hooks() {
    // ephemeral key
    register_rest_route($this->namespace, '/ephemeral-keys', array(
      'methods' => 'POST',
      'callback' => array($this, 'GetEphermalKeys'),
      'args' => array(
        'user_id' => array(
          'required' => true,
          'sanitize_callback' => 'esc_sql'
        ),
        'api_version' => array(
          'required' => true,
          'sanitize_callback' => 'esc_sql'
        )
      ),
    ));
    // payment intent
    register_rest_route($this->namespace, '/payment-intent', array(
      'methods' => 'POST',
      'callback' => array($this, 'GetPaymentIntent'),
      'args' => array(
        'user_id' => array(
          'required' => true,
          'sanitize_callback' => 'esc_sql'
        ),
        'price' => array(
          'required' => true,
          'sanitize_callback' => 'esc_sql'
        ),
      ),
    ));
    // checkout
    register_rest_route($this->namespace, '/checkout', array(
      'methods' => 'POST',
      'callback' => array($this, 'Checkout'),
      'args' => array(
        'user_id' => array(
          'required' => true,
          'sanitize_callback' => 'esc_sql'
        ),
        'order_id' => array(
          'required' => true,
          'sanitize_callback' => 'esc_sql'
        ),
        'token_id' => array(
          'required' => true,
          'sanitize_callback' => 'esc_sql'
        ),
      ),
    ));
  }

  function GetEphermalKeys($request) {
    // require nextend facebook connect
    require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
    if ( !is_plugin_active( 'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php' ) ) {
      return new WP_Error( 'woocommerce-gateway-stripe-deactive', __( 'Woocommerce Gateway Stripe Deactive' ), array( 'status' => 400 ) );
    }

    /** Try to authenticate the user with the passed credentials*/
    $parameters = $request->get_params();
    $userId = $parameters["user_id"];
    $APIVersion = $parameters["api_version"];

    $user = get_user_by('id', $userId);
    if (empty($user)) {
      return new WP_Error( 'wrong-user-id', __( 'No user found with id: ' . $userId ), array( 'status' => 404 ));
    }

    $settings = maybe_unserialize(get_option('woocommerce_stripe_settings'));
    if (empty($settings) || !$settings["enabled"]) {
      return new WP_Error( 'stripe-disabled', __( 'Stripe Payment Disabled' ), array( 'status' => 400 ) );
    }

    if ($settings["testmode"] && (empty($settings['test_publishable_key']) || empty($settings['test_secret_key']))) {
      return new WP_Error( 'wrong-config', __( 'Wrong config' ), array( 'status' => 404 ));
    }
    if (!$settings["testmode"] && (empty($settings['publishable_key']) || empty($settings['secret_key']))) {
      return new WP_Error( 'wrong-config', __( 'Wrong config' ), array( 'status' => 404 ));
    }
    $publicKey = $settings["test_publishable_key"];
    $secretKey = $settings["test_secret_key"];
    if (!$settings["testmode"]) {
      $publicKey = $settings["publishable_key"];
      $secretKey = $settings["secret_key"];
    }

    \Stripe\Stripe::setApiKey($secretKey);
    $stripe = new \Stripe\StripeClient($secretKey);

    $customerId = get_user_meta($userId, 'stripe_customer_id', true);
    if ( !empty($customerId) ) {
      try {
        $customer = $stripe->customers->retrieve($customerId, []);
      } catch (Throwable $t) {
         // Executed only in PHP 7, will not match in PHP 5
        WC_Stripe_Logger::log( 'Error: ' . $t->getMessage() );
        print_r("Throwable");
      } catch ( Exception $e ) {
        // Executed only in PHP 5, will not be reached in PHP 7
        WC_Stripe_Logger::log( 'Error: ' . $e->getMessage() );
        print_r("Exception");
      }
    }

    if (empty($customer)) {
      $customer = $stripe->customers->create([
        'email' => $user->data->user_email,
        'name' => $user->data->display_name,
      ]);
      print("new customer");
      print_r($customer);
      $customerId = $customer["id"];
      update_user_meta($userId, 'stripe_customer_id', $customerId);
    }
    
    $key = \Stripe\EphemeralKey::create(
      ['customer' => $customerId],
      ['stripe_version' => $APIVersion]
    );
    return [
      'data' => $key
    ];
  }

  function GetPaymentIntent($request) {
    // require nextend facebook connect
    require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
    if ( !is_plugin_active( 'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php' ) ) {
      return new WP_Error( 'woocommerce-gateway-stripe-deactive', __( 'Woocommerce Gateway Stripe Deactive' ), array( 'status' => 400 ) );
    }

    /** Try to authenticate the user with the passed credentials*/
    $parameters = $request->get_params();
    $userId = $parameters["user_id"];
    $price = $parameters["price"];

    $user = get_user_by('id', $userId);
    if (empty($user)) {
      return new WP_Error( 'wrong-user-id', __( 'No user found with id: ' . $userId ), array( 'status' => 404 ));
    }

    $settings = maybe_unserialize(get_option('woocommerce_stripe_settings'));
    if (empty($settings) || !$settings["enabled"]) {
      return new WP_Error( 'stripe-disabled', __( 'Stripe Payment Disabled' ), array( 'status' => 400 ) );
    }

    if ($settings["testmode"] && (empty($settings['test_publishable_key']) || empty($settings['test_secret_key']))) {
      return new WP_Error( 'wrong-config', __( 'Wrong config' ), array( 'status' => 404 ));
    }
    if (!$settings["testmode"] && (empty($settings['publishable_key']) || empty($settings['secret_key']))) {
      return new WP_Error( 'wrong-config', __( 'Wrong config' ), array( 'status' => 404 ));
    }
    $publicKey = $settings["test_publishable_key"];
    $secretKey = $settings["test_secret_key"];
    if (!$settings["testmode"]) {
      $publicKey = $settings["publishable_key"];
      $secretKey = $settings["secret_key"];
    }

    \Stripe\Stripe::setApiKey($secretKey);
    $stripe = new \Stripe\StripeClient($secretKey);

    $customerId = get_user_meta($userId, 'stripe_customer_id', true);
    if ( !empty($customerId) ) {
      try {
        $customer = $stripe->customers->retrieve($customerId, []);
      } catch (Throwable $t) {
         // Executed only in PHP 7, will not match in PHP 5
        WC_Stripe_Logger::log( 'Error: ' . $t->getMessage() );
        print_r("Throwable");
      } catch ( Exception $e ) {
        // Executed only in PHP 5, will not be reached in PHP 7
        WC_Stripe_Logger::log( 'Error: ' . $e->getMessage() );
        print_r("Exception");
      }
    }

    if (empty($customer)) {
      $customer = $stripe->customers->create([
        'email' => $user->data->user_email,
        'name' => $user->data->display_name,
      ]);
      print("new customer");
      print_r($customer);
      $customerId = $customer["id"];
      update_user_meta($userId, 'stripe_customer_id', $customerId);
    }
    
    $paymentIntent = \Stripe\PaymentIntent::create([
      'amount' => $price,
      'currency' => 'usd',
      'customer' => $customerId,
    ]);
    return [
      'data' => $paymentIntent["client_secret"]
    ];
  }

  function Checkout($request) {
    // require nextend facebook connect
    require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
    if ( !is_plugin_active( 'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php' ) ) {
      return new WP_Error( 'woocommerce-gateway-stripe-deactive', __( 'Woocommerce Gateway Stripe Deactive' ), array( 'status' => 400 ) );
    }

    /** Try to authenticate the user with the passed credentials*/
    $parameters = $request->get_params();
    $userId = $parameters["user_id"];
    $orderId = $parameters["order_id"];
    $tokenId = $parameters["token_id"];

    $user = get_user_by('id', $userId);
    if (empty($user)) {
      return new WP_Error( 'wrong-user-id', __( 'No user found with id: ' . $userId ), array( 'status' => 404 ));
    }

    $order = wc_get_order($orderId);
    if ( empty( $orderId ) ) {
      return new WP_Error( 'wc-rest-payment', __( "Order ID 'order_id' is required." ), array( 'status' => 400 ) );
    } else if ( empty( $order ) ) {
      return new WP_Error( 'wc-rest-payment', __( "Order ID 'order_id' is invalid. Order does not exist." ), array( 'status' => 400 ) );
    } else if ( $order->get_status() !== 'pending' ) {
      return new WP_Error( 'wc-rest-payment', __( "Order status is NOT 'pending', meaning order had already received payment. Multiple payment to the same order is not allowed. ", 'wc-rest-payment' ), array( 'status' => 400 ) );
    }

    $price = floatval($order->total) * 100;

    $settings = maybe_unserialize(get_option('woocommerce_stripe_settings'));
    if (empty($settings) || !$settings["enabled"]) {
      return new WP_Error( 'stripe-disabled', __( 'Stripe Payment Disabled' ), array( 'status' => 400 ) );
    }

    if ($settings["testmode"] && (empty($settings['test_publishable_key']) || empty($settings['test_secret_key']))) {
      return new WP_Error( 'wrong-config', __( 'Wrong config' ), array( 'status' => 404 ));
    }
    if (!$settings["testmode"] && (empty($settings['publishable_key']) || empty($settings['secret_key']))) {
      return new WP_Error( 'wrong-config', __( 'Wrong config' ), array( 'status' => 404 ));
    }
    $publicKey = $settings["test_publishable_key"];
    $secretKey = $settings["test_secret_key"];
    if (!$settings["testmode"]) {
      $publicKey = $settings["publishable_key"];
      $secretKey = $settings["secret_key"];
    }

    \Stripe\Stripe::setApiKey($secretKey);
    $stripe = new \Stripe\StripeClient($secretKey);

    $customerId = get_user_meta($userId, 'stripe_customer_id', true);
    if ( !empty($customerId) ) {
      try {
        $customer = $stripe->customers->retrieve($customerId, []);
      } catch (Throwable $t) {
         // Executed only in PHP 7, will not match in PHP 5
        WC_Stripe_Logger::log( 'Error: ' . $t->getMessage() );
        print_r("Throwable");
      } catch ( Exception $e ) {
        // Executed only in PHP 5, will not be reached in PHP 7
        WC_Stripe_Logger::log( 'Error: ' . $e->getMessage() );
        print_r("Exception");
      }
    }

    if (empty($customer)) {
      $customer = $stripe->customers->create([
        'email' => $user->data->user_email,
        'name' => $user->data->display_name,
      ]);
      print("new customer");
      print_r($customer);
      $customerId = $customer["id"];
      update_user_meta($userId, 'stripe_customer_id', $customerId);
    }

    $order->update_meta_data( '_stripe_customer_id', $customerId );
    // $order->update_meta_data( '_stripe_intent_id', $paymentIntent->id );
    $order->save();

    $wc_gateway_stripe = new WC_Gateway_Stripe();
    $_POST['stripe_token'] = $tokenId;

    // return print_r($paymentIntent);

    $payment_result = $wc_gateway_stripe->process_payment( $orderId );
    
    $response = array();
    if ( $payment_result['result'] === "success" ) {
      $response['code']    = 200;
      $response['message'] = __( "Your Payment was Successful", "wc-rest-payment" );

      $order = wc_get_order( $order_id );

      // set order to completed
      if( $order->get_status() == 'processing' ) {
        $order->update_status( 'completed' );
      }

    } else {
      return new WP_REST_Response( array("c"), 123 );
      $response['code']    = 401;
      $response['message'] = __( "Please enter valid card details", "wc-rest-payment" );
    }

    return new WP_REST_Response( $response, 123 );
  }
  
}