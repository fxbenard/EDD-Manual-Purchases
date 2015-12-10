<?php
/*
Plugin Name: Easy Digital Downloads - Manual Purchases
Plugin URI: http://easydigitaldownloads.com/extension/manual-purchases/
Description: Provides an admin interface for manually creating purchase orders in Easy Digital Downloads
Version: 1.9.3
Author: Pippin Williamson
Author URI:  http://pippinsplugins.com
Contributors: mordauk
Text Domain: edd-manual-purchases
Domain Path: languages
*/

class EDD_Manual_Purchases {

	private static $instance;

	/**
	 * Get active object instance
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @static
	 * @return object
	 */
	public static function get_instance() {

		if ( ! self::$instance )
			self::$instance = new EDD_Manual_Purchases();

		return self::$instance;
	}

	/**
	 * Class constructor.  Includes constants, includes and init method.
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {

		define( 'EDD_MP_STORE_API_URL', 'http://easydigitaldownloads.com' );
		define( 'EDD_MP_PRODUCT_NAME', 'Manual Purchases' );
		define( 'EDD_MP_VERSION', '1.9.3' );
		$this->init();

	}


	/**
	 * Run action and filter hooks.
	 *
	 * @since 1.0
	 *
	 * @access private
	 * @return void
	 */
	private function init() {

		if( ! function_exists( 'edd_price' ) )
			return; // EDD not present

		global $edd_options;

		// internationalization
		add_action( 'init', array( $this, 'textdomain' ) );

		// add a crreate payment button to the top of the Payments History page
		add_action( 'edd_payments_page_top' , array( $this, 'create_payment_button' ) );

		// register the Create Payment submenu
		add_action( 'admin_menu', array( $this, 'submenu' ) );

		// load scripts
		add_action( 'admin_enqueue_scripts', array( $this, 'load_scripts' ) );

		// check for download price variations via ajax
		add_action( 'wp_ajax_edd_mp_check_for_variations', array( $this, 'check_for_variations' ) );

		// process payment creation
		add_action( 'edd_create_payment', array( $this, 'create_payment' ) );

		// show payment created notice
		add_action( 'admin_notices', array( $this, 'payment_created_notice' ), 1 );

		// auto updater
		if( class_exists( 'EDD_License' ) ) {
			$eddc_license = new EDD_License( __FILE__, EDD_MP_PRODUCT_NAME, EDD_MP_VERSION, 'Pippin Williamson' );
		}

	}

	public static function textdomain() {

		// Set filter for plugin's languages directory
		$lang_dir = dirname( plugin_basename( __FILE__ ) ) . '/languages/';
		$lang_dir = apply_filters( 'edd_manual_purchases_lang_directory', $lang_dir );

		// Load the translations
		load_plugin_textdomain( 'edd-manual-purchases', false, $lang_dir );

	}

	public static function create_payment_button() {

		?>
		<p id="edd_create_payment_go">
			<a href="<?php echo esc_url( add_query_arg( 'page', 'edd-manual-purchase', admin_url( 'options.php' ) ) ); ?>" class="button-secondary"><?php _e('Create Payment', 'edd-manual-purchases'); ?></a>
		</p>
		<?php
	}

	public static function submenu() {
		global $edd_create_payment_page;
		$edd_create_payment_page = add_submenu_page( 'options.php', __('Create Payment', 'edd-manual-purchases'), __('Create Payment', 'edd-manual-purchases'), 'edit_shop_payments', 'edd-manual-purchase', array( __CLASS__, 'payment_creation_form' ) );
	}

	public static function load_scripts( $hook ) {

		if( 'admin_page_edd-manual-purchase' != $hook )
			return;

		// Use minified libraries if SCRIPT_DEBUG is turned off
		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		wp_enqueue_script( 'jquery-ui-datepicker' );
		$ui_style = ( 'classic' == get_user_option( 'admin_color' ) ) ? 'classic' : 'fresh';
		wp_enqueue_style( 'jquery-ui-css', EDD_PLUGIN_URL . 'assets/css/jquery-ui-' . $ui_style . $suffix . '.css' );
	}

	public static function payment_creation_form() {
		?>
		<div class="wrap">
			<h2><?php _e('Create New Payment', 'edd-manual-purchases'); ?></h2>
			<script type="text/javascript">
				jQuery(document).ready(function($) {
					// clone a download row
					$('#edd_mp_create_payment').on('click', '.edd-mp-add-download', function() {
						var row = $(this).closest('tr').clone();

						var count = $('tr.edd-mp-download-wrap').size();

						$('select.edd-mp-download-select', row).prop('name', 'downloads[' + count + '][id]');

						$('select.edd-mp-price-select', row).remove();

						if( ! $('.edd-mp-remove', row).length )
							$('.edd-mp-downloads', row).append('<a href="#" class="edd-mp-remove">Remove</a>');

						row.insertAfter( '#edd-mp-table-body tr.edd-mp-download-wrap:last' );
						return false;
					});
					// remove a download row
					$('#edd_mp_create_payment').on('click', '.edd-mp-remove', function() {
						$(this).closest('tr').remove();
						return false;
					});
					// check for variable prices
					$('#edd_mp_create_payment').on('change', '.edd-mp-download-select', function() {
						var $this = $(this);
						var selected_download = $('option:selected', this).val();
						if( parseInt( selected_download ) != 0) {
							var edd_mp_nonce = $('#edd_create_payment_nonce').val();
							var data = {
								action: 'edd_mp_check_for_variations',
								download_id: selected_download,
								key: $('tr.edd-mp-download-wrap').size() - 1,
								nonce: edd_mp_nonce
							}
							$this.parent().find('img').show();
							$.post(ajaxurl, data, function(response) {
								$this.next('select').remove();
								$this.after( response );
								$this.parent().find('img').hide();
							});
						} else {
							$this.next('select').remove();
							$this.parent().find('img').hide();
						}
					});
					if ($('.form-table .edd_datepicker').length > 0) {
						var dateFormat = 'mm/dd/yy';
						$('.edd_datepicker').datepicker({
							dateFormat: dateFormat
						});
					}
				});
			</script>
			<form id="edd_mp_create_payment" method="post">
				<table class="form-table">
					<tbody id="edd-mp-table-body">
						<tr class="form-field edd-mp-download-wrap">
							<th scope="row" valign="top">
								<label><?php echo edd_get_label_singular(); ?></label>
							</th>
							<td class="edd-mp-downloads">
								<select name="downloads[0][id]" class="edd-mp-download-select">
									<?php
									$args = array(
										'post_type' => 'download',
										'nopaging'  => true,
										'orderby'   => 'title',
										'order'     => 'ASC',
										'post_status' => 'any'
									);
									$downloads = get_posts( apply_filters( 'edd_mp_downloads_query', $args ) );
									if( $downloads ) {
										echo '<option value="0">' . sprintf( __('Choose %s', 'edd-manual-purchases'), esc_html( edd_get_label_plural() ) ) . '</option>';
										foreach( $downloads as $download ) {
											if( $download->post_status != 'publish' )
												$prefix = strtoupper( $download->post_status ) . ' - ';
											else
												$prefix = '';
											echo '<option value="' . $download->ID . '">' . $prefix . esc_html( get_the_title( $download->ID ) ) . '</option>';
										}
									} else {
										echo '<option value="0">' . sprintf( __('No %s created yet', 'edd-manual-purchases'), edd_get_label_plural() ) . '</option>';
									}
									?>
								</select>
								<a href="#" class="edd-mp-add-download"><?php _e('Add another', 'edd-manual-purchases' ); ?></a>
								<img src="<?php echo admin_url('/images/wpspin_light.gif'); ?>" class="waiting edd_mp_loading" style="display:none;"/>
							</td>
						</tr>
						<tr class="form-field">
							<th scope="row" valign="top">
								<label for="edd-mp-user"><?php _e('Buyer ID or Email', 'edd-manual-purchases'); ?></label>
							</th>
							<td class="edd-mp-user">
								<input type="text" class="small-text" id="edd-mp-user" name="user" style="width: 180px;"/>
								<div class="description"><?php _e('Enter the user ID or email of the buyer.', 'edd-manual-purchases'); ?></div>
							</td>
						</tr>
						<tr class="form-field">
							<th scope="row" valign="top">
								<label for="edd-mp-last"><?php _e('Buyer First Name', 'edd-manual-purchases'); ?></label>
							</th>
							<td class="edd-mp-last">
								<input type="text" class="small-text" id="edd-mp-last" name="first" style="width: 180px;"/>
								<div class="description"><?php _e('Enter the first name of the buyer (optional).', 'edd-manual-purchases'); ?></div>
							</td>
						</tr>
						<tr class="form-field">
							<th scope="row" valign="top">
								<label for="edd-mp-last"><?php _e('Buyer Last Name', 'edd-manual-purchases'); ?></label>
							</th>
							<td class="edd-mp-last">
								<input type="text" class="small-text" id="edd-mp-last" name="last" style="width: 180px;"/>
								<div class="description"><?php _e('Enter the last name of the buyer (optional).', 'edd-manual-purchases'); ?></div>
							</td>
						</tr>
						<tr class="form-field">
							<th scope="row" valign="top">
								<label for="edd-mp-amount"><?php _e('Amount', 'edd-manual-purchases'); ?></label>
							</th>
							<td class="edd-mp-downloads">
								<input type="text" class="small-text" id="edd-mp-amount" name="amount" style="width: 180px;"/>
								<div class="description"><?php _e('Enter the total purchase amount, or leave blank to auto calculate price based on the selected items above. Use 0.00 for 0.', 'edd-manual-purchases'); ?></div>
							</td>
						</tr>
						<?php if( edd_use_taxes() ) : ?>
						<tr class="form-field">
							<th scope="row" valign="top">
								<label for="edd-mp-tax"><?php _e('Tax', 'edd-manual-purchases'); ?></label>
							</th>
							<td class="edd-mp-downloads">
								<input type="text" class="small-text" id="edd-mp-tax" name="tax" value="0" style="width: 180px;"/>
								<div class="description"><?php _e('Enter the total tax charged on the purchase.', 'edd-manual-purchases'); ?></div>
							</td>
						</tr>
						<?php endif; ?>
						<tr class="form-field">
							<th scope="row" valign="top">
								<?php _e('Payment status', 'edd-manual-purchases'); ?>
							</th>
							<td class="edd-mp-status">
								<?php echo EDD()->html->select( array( 'name' => 'status', 'options' => edd_get_payment_statuses(), 'selected' => 'publish', 'show_option_all' => false, 'show_option_none' => false ) ); ?>
								<label for="edd-mp-status" class="description"><?php _e('Select the status of this payment.', 'edd-manual-purchases'); ?></label>
							</td>
						</tr>
						<tr class="form-field">
							<th scope="row" valign="top">
								<?php _e('Send Receipt', 'edd-manual-purchases'); ?>
							</th>
							<td class="edd-mp-receipt">
								<label for="edd-mp-receipt">
									<input type="checkbox" id="edd-mp-receipt" name="receipt" style="width: auto;" checked="1" value="1"/>
									<?php _e('Send the purchase receipt to the buyer?', 'edd-manual-purchases'); ?>
								</label>
							</td>
						</tr>
						<tr class="form-field">
							<th scope="row" valign="top">
								<label for="edd-mp-payment-method"><?php _e('Payment Method', 'edd-manual-purchases'); ?></label>
							</th>
							<td class="edd-mp-gateways">
								<select name="gateway" id="edd-mp-payment-method">
									<option value="manual_purchases"><?php esc_html_e( 'Manual Payment', 'edd-manual-purchases' ); ?></option>
									<?php foreach( edd_get_payment_gateways() as $gateway_id => $gateway ) : ?>
										<option value="<?php echo esc_attr( $gateway_id ); ?>"><?php echo esc_html( $gateway['admin_label'] ); ?></option>
									<?php endforeach; ?>
								</select>
								<div class="description"><?php _e('Select the payment method used.', 'edd-manual-purchases'); ?></div>
							</td>
						</tr>
						<tr class="form-field">
							<th scope="row" valign="top">
								<label for="edd-mp-transaction-id"><?php _e('Transaction ID', 'edd-manual-purchases'); ?></label>
							</th>
							<td class="edd-mp-downloads">
								<input type="text" class="small-text" id="edd-mp-transaction-id" name="transaction_id" style="width: 180px;"/>
								<div class="description"><?php _e('Enter the transaction ID, if any.', 'edd-manual-purchases'); ?></div>
							</td>
						</tr>
						<tr class="form-field">
							<th scope="row" valign="top">
								<label for="edd-mp-date"><?php _e('Date', 'edd-manual-purchases'); ?></label>
							</th>
							<td class="edd-mp-downloads">
								<input type="text" class="small-text edd_datepicker" id="edd-mp-date" name="date" style="width: 180px;"/>
								<div class="description"><?php _e('Enter the purchase date. Leave blank for today\'s date.', 'edd-manual-purchases'); ?></div>
							</td>
						</tr>
						<?php if( function_exists( 'eddc_record_commission' ) ) : ?>
						<tr class="form-field">
							<th scope="row" valign="top">
								<?php _e('Commission', 'edd-manual-purchases'); ?>
							</th>
							<td class="edd-mp-downloads">
								<label for="edd-mp-commission">
									<input type="checkbox" id="edd-mp-commission" name="commission" style="width: auto;"/>
									<?php _e('Record commissions (if any) for this manual purchase?', 'edd-manual-purchases'); ?>
								</label>
							</td>
						</tr>
						<?php endif; ?>
						<?php if( class_exists( 'EDD_Recurring' ) ) : ?>
						<tr class="form-field">
							<th scope="row" valign="top">
								<label for="edd-mp-date"><?php _e('Customer Expiration', 'edd-manual-purchases'); ?></label>
							</th>
							<td class="edd-mp-downloads">
								<input type="text" class="small-text edd_datepicker" id="edd-mp-expiration" name="expiration" style="width: 180px;"/>
								<div class="description"><?php _e('Set the customer\'s status to Active and set their expiration date. Leave blank to leave customer as is.', 'edd-manual-purchases'); ?></div>
							</td>
						</tr>
						<?php endif; ?>
						<?php if( class_exists( 'EDD_Simple_Shipping' ) ) : ?>
						<tr class="form-field">
							<th scope="row" valign="top">
								<label for="edd-mp-shipped"><?php _e('Shipped', 'edd-manual-purchases'); ?></label>
							</th>
							<td class="edd-mp-shipped">
								<label for="edd-mp-shipped">
									<input type="checkbox" id="edd-mp-shipped" name="shipped" style="width: auto;"/>
									<?php _e('Mark order as shipped?', 'edd-manual-purchases'); ?>
								</label>
							</td>
						</tr>
						<?php endif; ?>
						<?php if( class_exists( 'EDD_Wallet' ) ) : ?>
						<tr class="form-field">
							<th scope="row" valign="top">
								<label for="edd-mp-wallet"><?php _e( 'Pay From Wallet', 'edd-manual-purchases' ); ?></label>
							</th>
							<td class="edd-mp-wallet">
								<label for="edd-mp-wallet">
									<input type="checkbox" id="edd-mp-wallet" name="wallet" style="width: auto;"/>
									<?php _e( 'Use funds from the customers\' wallet to pay for this payment.', 'edd-manual-purchases' ); ?>
								</label>
							</td>
						</tr>
						<?php endif; ?>
					</tbody>
				</table>
				<?php wp_nonce_field( 'edd_create_payment_nonce', 'edd_create_payment_nonce' ); ?>
				<input type="hidden" name="edd-gateway" value="manual_purchases"/>
				<input type="hidden" name="edd-action" value="create_payment" />
				<?php submit_button(__('Create Payment', 'edd-manual-purchases') ); ?>
			</form>
		</div>
		<?php
	}

	public static function check_for_variations() {

		if( isset($_POST['nonce'] ) && wp_verify_nonce($_POST['nonce'], 'edd_create_payment_nonce') ) {

			$download_id = absint( $_POST['download_id'] );

			if( edd_has_variable_prices( $download_id ) ) {

				$prices = get_post_meta( $download_id, 'edd_variable_prices', true );
				$response = '';
				if( $prices ) {
					$response = '<select name="downloads[' . absint( $_POST['key'] ) . '][options][price_id]" class="edd-mp-price-select">';
						foreach( $prices as $key => $price ) {
							$response .= '<option value="' . esc_attr( $key ) . '">' . $price['name']  . '</option>';
						}
					$response .= '</select>';
				}
				echo $response;
			}
			die();
		}
	}

	public static function create_payment( $data ) {

		if( wp_verify_nonce( $data['edd_create_payment_nonce'], 'edd_create_payment_nonce' ) ) {

			global $edd_options;

			if( empty( $data['downloads'][0]['id'] ) ) {
				wp_die( sprintf( __( 'Please select at least one %s to add to the payment.', 'edd-manual-purchases' ), edd_get_label_singular() ) );
			}

			$user = strip_tags( trim( $data['user'] ) );

			if( empty( $user ) ) {
				wp_die( __( 'Please enter a username, user ID, or email for the customer this payment belongs to.', 'edd-manual-purchases' ) );
			}

			$payment = new EDD_Payment;

			if( is_numeric( $user ) ) {
				$user = get_userdata( $user );
			} elseif ( is_email( $user ) ) {
				$user = get_user_by( 'email', $user );
			} elseif ( is_string( $user ) ) {
				$user = get_user_by( 'login', $user );
			} else {
				wp_die( __( 'Please enter a username, user ID, or email for the customer this payment belongs to.', 'edd-manual-purchases' ) );
			}

			$user_id 	= $user ? $user->ID : 0;
			$email 		= $user ? $user->user_email : strip_tags( trim( $data['user'] ) );
			if( isset( $data['first'] ) ) {
				$user_first = sanitize_text_field( $data['first'] );
			} else {
				$user_first	= $user ? $user->first_name : '';
			}

			if( isset( $data['last'] ) ) {
				$user_last = sanitize_text_field( $data['last'] );
			} else {
				$user_last	= $user ? $user->last_name : '';
			}

			$payment->user_id    = $user_id;
			$payment->first_name = $user_first;
			$payment->last_name  = $user_last;
			$payment->email      = $email;

			$price = ! empty( $data['amount'] ) ? edd_sanitize_amount( strip_tags( trim( $data['amount'] ) ) ) : false;

			$cart_details = array();

			$total = 0;
			foreach( $data['downloads'] as $key => $download ) {

				// calculate total purchase cost

				if( isset( $download['options'] ) ) {

					$prices     = get_post_meta( $download['id'], 'edd_variable_prices', true );
					$price_key  = $download['options']['price_id'];
					$item_price = $prices[$price_key]['amount'];

				} else {
					$item_price = edd_get_download_price( $download['id'] );
				}

				$args = array(
					'quantity'    => 1,
					'price_id'    => isset( $download['options'] ) ? $download['options']['price_id'] : null,
					'amount'      => $item_price
				);

				$options = isset( $download['options'] ) ? $download['options'] : array();

				$payment->add_download( $download['id'], $args, $options );

				$total += $item_price;

			}

			// if we are using Wallet, ensure the customer can afford this purchase
			if( ! empty( $data['wallet'] ) && class_exists( 'EDD_Wallet' ) && $user_id > 0 ) {
				$wallet_value = edd_wallet()->wallet->balance( $user_id );

				if( $wallet_value < $total ) {
					wp_die( __( 'The customer does not have sufficient funds in their wallet to pay for this purchase.', 'edd-manual-purchases' ) );
				}
			}

			$date = ! empty( $data['date'] ) ? date( 'Y-m-d H:i:s', strtotime( strip_tags( trim( $data['date'] ) ) ) ) : false;
			if( ! $date ) {
				$date = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) );
			}

			if( strtotime( $date, time() ) > time() ) {
				$date = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) );
			}

			$payment->date     = $date;
			$payment->status   = isset( $_POST['status'] ) ? sanitize_text_field( $_POST['status'] ) : 'pending';
			$payment->tax      = ! empty( $_POST['tax'] ) ? edd_sanitize_amount( sanitize_text_field( $_POST['tax'] ) ) : 0;
			$payment->currency = edd_get_currency();
			$payment->gateway  = sanitize_text_field( $_POST['gateway'] );

			if( ! empty( $_POST['transaction_id'] ) ) {
				$payment->transaction_id = sanitize_text_field( $_POST['transaction_id'] );
			}

			$payment->save();

			if( empty( $data['receipt'] ) || $data['receipt'] != '1' ) {
				remove_action( 'edd_complete_purchase', 'edd_trigger_purchase_receipt', 999 );
			}

			if( ! empty( $data['expiration'] ) && class_exists( 'EDD_Recurring_Customer' ) && $user_id > 0 ) {

				$expiration = strtotime( $data['expiration'] . ' 23:59:59' );

				EDD_Recurring_Customer::set_as_subscriber( $user_id );
				EDD_Recurring_Customer::set_customer_payment_id( $user_id, $payment->ID );
				EDD_Recurring_Customer::set_customer_status( $user_id, 'active' );
				EDD_Recurring_Customer::set_customer_expiration( $user_id, $expiration );
			}

			if( ! empty( $data['wallet'] ) && class_exists( 'EDD_Wallet' ) && $user_id > 0 ) {
				// Update the user wallet
				edd_wallet()->wallet->withdraw( $user_id, $total, 'withdrawal', $payment->ID );
			}

			if( ! empty( $data['shipped'] ) ) {
				update_post_meta( $payment->ID, '_edd_payment_shipping_status', '2' );
			}

			wp_redirect( admin_url( 'edit.php?post_type=download&page=edd-payment-history&edd-message=payment_created' ) ); exit;

		}
	}

	public static function payment_created_notice() {
		if( isset($_GET['edd-message'] ) && $_GET['edd-message'] == 'payment_created' && current_user_can( 'view_shop_reports' ) ) {
			add_settings_error( 'edd-notices', 'edd-payment-created', __('The payment has been created.', 'edd-manual-purchases'), 'updated' );
		}
	}


}

function edd_load_manual_purchases() {
	$GLOBALS['edd_manual_purchases'] = new EDD_Manual_Purchases();
}
add_action( 'plugins_loaded', 'edd_load_manual_purchases' );
