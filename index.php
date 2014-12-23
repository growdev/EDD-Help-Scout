<?php
/**
 * HelpScout EDD integration.
 *
 * This code is based in large part on an example provided by HelpScout and then modified for Easy Digital Downloads and WP.
 * 
 * Based off of Yoast's original integration.
 */

// We use core, so we include it.
require '../wp-load.php';

// Require the settings file for the secret key
require './settings.php';

class EDD_Help_Scout {
	private $input = false;

	/**
	 * Returns the requested HTTP header.
	 *
	 * @param string $header
	 * @return bool|string
	 */
	private function getHeader( $header ) {
		if ( isset( $_SERVER[$header] ) ) {
			return $_SERVER[$header];
		}
		return false;
	}

	/**
	 * Retrieve the JSON input
	 *
	 * @return bool|string
	 */
	private function getJsonString() {
		if ( $this->input === false ) {
			$this->input = @file_get_contents( 'php://input' );
		}
		return $this->input;
	}

	/**
	 * Generate the signature based on the secret key, to compare in isSignatureValid
	 *
	 * @return bool|string
	 */
	private function generateSignature() {
		$str = $this->getJsonString();
		if ( $str ) {
			return base64_encode( hash_hmac( 'sha1', $str, HELPSCOUT_SECRET_KEY, true ) );
		}
		return false;
	}

	/**
	 * Returns true if the current request is a valid webhook issued from Help Scout, false otherwise.
	 *
	 * @return boolean
	 */
	private function isSignatureValid() {
		$signature = $this->generateSignature();

		if ( !$signature || !$this->getHeader( 'HTTP_X_HELPSCOUT_SIGNATURE' ) )
			return false;

		return $signature == $this->getHeader( 'HTTP_X_HELPSCOUT_SIGNATURE' );
	}

	/**
	 * Create a response.
	 *
	 * @return array
	 */
	public function getResponse() {
		$ret = array( 'html' => '' );

		if ( !$this->isSignatureValid() ) {
			return array( 'html' => 'Invalid signature' );
		}
		$data = json_decode( $this->input, true );

		// do some stuff
		$ret['html'] = $this->fetchHtml( $data );

		// Used for debugging
		// $ret['html'] = '<pre>'.print_r($data,1).'</pre>' . $ret['html'];

		return $ret;
	}

	/**
	 * Generate output for the response.
	 *
	 * @param $data
	 * @return string
	 */
	private function fetchHtml( $data ) {
		global $wpdb;

		/* Ignore own email address */
		if ( isset( $data['customer']['emails'] ) && is_array( $data['customer']['emails'] ) ) {

			if(($key = array_search(HELPSCOUT_EMAIL, $messages)) !== false) {
			    unset($data['customer']['emails'][$key]);
			}

		} else {

			if ( $data['customer']['email'] == HELPSCOUT_EMAIL ) {
				return 'Cannot query customer licenses.  E-mail from ' . HELPSCOUT_EMAIL;
			}

		}

		if ( isset( $data['customer']['emails'] ) && is_array( $data['customer']['emails'] ) ) {
			$email_query = "IN (";
			foreach ( $data['customer']['emails'] as $email ) {
				$email_query .= "'" . $email . "',";
			}
			$email_query = rtrim( $email_query, ',' );
			$email_query .= ')';
		} else {
			$email_query = "= '" . $data['customer']['email'] . "'";
		}

		$query   = "SELECT pm2.post_id, pm2.meta_value, p.post_status FROM $wpdb->postmeta pm, $wpdb->postmeta pm2, $wpdb->posts p WHERE pm.meta_key = '_edd_payment_user_email' AND pm.meta_value $email_query AND pm.post_id = pm2.post_id AND pm2.meta_key = '_edd_payment_meta' AND pm.post_id = p.ID AND p.post_status NOT IN ('failed','pending') ORDER BY pm.post_id DESC LIMIT 20";
		$results = $wpdb->get_results( $query );

		if ( !$results ) {
			$fuzzy_results = true;
			$query   = "SELECT pm.post_id, pm.meta_value, p.post_status FROM $wpdb->postmeta pm, $wpdb->posts p WHERE pm.meta_key = '_edd_payment_meta' AND pm.meta_value LIKE '%%" . $data['customer']['fname'] . "%%' AND pm.meta_value LIKE '%%" . $data['customer']['lname'] . "%%' AND pm.post_id = p.ID AND p.post_status NOT IN ('failed','pending') ORDER BY pm.post_id DESC LIMIT 20";
			$results = $wpdb->get_results( $query );
		}

		if ( !$results ) {
			return 'No license data found.';
		}

		$orders = array();
		foreach ( $results as $result ) {
			$order         = array();
			$order['link'] = '<a target="_blank" href="' . get_admin_url( null, 'edit.php?post_type=download&page=edd-payment-history&view=view-order-details&id=' . $result->post_id ) . '">#' . $result->post_id . '</a>';

			$post = get_post( $result->post_id );

			$purchase = maybe_unserialize( $result->meta_value );
			$user_info = maybe_unserialize( $purchase['user_info'] );

			$order['date'] = date_i18n( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), strtotime( $post->post_date ) );
			unset( $post );

			$order['id']             = $result->post_id;
			$order['status']         = $result->post_status;
			$order['amount']         = edd_get_payment_amount( $result->post_id );
			$order['payment_method'] = edd_get_payment_gateway( $result->post_id );
			$order['email']          = $user_info['email'];
			$order['name']           = $user_info['first_name'] . ' ' . $user_info['last_name'];

			if ( 'paypal' == $order['payment_method'] ) {
				// Grab the PayPal transaction ID and link the transaction to PayPal
				$notes = edd_get_payment_notes( $result->post_id );
				foreach ( $notes as $note ) {
					if ( preg_match( '/^PayPal Transaction ID: ([^\s]+)/', $note->comment_content, $match ) )
						$order['paypal_transaction_id'] = $match[1];
				}

				$order['payment_method'] = '<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_view-a-trans&id=' . esc_url( $order['paypal_transaction_id'] ) . '" target="_blank">PayPal</a>';
			} else if ( 'stripe' == $order['payment_method'] ) {
				// Grab the PayPal transaction ID and link the transaction to PayPal
				$notes = edd_get_payment_notes( $result->post_id );
				foreach ( $notes as $note ) {
					if ( preg_match( '/^Stripe Charge ID: ([^\s]+)/', $note->comment_content, $match ) )
						$order['stripe_charge_id'] = $match[1];
				}

				$order['payment_method'] = '<a href="https:/stripe.com/payments/' . esc_url( $order['stripe_charge_id'] ) . '" target="_blank">Stripe</a>';
			}

			$downloads = edd_get_payment_meta_downloads( $result->post_id );

			if ( $downloads ) {
				foreach ( $downloads as $download ) {

					$id = isset( $purchase['cart_details'] ) ? $download['id'] : $download;

					$licensing = new EDD_Software_Licensing();

					if ( get_post_meta( $id, '_edd_sl_enabled', true ) ) {

						$license = $licensing->get_license_by_purchase( $order['id'], $id );
						$order['downloads'][] = '<strong>' . get_the_title( $id ) . "</strong><br/>"
							. edd_get_price_option_name( $id, $download['options']['price_id'] ) . '<br/>'
							. get_post_meta( $license->ID, '_edd_sl_key', true ) . '<br/><br/>';

					} else {

						$order['downloads'][] = '<strong>' . get_the_title( $id ) . "</strong><br/>";

					}
				}
			}

			$orders[] = $order;
		}

		$output = '';
		if ($fuzzy_results === true) {
			$output .= '<p>Matches based on customer name:</p>';
		}
		foreach ( $orders as $order ) {
			$output .= '<strong><i class="icon-cart"></i> ' . $order['link'] . '</strong>';
			if ( $order['status'] != 'publish' )
				$output .= ' - <span style="color:orange;font-weight:bold;">' . $order['status'] . '</span>';
			$output .= '<p><span class="muted">' . $order['date'] . '</span><br/>';
			if ($fuzzy_results === true) {
				$output .= $order['name'] . '<br/>' . $order['email'] . '<p>';
			}
			$output .= '$' . $order['amount'] . ' - ' . $order['payment_method'] . '</p>';
			$output .= '<p><i class="icon-pointer"></i><a target="_blank" href="' . add_query_arg( array( 'edd-action' => 'email_links', 'purchase_id' => $order['id'] ), admin_url( 'edit.php?post_type=download&page=edd-payment-history' ) ) . '">' . __( 'Resend Purchase Receipt', 'edd' ) . '</a></p>';
			$output .= '<ul>';
			foreach ( $order['downloads'] as $download ) {
				$output .= '<li>' . $download . '</li>';
			}
			$output .= '</ul>';
		}

		return $output;
	}
}

$eddhelpscout = new EDD_Help_Scout();

echo json_encode( $eddhelpscout->getResponse() );
