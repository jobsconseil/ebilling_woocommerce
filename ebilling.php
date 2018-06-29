<?php
/*
  Plugin Name: E-Billing Moyen de Paiement - WooCommerce
  Plugin URI: http://jobs-conseil.com/eBillingPaymentApi/
  Description: Intégration facile de la solution Ebilling dans WooCommerce pour le paiement par mobile payment au Gabon.
  Version: 1.0
  Author: Mebodo Aristide Richard
  Author URI: https://www.facebook.com/aristide.mebodo
 */	
if (!defined('ABSPATH')) {
    exit;
}
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    exit;
}

add_action('plugins_loaded', 'woocommerce_ebilling_init', 0);

function sp_custom_notice(){
	$message = '';
	if(isset($_GET['erreur']) && isset($_GET['msg'])){
		$_GET['erreur'] = (int)$_GET['erreur'];
		if($_GET['erreur'] == 422){
			$message = "Le numéro de téléphone est incorrect (Exemple : 01000000). L'opération ne peut être exécutée.";	
		}else{
			$message = "L'opération ne peut être exécutée.";
		}
		$_SERVER['REQUEST_URI'] = '/commande/';
		$_SERVER['QUERY_STRING'] = '';
		$_SERVER['REDIRECT_QUERY_STRING'] = '';
		unset($_GET['erreur']);
		wc_add_notice($message, 'error');
	}
}

add_action( 'wp', 'sp_custom_notice' );

function woocommerce_ebilling_init() {	
    if (!class_exists('WC_Payment_Gateway'))
        return;
	
	//verification de l'existance d'une notification
	if(isset($_GET['notify_ebilling']) && $_GET['notify_ebilling']==1){		
		global $woocommerce;
		$wc_order_id = (int)$_POST['reference'];
		$order = new WC_Order($wc_order_id);
		if (isset($order->id)) {
			if ($_POST['amount'] == $order->order_total) {
				global $wpdb;
				$wpdb->update($wpdb->prefix."paiement", 
							array(
								'paymentsystem' => $_POST['paymentsystem'],
								'transactionid' => $_POST['transactionid'],
								'billingid' => $_POST['billingid'],
								'amount' => $_POST['amount'],
								'etat' => 'Complété',
							),
							array(
								'external_reference' => $_POST['reference']
							)
						);
				http_response_code(200);
				echo http_response_code();		
			} else {
				http_response_code(400);
				echo http_response_code();
			}
		} else {
			http_response_code(400);
			echo http_response_code();
		}
		exit;
	}

	class WC_EBilling extends WC_Payment_Gateway {

        public function __construct() {
            $this->ebilling_errors = new WP_Error();

            $this->id = 'ebilling';
            $this->medthod_title = 'E-Billing Paiement';
            $this->icon = apply_filters('woocommerce_ebilling_icon', plugins_url('assets/images/airtel-moov-acheter.png', __FILE__));
            $this->has_fields = false;

            $this->init_form_fields();
            $this->init_settings();
            $this->ebilling_woocommerce_db();
			
			$this->title = $this->settings['title'];
            $this->description = $this->settings['description'];

            $this->user_name = $this->settings['user_name'];
            $this->shared_key = $this->settings['shared_key'];
			
            //$this->sandbox = $this->settings['sandbox'];

            $this->msg['message'] = "";
            $this->msg['class'] = "";
			

            if (isset($_REQUEST["ebilling"])) {
				wc_add_notice($_REQUEST["ebilling"], "error");
            }
			
			//callback_url de ebilling après le success du paiement
			if (isset($_GET["success_eb_paiment"]) && $_GET["success_eb_paiment"] != '') {
				$_GET['success_eb_paiment'] = (int)$_GET['success_eb_paiment'];
				$this->callback_ebilling_response($_GET['success_eb_paiment']);
            }
			
            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
            } else {
                add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
            }
        }

        function ebilling_woocommerce_db() {
			// Create DB Here
			global $wpdb;
			$charset_collate = $wpdb->get_charset_collate();
			$table_name = $wpdb->prefix.'paiement';
			$sql = "CREATE TABLE $table_name (
				  `id` int(11) NOT NULL AUTO_INCREMENT,
				  `email` varchar(255) NOT NULL,
				  `phone` varchar(255) NOT NULL,
				  `amount_order` float NOT NULL,
				  `description` text  NOT NULL,
				  `date` datetime NOT NULL,
				  `external_reference` varchar(255) NOT NULL,
				  `first_name` varchar(255) DEFAULT NULL,
				  `last_name` varchar(255) NOT NULL,
				  `address` varchar(255) NOT NULL,
				  `city` varchar(255) NOT NULL,
				  `paymentsystem` varchar(255) DEFAULT NULL,
				  `amount` float DEFAULT NULL,
				  `etat` varchar(255) NOT NULL,
				  `billingid` varchar(255) DEFAULT NULL,
				  `transactionid` varchar(255) DEFAULT NULL,
				  PRIMARY KEY (id)
			) $charset_collate;";

			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
			dbDelta( $sql );
		}

        function init_form_fields() { 
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Activé/Désactivé', 'ebilling'),
                    'type' => 'checkbox',
                    'label' => __('Activé E-Billing Payment Module.', 'ebilling'),
                    'default' => 'no'),
				'title' => array(
                    'title' => __('Title:', 'ebilling'),
                    'type' => 'text',
                    'description' => __('C\'est le titre que le titre que les clients verront lorsqu\'ils effectueront leurs achats.', 'ebilling'),
                    'default' => __('E-Billing Payment', 'ebilling')),
                'description' => array(
                    'title' => __('Description:', 'ebilling'),
                    'type' => 'textarea',
                    'description' => __('C\'est la description que le titre que les clients verront lorsqu\'ils effectueront leurs achats.', 'ebilling'),
                    'default' => __('Moyen de paiement en ligne par mobile banking.', 'ebilling')),
                'user_name' => array(
                    'title' => __('Nom utilisateur :', 'ebilling'),
                    'type' => 'text',
                    'description' => __('Nom utilisateur du compte EBilling.', 'ebilling')),
                'shared_key' => array(
                    'title' => __('Shared Key :', 'ebilling'),
                    'type' => 'text',
                    'description' => __('clé d\'identification du compte pour le paiement.')),
            );
        }

        public function admin_options() {
            echo '<h3>' . __('Moyen de Paiment EBilling', 'ebilling') . '</h3>';
            echo '<p>' . __('Ebilling meilleur moyen de paiement en ligne par mobile banking') . '</p>';
            echo '<table class="form-table">';
            // Generate the HTML For the settings form.
            $this->generate_settings_html();
            echo '</table>';
            wp_enqueue_script('expresspay_admin_option_js', plugin_dir_url(__FILE__) . 'assets/js/settings.js', array('jquery'), '1.0.1');
        }

        function payment_fields() {
            if ($this->description)
                echo wpautop(wptexturize($this->description));
        }

        protected function get_ebilling_args($order) {
            global $woocommerce;

            //$order = new WC_Order($order_id);
            $txnid = $order->id . '_' . date("ymds");

            $redirect_url = $woocommerce->cart->get_checkout_url();

            $productinfo = "Order: " . $order->id;

            $str = "$this->merchant_id|$txnid|$order->order_total|$productinfo|$order->billing_first_name|$order->billing_email|||||||||||$this->salt";
            $hash = hash('sha512', $str);

            WC()->session->set('ebilling_wc_hash_key', $hash);

            $items = $woocommerce->cart->get_cart();
            $ebilling_items = array();
            foreach ($items as $item) {
                $ebilling_items[] = array(
                    "name" => $item["data"]->post->post_title,
                    "quantity" => $item["quantity"],
                    "unit_price" => $item["line_total"] / (($item["quantity"] == 0) ? 1 : $item["quantity"]),
                    "total_price" => $item["line_total"],
                    "description" => ""
                );
            }
            $ebilling_args = array(
                "invoice" => array(
                    "total_amount" => $order->order_total,
                    "description" => "La valeur de la commande est de " . $order->order_total . " Franc CFA.",
                ),
                "store" => array(
                    "name" => get_bloginfo("name"),
                    "website_url" => get_site_url()
                ), 
                "actions" => array(
                    "cancel_url" => $redirect_url,
                    "return_url" => $redirect_url
                ), 
                "custom_data" => array(
                    "order_id" => $order->id,
                    "trans_id" => $txnid,
                    "hash" => $hash
                )
            );


            apply_filters('woocommerce_ebilling_args', $ebilling_args, $order);
            return $ebilling_args;
        }

        function post_to_url($data, $order) {
			global $wpdb;
			global $woocommerce;
			
			// Fetch all data (including those not optional) from session
            $eb_amount = $order->order_total;
            $eb_reference = $order->id;
			$eb_shortdescription = 'Règlement de la commande '.$eb_reference.' via eBilling Payment dans la boutique '.get_bloginfo("name").'.';
            $eb_email = $order->billing_email;
            $eb_msisdn = $order->billing_phone;
            $eb_name = $order->billing_first_name;
            $eb_address = $order->billing_address_1;
            $eb_city = $order->billing_city;
            $eb_detaileddescription = $data['invoice']['description'];
            $eb_additionalinfo = "Paiement effectué via eBilling";
            $eb_callbackurl = $data['store']['website_url'].'/commande/?success_eb_paiment='.$eb_reference;
			$date = date('Y-m-d H:m:s');
			
			//enregistrement dans la base de données
			$wpdb->insert($wpdb->prefix."paiement", array(
				'email' => $eb_email,
				'phone' => $eb_msisdn,
				'amount_order' => $eb_amount,
				'description' => $eb_shortdescription,
				'date' => $date,
				'external_reference' => $eb_reference,
				'first_name' => $eb_name,
				'last_name' => $order->billing_last_name,
				'address' => $eb_address,
				'city' => $eb_city,
				'etat' => "En Cours",
			));
			
			$SERVER_URL = "http://lab.billing-easy.net/api/v1/merchant/e_bills";
			/*$SERVER_URL = "https://www.billing-easy.com/api/v1/merchant/e_bills";*/

			// Username
			$USER_NAME = $this->user_name; //'aristide';

			// SharedKey
			$SHARED_KEY =  $this->shared_key; //'a4e80739-61ea-430e-8ddc-db9eb7bf0783'; 


			$global_array =
			[
				'payer_email' => $eb_email,
				'payer_msisdn' => $eb_msisdn,
				'amount' => $eb_amount,
				'short_description' => $eb_shortdescription,
				'description' => $eb_detaileddescription,
				'due_date' => date('d/m/Y', time() + 86400),
				'external_reference' => $eb_reference,
				'payer_name' => $eb_name,
				'payer_address' => $eb_address,
				'payer_city' => $eb_city,
				'additional_info' => $eb_additionalinfo
			];
			$content = json_encode($global_array);
			$curl = curl_init($SERVER_URL);
			curl_setopt($curl, CURLOPT_USERPWD, $USER_NAME . ":" . $SHARED_KEY);
			curl_setopt($curl, CURLOPT_HEADER, false);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-type: application/json"));
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $content);
			$json_response = curl_exec($curl);

			$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

			if ( $status != 201 ) {
				//die("Error: call to URL  failed with status $status, response $json_response, curl_error " . curl_error($curl) . ", curl_errno " . curl_errno($curl));
				$response = json_decode($json_response, true);
				$redirect_url = get_site_url().'/commande/?erreur='.$status.'&msg='.$response['message'];
				return $redirect_url;
					//return $redirect_url;
			}
				
			curl_close($curl);

			$response = json_decode($json_response, true);				
				

			$url = get_site_url()."/wp-content/plugins/ebilling_woocommerce/post_ebilling.php?invoice_number=".$response['e_bill']['bill_id']."&eb_callbackurl=".$eb_callbackurl;
			
			return $url;            
        }

        function process_payment($order_id) {
            $order = new WC_Order($order_id);
            return array(
                'result' => 'success',
                'redirect' => $this->post_to_url($this->get_ebilling_args($order), $order)
            );
        }

        function showMessage($content) {
            return '<div class="box ' . $this->msg['class'] . '-box">' . $this->msg['message'] . '</div>' . $content;
        }

        /*
         *permet de valider une commande grâce au callback_url
         * @param int $params
         */
		function callback_ebilling_response($params) {
            global $woocommerce;
			$wc_order_id = WC()->session->get('order_awaiting_payment');
			$order = new WC_Order($wc_order_id);
			if ($params != 0) {
                //commande annulé
				$order_id = $params;
				if ($wc_order_id <> $order_id) {
					$message = "Merci de faire vos achats avec nous. 
						Le délai de transaction est dépassé. 
						Le N° de votre commande est $order_id";
					wc_add_notice( $message , 'notice' );
					$redirect_url = $order->get_cancel_order_url();						
				} else {
					//paiement complètement effectué
					$message = "Merci de faire vos achats avec nous. 
						Votre transaction s'est bien effectuée, le paiement a été reçu. 
						Le N° de votre commande est $order_id";
					$order->payment_complete();
					$order->update_status('completed');
					wc_add_notice( $message, 'success' );
					$woocommerce->cart->empty_cart();
					$redirect_url = $this->get_return_url($order);
					$customer = trim($order->billing_last_name . " " . $order->billing_first_name);
				}
			} else {
				//paiement introuvable
				$message = "Merci de faire vos achats avec nous. La transaction a été déclinée.";
				$message_type = "error";
				wc_add_notice( $message, ‘error’ );
				$redirect_url = $order->get_cancel_order_url();
			}

			$notification_message = array(
				'message' => $message,
				'message_type' => $message_type
			);
			if (version_compare(WOOCOMMERCE_VERSION, "2.2") >= 0) {
				add_post_meta($wc_order_id, '_ebilling_hash', $hash, true);
			}
			
			wp_redirect($redirect_url);
			exit;
        }

        /*
         * fonction permettant d'annuler une commande
         */
		function cancel(){
			global $woocommerce;
			$wc_order_id = WC()->session->get('ebilling_wc_oder_id');
			$order = new WC_Order($wc_order_id);
			$redirect_url = $order->get_cancel_order_url();
			wp_redirect($redirect_url);
			exit;
		}

        static function add_ebilling_gab_currency($currencies) {
            $currencies['GAB'] = __('Franc CFA', 'woocommerce');
            return $currencies;
        }

        static function add_ebilling_gab_currency_symbol($currency_symbol, $currency) {
            switch (
            $currency) {
                case 'GAB': $currency_symbol = 'GAB ';
                    break;
            }
            return $currency_symbol;
        }

        static function woocommerce_add_ebilling_gateway($methods) {
            $methods[] = 'WC_EBilling';
            return $methods;
        }

        // Ajout du lien de paramétrage à la page des plugins
        static function woocommerce_add_ebilling_settings_link($links) {
            $settings_link = '<a href="admin.php?page=wc-settings&tab=checkout&section=wc_ebilling">Paramètres</a>';
            array_unshift($links, $settings_link);
            return $links;
        }

    }

    $plugin = plugin_basename(__FILE__);

    add_filter('woocommerce_currencies', array('WC_EBilling', 'add_ebilling_gab_currency'));
    add_filter('woocommerce_currency_symbol', array('WC_EBilling', 'add_ebilling_gab_currency_symbol'), 10, 2);

    add_filter("plugin_action_links_$plugin", array('WC_EBilling', 'woocommerce_add_ebilling_settings_link'));
    add_filter('woocommerce_payment_gateways', array('WC_EBilling', 'woocommerce_add_ebilling_gateway'));

    function addDonateLink( $links, $file ) {
    	$plugin = plugin_basename(__FILE__);
		if ( $file === $plugin ) {
			$donate  = '<strong><a href="http://ebillingpaymentpai.jobs-conseil.com">' .
			           '<span class="dashicons dashicons-heart"></span> ' .
			           __( 'Donate', 'document-gallery' ) . '</a></strong>';
			$links[] = $donate;
		}

		return $links;
	}
    add_filter( 'plugin_row_meta', 'addDonateLink', 10, 2);
}
