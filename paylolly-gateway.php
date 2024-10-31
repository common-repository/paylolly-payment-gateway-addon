<?php
/*
 * Plugin Name: PayLolly Payment Gateway AddOn
 * Description: Take card payments on your store.
 * Author: Paylolly Ltd
 * Author URI: http://paylolly.com
 * Version: 1.0.0
 * WC requires at least: 6.0.0
 * WC tested up to: 7.7
 * Requires at least: 6.0
 * Requries PHP: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_filter("woocommerce_payment_gateways", "paylolly_add_gateway_class");
function paylolly_add_gateway_class($gateways)
{
    $gateways[] = "WC_Paylolly_Gateway"; // your class name is here
    return $gateways;
}

/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action("plugins_loaded", "paylolly_init_gateway_class");
function paylolly_init_gateway_class()
{
    class WC_Paylolly_Gateway extends WC_Payment_Gateway_CC
    {
        /**
         * Class constructor, more about it in Step 3
         */
        public function __construct()
        {
            $this->id = "paylolly"; // payment gateway plugin ID
            $this->icon = ""; // URL of the icon that will be displayed on checkout page near your gateway name
            $this->has_fields = true; // in case you need a custom credit card form
            $this->method_title = "Paylolly";
            $this->method_description = "Payments via Paylolly"; // will be displayed on the options page

            // gateways can support subscriptions, refunds, saved payment methods,
            // but in this tutorial we begin with simple payments
            $this->supports = ["products", "refunds","default_credit_card_form"];

            // Method with all the options fields
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();
            $this->title = $this->get_option("title");
            $this->description = $this->get_option("description");
            $this->enabled = $this->get_option("enabled");
            $this->testmode = "yes" === $this->get_option("testmode");
            $this->private_key = $this->testmode
                ? $this->get_option("test_private_key")
                : $this->get_option("private_key");
            $this->merchant_id = $this->testmode
                ? $this->get_option("test_merchant_id")
                : $this->get_option("merchant_id");
            $this->tokenization = "yes" === $this->get_option("tokenization");
            $this->subscriptions = "yes" === $this->get_option("subscriptions");
            if ($this->tokenization) {
                array_push($this->supports, "tokenization","credit_card_form_cvc_on_saved_method");
            }
	    if ($this->subscriptions){
		    if (!class_exists("WC_Subscriptions_manager")||!class_exists("WC_Subscriptions"))
			    $this->subscriptions=false;
	    }
	    if ($this->subscriptions){
		    array_push($this->supports, 
			    "subscriptions",
			    "subscription_cancellation",
			    "subscription_suspension",
			    "subscription_reactivation",
			    "subscription_amount_changes",
			    "subscription_date_changes",
			    //"subscription_payment_method_changes",
			    //"subscription_payment_method_change_customer",
			    //"subscription_payment_method_change_admin",
			    //"multiple_subscriptions"
			    );
		add_action('wocommerce-scheduled_subscription_payment_paylolly',array($this,'scheduled_subscription_payment'),10,2);
	    }

			if (is_admin())
			{
				// This action hook saves the settings
				add_action(
					"woocommerce_update_options_payment_gateways_" . $this->id,
					[$this, "process_admin_options"]
				);
			}
            // We need custom JavaScript to obtain a token
            add_action("wp_enqueue_scripts", [$this, "payment_scripts"]);

            // Register Webhook
            add_action("woocommerce_api_wc_gateway_paylolly_approved", [
                $this,
                "webhook",
            ]);
        }

        function get_base_url()
        {
            return $this->testmode
                ? "https://api.stg.paylolly.app"
                : "https://api.paylolly.app";
        }

        /**
         * Plugin options, we deal with it in Step 3 too
         */
        public function init_form_fields()
        {
            $this->form_fields = [
                "enabled" => [
                    "title" => "Enable/Disable",
                    "label" => "Enable Paylolly Gateway",
                    "type" => "checkbox",
                    "description" => "",
                    "default" => "no",
                ],
                "title" => [
                    "title" => "Title",
                    "type" => "text",
                    "description" =>
                        "This controls the title which the user sees during checkout.",
                    "default" => "Credit Card",
                    "desc_tip" => true,
                ],
                "description" => [
                    "title" => "Description",
                    "type" => "textarea",
                    "description" =>
                        "This controls the description which the user sees during checkout.",
                    "default" => "Pay with your credit/debit card.",
                ],
                "tokenization" => [
                    "title" => "Tokenization",
                    "label" => "Enable Tokenization",
                    "type" => "checkbox",
                    "description" =>
                        "Allw use of tokenization and saved cards.",
                    "default" => "no",
                    "desc_tip" => true,
                ],
		"subscriptions" => [
                    "title" => "Subscriptions",
                    "label" => "Enable Subscriptions",
                    "type" => "checkbox",
                    "description" =>
                        "Allw use of subscriptions.",
                    "default" => "no",
                    "desc_tip" => true,
                ],
                "testmode" => [
                    "title" => "Test mode",
                    "label" => "Enable Test Mode",
                    "type" => "checkbox",
                    "description" =>
                        "Place the payment gateway in test mode using test API keys.",
                    "default" => "yes",
                    "desc_tip" => true,
                ],
                "test_merchant_id" => [
                    "title" => "Test Merchant Id",
                    "type" => "text",
                ],
                "test_private_key" => [
                    "title" => "Test Key",
                    "type" => "password",
                ],
                "merchant_id" => [
                    "title" => "Live Merchant Id",
                    "type" => "text",
                ],
                "private_key" => [
                    "title" => "Live Key",
                    "type" => "password",
                ],
            ];
        }

        /**
         * You will need it if you want your custom credit card form, Step 4 is about it
         */
        public function payment_fields()
        {
            // ok, let's display some description before the payment form
            if ($this->description) {
                // you can instructions for test mode, I mean test card numbers etc.
                if ($this->testmode) {
                    $this->description .=
                        ' TEST MODE ENABLED. In test mode, you can use the card numbers listed in <a href="https://docs.stg.paylolly.app/Public/testcards.html">documentation</a>.';
                    $this->description = trim($this->description);
                }
                // display the description with <p> tags etc.
                echo wpautop(wp_kses_post($this->description));
            }

?>
	<input id="paylolly_token" type="hidden" name="Token">
	<input id="paylolly_cvv" type="hidden" name="CVVEncrypted">
<?php
			parent::Payment_fields();
        }

        /*
         * Custom CSS and JS, in most cases required only when you decided to go with a custom credit card form
         */
        public function payment_scripts()
        {
            // we need JavaScript to process a token only on cart/checkout pages, right?
            if (
                !is_cart() &&
                !is_checkout() &&
                !isset($_GET["pay_for_order"])
            ) {
                return;
            }

            // if our payment gateway is disabled, we do not have to enqueue JS too
            if ("no" === $this->enabled) {
                return;
            }

            // no reason to enqueue JavaScript if API keys are not set
            if (empty($this->private_key) || empty($this->merchant_id)) {
                wc_add_notice("key or merchantid missing", "error");
                return;
            }

            // do not work with card detailes without SSL unless your website is in a test mode
            if (!$this->testmode && !is_ssl()) {
                wc_add_notice("SSL is required", "error");
                return;
            }
            $url = $this->get_base_url();
            $private_key = $this->private_key;
            $response = wp_remote_get(
                $url .
                    "/api/rest/v1.0/Token/GetMerchantSessionKey?timeSpan=00:20:00",
                [
                    "method" => "GET",
                    "timeout" => 10,
                    "headers" => [
                        "MerchantKey" => $private_key,
                        "accept" => "application/json",
                    ],
                ]
            );
            if (!is_wp_error($response)) {
                $sessionKey = json_decode($response["body"], true);
            } else {
                wc_add_notice(
                    "We are currently experiencing problems trying to connect to this payment gateway. Sorry for the inconvenience.",
                    "error"
                );
                return;
            }

            // and this is our custom JS in your plugin directory that works with token.js
            wp_register_script(
                "woocommerce_paylolly",
                plugins_url("paylolly.js", __FILE__),
                ["jquery"]
            );

            // in most payment processors you have to use PUBLIC KEY to obtain a token
            wp_localize_script("woocommerce_paylolly", "paylolly_params", [
                "sessionKey" => $sessionKey,
                "url" => $url,
                "merchantId" => $this->merchant_id,
            ]);

            wp_enqueue_script("woocommerce_paylolly");
        }

        /*
         * Fields validation, more in Step 5
         */
        public function validate_fields()
        {
            return true;
        }
        /*
         * In case you need a webhook, like PayPal IPN etc
         */
        public function webhook()
        {
            $order = wc_get_order(wc_clean($_GET["reference"]));
            if ($order->get_status() == "pending") {
                $order->payment_complete();
                wc_reduce_stock_levels($order->get_id());

		if ($this->tokenization && wc_clean($_GET["new_payment_method"])=="1")
		{
			$input=file_get_contents('php://input');
			//error_log($input);
			$body=json_decode($input,true);
			//error_log(print_r($body,true));
			$this->addCardToken($body,$order->get_user_id());
		}	
		if ($this->subscriptions)
			$this->addSubscription($body,$order);
            }
            update_option("webhook_debug", $_GET);
        }
	function addSubscription($body,$order)
	{
		if (wcs_order_contains_subscription($order))
		{
			$subscriptions = wcs_get_subscriptions_for_order($order);
			foreach($subscriptions as $subscription){
				update_post_meta($subscription->id,'trace_id',$body['result']['traceId']);
				update_post_meta($subscription->id,'token',$body['cardData']['token']);
			}
		}
	}
	function addCardToken($body,$user_id)
	{
                                $token = new WC_Payment_Token_CC();
                                $token->set_token(
                                    $body["cardData"]["token"]
                                );
                                $token->set_gateway_id($this->id);
                                $token->set_card_type(
                                    $body["cardData"]["brand"]
                                );
                                $token->set_last4(
                                    substr($body["cardData"]["maskedPAN"], -4)
                                );
                                $token->set_expiry_month(
                                    substr($body["cardData"]["expiryYYMM"], -2)
                                );
                                $token->set_expiry_year(
                                    "20" .
                                        substr(
                                            $body["cardData"]["expiryYYMM"],
                                            0,
                                            2
                                        )
                                );
                                $token->set_user_id($user_id);
				//error_log(print_r($token,true));
                                $token->save();
	}
        function GUID()
        {
            if (function_exists("com_create_guid") === true) {
                return trim(com_create_guid(), "{}");
            }

            return sprintf(
                "%04X%04X-%04X-%04X-%04X-%04X%04X%04X",
                mt_rand(0, 65535),
                mt_rand(0, 65535),
                mt_rand(0, 65535),
                mt_rand(16384, 20479),
                mt_rand(32768, 49151),
                mt_rand(0, 65535),
                mt_rand(0, 65535),
                mt_rand(0, 65535)
            );
        }
        /*
         * We're processing the payments here, everything about it is in Step 5
         */
        public function process_payment($order_id)
        {
            global $woocommerce;

            // we need it to get any order detailes
            $order = wc_get_order($order_id);

            /*
             * Array with parameters for API interaction
             */
            $merchRef = $this->GUID();
            $returnURL = $this->get_return_url();
			$new_payment_method = false;
			if ($this->tokenization)
				if (isset($_POST['wc-paylolly-new-payment-method']))
					$new_payment_method = true;
            $webhook_url = add_query_arg(
                [
                    "reference" => urlencode($order_id),
                    'new_payment_method' => urlencode("".$new_payment_method)
                ],
                WC()->api_request_url("wc_gateway_paylolly_approved")
            );
            $payload = [
                "merchantId" => $this->merchant_id,
                "currency" => $order->get_currency(),
                "amount" => $order->get_total(),
                "merchantRef" => $merchRef,
                "cardHolder"=>[
					"name"=>$order->get_billing_first_name()." ".$order->get_billing_last_name(),
					"email"=>$order->get_billing_email(),
					"address"=>[
						"address"=>$order->get_billing_address_1(),
						"city"=>$order->get_billing_city(),
						"state"=>$order->get_billing_state(),
						"zip"=>$order->get_billing_postcode(),
						"country"=>$order->get_billing_country()
					]
				],
                "cardData" => [
                    "cvcEncrypted" => sanitize_text_field($_POST["CVVEncrypted"]),
                ],
                "tokenData" => [
                    "token" => sanitize_key($_POST["Token"]),
                ],
                "authorisationType" => "AuthCapture",
                "recurrence" => "OneTime",
                "initiation" => "CIT",
                "returnUrl" => $returnURL,
                //"validUntil"=>array(),
                "webHooks" => ["approved" => $webhook_url],
            ];
			$datetime=new DateTime();
			$datetime->add(new DateInterval('PT' . 20 . 'M'));
			$payload["validUntil"]=$datetime->format(DateTime::ATOM);
			if ($order->get_customer_id()!="0")
			{
				$payload["cardHolder"]["reference"]="".$order->get_customer_id();
			}
            if ($this->tokenization) {
		    //error_log(print_r($_POST,true));
                if (
                    isset($_POST["wc-paylolly-payment-token"]) &&
                    "new" !== wc_clean($_POST["wc-paylolly-payment-token"])
                ) {
                    $token_id = wc_clean($_POST["wc-paylolly-payment-token"]);
                    $token = WC_Payment_Tokens::get($token_id);
                    // Token user ID does not match the current user... bail out of payment processing.
                    if ($token->get_user_id() !== get_current_user_id()) {
                        // Optionally display a notice with `wc_add_notice`
                        return;
                    }

                    $payload["tokenData"]['token']=$token->get_token();
                }
            }
            $args = [
                "method" => "POST",
                "timeout" => 30,
                "headers" => [
                    "MerchantKey" => $this->private_key,
                    "accept" => "application/json",
                    "content-type" => "application/json",
                ],
                "body" => json_encode($payload),
            ];
            $response = wp_remote_post(
                $this->get_base_url() .
                    "/api/rest/v1.0/Payment/CreateAsyncPayment",
                $args
            );

            if (!is_wp_error($response)) {
                $body = json_decode($response["body"], true);
                //wc_add_notice(json_encode($payload),'error');
                //wc_add_notice(json_encode($body), "error");
                $status = null;
                if (
                    array_key_exists("result", $body) &&
                    array_key_exists("status", $body["result"])
                ) {
                    $status = $body["result"]["status"];
                }
                if (array_key_exists("paymentId", $body)) {
                    update_post_meta(
                        $order_id,
                        "payment_id",
                        $body["paymentId"]
                    );
                }
                switch ($status) {
                    case "Async":
                        return [
                            "result" => "success",
                            "redirect" => $body["redirectUrl"],
                        ];
                    case "Approved":
                        // we received the payment
                        $order->payment_complete();
                        wc_reduce_stock_levels($order->get_id());

                        // some notes to customer (replace true with false to make it private)
                        $order->add_order_note(
                            "Hey, your order is paid! Thank you!",
                            true
                        );

                        // Empty cart
                        wc_empty_cart();

                        // Token save
                        if ($this->tokenization) {
                            if ( isset($_POST[ "wc-paylolly-new-payment-method" ]))
				$this->addCardToken($body,$order->get_user_id());
                        }
			if ($this->subscriptions)
				$this->addSubscription($body,$order);

                        // Redirect to the thank you page
                        return [
                            "result" => "success",
                            "redirect" => $this->get_return_url($order),
                        ];
                    default:
                        wc_add_notice(
                            "Please try again. - " . $status,
                            "error"
                        );
                        return;
                }
            } else {
                wc_add_notice("Connection error.", "error");
                return;
            }
        }
        public function process_refund($order_id, $amount = null, $reason = "")
        {
            $payment_id = get_post_meta($order_id, "payment_id", true);
            $customer_order = new WC_Order($order_id);

            if ($amount === null || $amount == 0 || $amount === "") {
                return new WP_Error(
                    "paylolly-woocommerce-gateway_refund_error",
                    "Amount is NOT optional."
                );
            }
            $payment_id = get_post_meta($order_id, "payment_id", true);
            $payload = [
                "merchantId" => $this->merchant_id,
                "amount" => $amount,
                "paymentId" => $payment_id,
            ];
            $args = [
                "method" => "POST",
                "timeout" => 30,
                "headers" => [
                    "MerchantKey" => $this->private_key,
                    "accept" => "application/json",
                    "content-type" => "application/json",
                ],
                "body" => json_encode($payload),
            ];

            $response = wp_remote_post(
                $this->get_base_url() . "/api/rest/v1.0/Payment/RefundPayment",
                $args
            );
            if (!is_wp_error($response)) {
                $body = json_decode($response["body"], true);
                //wc_add_notice(json_encode($payload),'error');
                //wc_add_notice(json_encode($body),'error');

                $customer_order->add_order_note(
                    __(
                        "Refund of " .
                            $customer_order->get_currency() .
                            " " .
                            $amount .
                            " Successful ",
                        "transactium-wc-addon"
                    )
                );
                return true;
            } else {
                return new WP_Error(
                    "paylolly-woocommerce-gateway_refund_error",
                    "Refund Error."
                );
            }
        }
	public function scheduled_subscription_payment($amount_to_charge, $customer_order)
	{
		if (!class_exists('WC_Subscriptions') || !class_exists('WC_Subscriptions_Manager'))
		{
			return new WP_Error('no_woo','Subscriptions plugin not active');
		}
		$subscriptions=wcs_get_subscriptions_for_order($customer_order);
		if (is_array($subscriptions))
			$subscription=$subscriptions[0];
		else
			$subscription=$subscriptions;
	$token=get_post_meta($subscription->id,'token',true);
	$trace_id=get_post_meta($subscription->id,'trace_id',true);
	 $payment_id = get_post_meta($order_id, "payment_id", true);
            $payload = [
                "merchantId" => $this->merchant_id,
                "amount" => $amount_to_charge,
		"currency" => $customer_order->get_currency(),
		"merchantRef" =>$this->GUID(),
		"tokenData"=>[
			"token"=>$token,
			"traceId"=>$trace_id,
			],
		"authorisationType"=>"authCapture",
		"recurrence"=>"Recurring",
		"initiation"=>"MIT",
            ];
            $args = [
                "method" => "POST",
                "timeout" => 30,
                "headers" => [
                    "MerchantKey" => $this->private_key,
                    "accept" => "application/json",
                    "content-type" => "application/json",
                ],
                "body" => json_encode($payload),
            ];

            $response = wp_remote_post(
                $this->get_base_url() . "/api/rest/v1.0/Payment/CreateSyncPayment",
                $args
            );
            if (!is_wp_error($response)) {
                $body = json_decode($response["body"], true);
                //wc_add_notice(json_encode($payload),'error');
                //wc_add_notice(json_encode($body),'error');

		if ($body['result']['status']=="Approved")
		{
			WC_Subscriptions_Manager::process_subscription_payments_on_order($customer_order);
			$customer_order->add_order_note(sprintf("payment_id %s Auth code %s",$body['paymentId'],$body['result']['authCode']));
			return;
		}
		WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($customer_order);

		$customer_order->add_order_note(sprintf("payment_id %s failed %s",$body['paymentId'],$body['result']['message']));
                return;
            } else {
		WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($customer_order);
                return new WP_Error(
                    "paylolly-woocommerce-gateway_subscription_error",
                    "Subscription Error."
                );
            }	
	}
    }
}
