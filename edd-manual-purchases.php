<?php
/*
Plugin Name: Easy Digital Downloads - Manual Purchases
Plugin URI: https://easydigitaldownloads.com/downloads/manual-purchases/
Description: Provides an admin interface for manually creating purchase orders in Easy Digital Downloads
Version: 2.0
Author: Easy Digital Downloads Team
Author URI:  https://easydigitaldownloads.com
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

		define( 'EDD_MP_PRODUCT_NAME', 'Manual Purchases' );
		define( 'EDD_MP_VERSION', '2.0' );
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

		if( ! function_exists( 'edd_price' ) ) {
			return; // EDD not present
		}

		if( version_compare( EDD_VERSION, '2.5', '<' ) ) {

			add_action( 'admin_notices', array( $this, 'edd_version_notice' ) );
			return;
		}

		global $edd_options;

		// internationalization
		add_action( 'init', array( $this, 'textdomain' ) );

		// add a crreate payment button to the top of the Payments History page
		add_action( 'edd_payments_page_top' , array( $this, 'create_payment_button' ) );

		// register the Create Payment submenu
		add_action( 'admin_menu', array( $this, 'submenu' ) );

		// load scripts
		add_action( 'admin_enqueue_scripts', array( $this, 'load_scripts' ) );
		add_filter( 'edd_load_admin_scripts', array( $this, 'register_admin_page' ), 10, 2 );

		// check for download price variations via ajax
		add_action( 'wp_ajax_edd_mp_check_for_variations', array( $this, 'check_for_variations' ) );
		add_action( 'wp_ajax_edd_mp_variation_change', array( $this, 'variation_change' ) );

		// process payment creation
		add_action( 'edd_create_payment', array( $this, 'create_payment' ) );

		// show payment created notice
		add_action( 'admin_notices', array( $this, 'payment_created_notice' ), 1 );

		// auto updater
		if( class_exists( 'EDD_License' ) ) {
			$eddc_license = new EDD_License( __FILE__, EDD_MP_PRODUCT_NAME, EDD_MP_VERSION, 'Easy Digital Downloads' );
		}

		// Add 'Payment' to the New menu of the admin bar
		add_action( 'admin_bar_menu', array( $this, 'modify_admin_bar' ), 999 );

	}

	public function edd_version_notice() {
		echo '<div class="error"><p>' . __( 'Your version of Easy Digital Downloads is below the minimum version for Manual Purchases. Please update to 2.5 or later.', 'edd-manual-purchases' ) . '</p></div>';
	}

	public static function textdomain() {

		// Set filter for plugin's languages directory
		$lang_dir = dirname( plugin_basename( __FILE__ ) ) . '/languages/';
		$lang_dir = apply_filters( 'edd_manual_purchases_lang_directory', $lang_dir );

		// Load the translations
		load_plugin_textdomain( 'edd-manual-purchases', false, $lang_dir );

	}

	/**
	 * Adds 'Payment' to the Admin Bar's 'NEW' menu
	 *
	 * @since  2.0
	 * @param  obj $wp_admin_bar The global WP_Admin_Bar object
	 * @return void
	 */
	function modify_admin_bar( $wp_admin_bar ){
		$args = array(
			'id'     => 'edd-mp-new-payment',
			'title'  => __( 'Payment', 'edd-manual-purchases' ),
			'parent' => 'new-content',
			'href'   => esc_url( add_query_arg( 'page', 'edd-manual-purchase', admin_url( 'options.php' ) ) ),
		);

		$wp_admin_bar->add_menu( $args );
	}

	public static function create_payment_button() {

		?>
		<p id="edd_create_payment_go">
			<a href="<?php echo esc_url( add_query_arg( 'page', 'edd-manual-purchase', admin_url( 'options.php' ) ) ); ?>" class="button-secondary"><?php _e( 'Create Payment', 'edd-manual-purchases' ); ?></a>
		</p>
		<?php
	}

	// Makes EDD recognize this as an admin page and include admin scripts
	public static function register_admin_page( $found, $hook ) {
		if ( 'admin_page_edd-manual-purchase' == $hook ) {
			$found = true;
		}
		return $found;
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

		add_filter( 'edd_is_admin_page', '__return_true' );
	}

	public static function payment_creation_form() {

		// Determine our float accuracy for the steps and rounding
		$decimals = edd_currency_decimal_filter();
		if ( empty( $decimals ) ) {
			$step = 1;
		} else {;
			$i    = 1;
			$step = '0.';

			while ( $i < $decimals ) {
				$step .= '0';
				$i++;
			}

			$step .= '1';
			$step = (float) $step;
		}

		$tax_included = 'false';
		if ( edd_use_taxes() && edd_prices_include_tax() ) {
			$tax_included = 'true';
		}

		$columns = 4;
		?>
		<div class="wrap">
			<h2><?php _e( 'Create New Payment', 'edd-manual-purchases' ); ?></h2>
			<script type="text/javascript">
				jQuery(document).ready(function($) {

					$(document.body).on('input', '.edd-mp-amount,.edd-mp-tax,.edd-mp-quantity', function() {
						eddmp_update_total();
					});

					// check for variable prices
					$('#edd_mp_create_payment').on('change', '.mp-downloads', function() {
						var $this = $(this);
						var selected_download = $('option:selected', this).val();
						$this.parent().parent().find('.download-price-option-wrap').html('');
						if( parseInt( selected_download ) != 0) {
							var edd_mp_nonce = $('#edd_create_payment_nonce').val();
							var key = $this.parent().parent().data('key');
							$.ajax({
								type: "POST",
								url: ajaxurl,
								data: {
									action: 'edd_mp_check_for_variations',
									download_id: selected_download,
									key: key,
									nonce: edd_mp_nonce
								},
								dataType: "json",
								success: function(response) {
									$this.parent().parent().find('.download-price-option-wrap').html( response.html );
									$this.parent().parent().find('input[name="downloads['+ key +'][amount]"]').val( response.amount );
									eddmp_update_total();
								}
							}).fail(function (data) {
								if ( window.console && window.console.log ) {
									console.log( data );
								}
							});
						} else {
							$this.parent().parent().find('.download-price-option-wrap').html('N/A');
						}
					});

					// Update the price when a variation changes
					$('#edd_mp_create_payment').on('change', '.edd-mp-price-select', function() {
						var $this        = $(this);
						var price_id     = $('option:selected', this).val();
						var edd_mp_nonce = $('#edd_create_payment_nonce').val();
						var key          = $this.parent().parent().data('key');
						var download_id  = $('select[name="downloads[' + key + '][id]"]').val();

						$.ajax({
							type: "POST",
							url: ajaxurl,
							data: {
								action: 'edd_mp_variation_change',
								download_id: download_id,
								price_id: price_id,
								key: key,
								nonce: edd_mp_nonce
							},
							dataType: "json",
							success: function(response) {
								$this.parent().parent().find('input[name="downloads['+ key +'][amount]"]').val( response.amount );
								eddmp_update_total();
							}
						}).fail(function (data) {
							if ( window.console && window.console.log ) {
								console.log( data );
							}
						});

					});

					$('.edd_add_repeatable').click(function() {
						setTimeout( function() {
							$('.edd_repeatable_row:last').find('.download-price-option-wrap').html('');
							$('.edd_repeatable_row:last').find('.edd-mp-quantity').val('1');
						}, 300 );
					});

					$(document.body).on('click', '.edd_remove_repeatable', function() {
						setTimeout( function() {
							var row_count = $('.edd_repeatable_row').length;
							if ( 1 === row_count ) {
								var current_quantity = $('.edd_repeatable_row:first').find('.edd-mp-quantity').val();
								if ( '' === current_quantity ) {
									$('.edd_repeatable_row:first').find('.edd-mp-quantity').val('1');
								}
							}

							eddmp_update_total();
						}, 100 );
					});

					if ($('.form-table .edd_datepicker').length > 0) {
						var dateFormat = 'mm/dd/yy';
						$('.edd_datepicker').datepicker({
							dateFormat: dateFormat
						});
					}

					function eddmp_update_total() {
						// Setup some place holder vars for each row
						var item_amount   = 0;
						var item_tax      = 0;
						var item_quantity = 1;
						var item_total    = 0;

						// Our final total to show to the customer
						var total      = 0;

						var prices_include_tax = <?php echo $tax_included; ?>;

						// Iterate over each line item and add amount + tax * quantity to get the total
						$('.edd_repeatable_row').each(function() {
							var row = $(this);

							item_amount   = parseFloat( row.find('.edd-mp-amount').val() );

							if (row.find('.edd-mp-tax').length) {
								item_tax      = parseFloat(row.find('.edd-mp-tax').val() );

								if (! isNaN(item_tax) && ! prices_include_tax) {
									item_amount = item_amount + item_tax;
								}
							}

							if (row.find('.edd-mp-quantity').length) {
								item_quantity = parseFloat(row.find('.edd-mp-quantity').val() );
							}

							item_total  = item_amount * item_quantity;

							total += item_total;
						});

						if ( isNaN( total ) ){
							total = 0;
						}

						$('#edd-mp-total-amount').html(total.toFixed(<?php echo $decimals; ?>));
					}
				});
			</script>

			<form id="edd_mp_create_payment" method="post">
				<table class="form-table" id="edd-customer-details">
					<tbody id="edd-mp-table-body">
						<tr class="form-field edd-mp-download-wrap">
							<th scope="row" valign="top">
								<label><?php echo edd_get_label_plural(); ?></label>
							</th>
							<td class="edd-mp-downloads">
								<div id="edd_file_fields" class="edd_meta_table_wrap">
									<table class="widefat edd_repeatable_table" style="width: auto;" cellpadding="0" cellspacing="0">
										<thead>
											<tr>
												<th style="padding: 10px;"><?php echo edd_get_label_plural(); ?></th>
												<th style="padding: 10px;"><?php _e( 'Price Option', 'edd-manual-purchases' ); ?></th>
												<th style="padding: 10px; width: 150px;"><?php _e( 'Amount', 'edd-manual-purchases' ); ?></th>
												<?php if( edd_use_taxes() ) : ?>
													<th style="padding: 10px; width: 150px;"><?php _e( 'Tax', 'edd-manual-purchases' ); ?></th>
													<?php $columns++; ?>
												<?php endif; ?><?php if( edd_item_quantities_enabled() ) : ?>
													<th style="padding: 10px; width: 50px;"><?php _e( 'Quantity', 'edd-manual-purchases' ); ?></th>
													<?php $columns++; ?>
												<?php endif; ?>
												<th style="padding: 10px; width: 5px;"
											</tr>
										</thead>
										<tbody>
											<tr class="edd_repeatable_product_wrapper edd_repeatable_row" data-key="1">
												<td>
													<?php
													echo EDD()->html->product_dropdown( array(
														'name'     => 'downloads[1][id]',
														'id'       => 'downloads',
														'class'    => 'mp-downloads',
														'multiple' => false,
														'chosen'   => true,
														'bundles'  => false
													) );
													?>
												</td>
												<td class="download-price-option-wrap"><?php _e( 'N/A', 'edd-manual-purchases' ); ?></td>
												<td>
													<input type="number" step="<?php echo $step; ?>" class="edd-mp-amount" name="downloads[1][amount]" value="" min="0" placeholder="<?php esc_attr_e( 'Item price', 'edd-manual-purchases' ); ?>"/>
												</td>
												<?php if( edd_use_taxes() ) : ?>
													<td>
														<?php if ( ! edd_prices_include_tax() ) : ?>
														&nbsp;&plus;&nbsp;
														<?php endif; ?>
														<input type="number" style="width: 65%" step="<?php echo $step; ?>" class="edd-mp-tax" name="downloads[1][tax]" value="" min="0" placeholder="<?php esc_attr_e( 'Item Tax', 'edd-manual-purchases' ); ?>"/>
													</td>
												<?php endif; ?>
												<?php if( edd_item_quantities_enabled() ) : ?>
													<td>
														&nbsp;&times;&nbsp;<input type="number" step="1" class="edd-mp-quantity" style="width: 65%" name="downloads[1][quantity]" value="1" min="1" placeholder="<?php esc_attr_e( 'Enter quantity', 'edd-manual-purchases' ); ?>"/>
													</td>
												<?php endif; ?>
												<td>
													<a href="#" class="edd_remove_repeatable" data-type="file" style="background: url(<?php echo admin_url('/images/xit.gif'); ?>) no-repeat;">&times;</a>
												</td>
											</tr>
											<tr>
												<td class="submit" colspan="<?php echo $columns; ?>" style="float: none; clear:both; background: #fff;">
													<a class="button-secondary edd_add_repeatable" style="margin: 6px 0 10px;"><?php _e( 'Add New', 'edd-manual-purchases' ); ?></a>
													<span style="line-height: 38px;">
														Total: <?php echo edd_currency_symbol(); ?><span id="edd-mp-total-amount">0.00</span>
														<?php if ( edd_use_taxes() ) :?>
															<sup>&dagger;</sup>
														<?php endif; ?>
													</span>
												</td>
											</tr>
										</tbody>
									</table>
								</div>
								<span>
									<small>
									<?php if ( edd_use_taxes() ) : ?><sup>&dagger;</sup>
										<?php if ( ! edd_prices_include_tax() ) : ?>
											<em><?php _e( 'Total is based on prices exclusive of tax.', 'edd-manual-purchases' ); ?></em>
										<?php else: ?>
											<em><?php _e( 'Total is based on prices inclusive of tax.', 'edd-manual-purchases' ); ?></em>
										<?php endif; ?>
									<?php endif; ?>
									</small>
								</span>
							</td>
						</tr>
						<tr class="form-field">
							<th scope="row" valign="top">
								<label for="edd-mp-user"><?php _e( 'Customer', 'edd-manual-purchases' ); ?></label>
							</th>
							<td class="edd-mp-email">
								<div class="customer-info">
									<?php echo EDD()->html->customer_dropdown( array( 'name' => 'customer' ) ); ?>
								</div>
								<div class="description customer-info">
									<a href="#new" class="edd-payment-new-customer" title="<?php _e( 'New Customer', 'edd-manual-purchases' ); ?>"><?php _e( 'Create new customer', 'edd-manual-purchases' ); ?></a>
								</div>
								<div class="description new-customer" style="display: none">
									<a href="#cancel" class="edd-payment-new-customer-cancel" title="<?php _e( 'Existing Customer', 'edd-manual-purchases' ); ?>"><?php _e( 'Select existing customer', 'edd-manual-purchases' ); ?></a>
								</div>
							</td>
						</tr>
						<tr class="form-field new-customer" style="display: none">
							<th scope="row" valign="top">
								<label for="edd-mp-user"><?php _e( 'Customer Email', 'edd-manual-purchases' ); ?></label>
							</th>
							<td class="edd-mp-email">
								<input type="text" class="small-text" id="edd-mp-email" name="email" style="width: 180px;"/>
								<div class="description"><?php _e( 'Enter the email address of the customer.', 'edd-manual-purchases' ); ?></div>
							</td>
						</tr>
						<tr class="form-field new-customer" style="display: none">
							<th scope="row" valign="top">
								<label for="edd-mp-last"><?php _e( 'Customer First Name', 'edd-manual-purchases' ); ?></label>
							</th>
							<td class="edd-mp-last">
								<input type="text" class="small-text" id="edd-mp-last" name="first" style="width: 180px;"/>
								<div class="description"><?php _e( 'Enter the first name of the customer (optional).', 'edd-manual-purchases' ); ?></div>
							</td>
						</tr>
						<tr class="form-field new-customer" style="display: none">
							<th scope="row" valign="top">
								<label for="edd-mp-last"><?php _e( 'Customer Last Name', 'edd-manual-purchases' ); ?></label>
							</th>
							<td class="edd-mp-last">
								<input type="text" class="small-text" id="edd-mp-last" name="last" style="width: 180px;"/>
								<div class="description"><?php _e( 'Enter the last name of the customer (optional).', 'edd-manual-purchases' ); ?></div>
							</td>
						</tr>
						<tr class="form-field">
							<th scope="row" valign="top">
								<label for="edd-mp-amount"><?php _e( 'Amount', 'edd-manual-purchases' ); ?></label>
							</th>
							<td class="edd-mp-downloads">
								<input type="text" class="small-text" id="edd-mp-amount" name="amount" style="width: 180px;"/>
								<?php if( edd_item_quantities_enabled() ) : ?>
									<div class="description"><?php _e( 'Enter the total purchase amount, or leave blank to auto calculate price based on the selected items and quantities above. Use 0.00 for 0.', 'edd-manual-purchases' ); ?></div>
								<?php else: ?>
									<div class="description"><?php _e( 'Enter the total purchase amount, or leave blank to auto calculate price based on the selected items above. Use 0.00 for 0.', 'edd-manual-purchases' ); ?></div>
								<?php endif; ?>
							</td>
						</tr>
						<tr class="form-field">
							<th scope="row" valign="top">
								<?php _e( 'Payment status', 'edd-manual-purchases' ); ?>
							</th>
							<td class="edd-mp-status">
								<?php echo EDD()->html->select( array( 'name' => 'status', 'options' => edd_get_payment_statuses(), 'selected' => 'publish', 'show_option_all' => false, 'show_option_none' => false ) ); ?>
								<label for="edd-mp-status" class="description"><?php _e( 'Select the status of this payment.', 'edd-manual-purchases' ); ?></label>
							</td>
						</tr>
						<tr class="form-field">
							<th scope="row" valign="top">
								<label for="edd-mp-payment-method"><?php _e( 'Payment Method', 'edd-manual-purchases' ); ?></label>
							</th>
							<td class="edd-mp-gateways">
								<select name="gateway" id="edd-mp-payment-method">
									<option value="manual_purchases"><?php esc_html_e( 'Manual Payment', 'edd-manual-purchases' ); ?></option>
									<?php foreach( edd_get_payment_gateways() as $gateway_id => $gateway ) : ?>
										<option value="<?php echo esc_attr( $gateway_id ); ?>"><?php echo esc_html( $gateway['admin_label'] ); ?></option>
									<?php endforeach; ?>
								</select>
								<div class="description"><?php _e( 'Select the payment method used.', 'edd-manual-purchases' ); ?></div>
							</td>
						</tr>
						<tr class="form-field">
							<th scope="row" valign="top">
								<label for="edd-mp-transaction-id"><?php _e( 'Transaction ID', 'edd-manual-purchases' ); ?></label>
							</th>
							<td class="edd-mp-downloads">
								<input type="text" class="small-text" id="edd-mp-transaction-id" name="transaction_id" style="width: 180px;"/>
								<div class="description"><?php _e( 'Enter the transaction ID, if any.', 'edd-manual-purchases' ); ?></div>
							</td>
						</tr>
						<tr class="form-field">
							<th scope="row" valign="top">
								<label for="edd-mp-date"><?php _e( 'Date', 'edd-manual-purchases' ); ?></label>
							</th>
							<td class="edd-mp-downloads">
								<input type="text" class="small-text edd_datepicker" id="edd-mp-date" name="date" style="width: 180px;"/>
								<div class="description"><?php _e( 'Enter the purchase date, or leave blank for today\'s date.', 'edd-manual-purchases' ); ?></div>
							</td>
						</tr>
						<?php if( function_exists( 'eddc_record_commission' ) ) : ?>
						<tr class="form-field">
							<th scope="row" valign="top">
								<?php _e( 'Commission', 'edd-manual-purchases' ); ?>
							</th>
							<td class="edd-mp-downloads">
								<label for="edd-mp-commission">
									<input type="checkbox" id="edd-mp-commission" name="commission" style="width: auto;"/>
									<?php _e( 'Record commissions (if any) for this manual purchase?', 'edd-manual-purchases' ); ?>
								</label>
							</td>
						</tr>
						<?php endif; ?>
						<?php if( class_exists( 'EDD_Simple_Shipping' ) ) : ?>
						<tr class="form-field">
							<th scope="row" valign="top">
								<label for="edd-mp-shipped"><?php _e( 'Shipped', 'edd-manual-purchases' ); ?></label>
							</th>
							<td class="edd-mp-shipped">
								<label for="edd-mp-shipped">
									<input type="checkbox" id="edd-mp-shipped" name="shipped" style="width: auto;"/>
									<?php _e( 'Mark order as shipped?', 'edd-manual-purchases' ); ?>
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
						<tr class="form-field">
							<th scope="row" valign="top">
								<?php _e( 'Send Receipt', 'edd-manual-purchases' ); ?>
							</th>
							<td class="edd-mp-receipt">
								<label for="edd-mp-receipt">
									<input type="checkbox" id="edd-mp-receipt" name="receipt" style="width: auto;" checked="1" value="1"/>
									<?php _e( 'Send the purchase receipt to the buyer?', 'edd-manual-purchases' ); ?>
								</label>
							</td>
						</tr>
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

			$response = array();

			if( edd_has_variable_prices( $download_id ) ) {

				$prices = get_post_meta( $download_id, 'edd_variable_prices', true );
				$html   = '';
				if( $prices ) {
					$html = '<select name="downloads[' . absint( $_POST['key'] ) . '][price_id]" class="edd-mp-price-select">';
						foreach( $prices as $key => $price ) {
							$html .= '<option value="' . esc_attr( $key ) . '">' . $price['name']  . '</option>';

							if ( ! isset( $response['amount'] ) ) {
								$response['amount'] = $price['amount'];
							}
						}
					$html .= '</select>';
				}
				$response['html'] = $html;

			} else {

				$response['amount'] = edd_get_download_price( $download_id );

			}

			echo json_encode( $response );
			exit;
		}
	}

	public static function variation_change() {

		if( isset($_POST['nonce'] ) && wp_verify_nonce($_POST['nonce'], 'edd_create_payment_nonce') ) {

			$download_id = absint( $_POST['download_id'] );
			$price_id    = absint( $_POST['price_id'] );
			$response    = array();

			if( ! edd_has_variable_prices( $download_id ) ) {

				$response['amount'] = false;

			} else {

				$prices = edd_get_variable_prices( $download_id );
				$response['amount'] = isset( $prices[ $price_id ] ) ? $prices[ $price_id ]['amount'] : false;

			}

			echo json_encode( $response );
			exit;
		}

	}

	public static function create_payment( $data ) {

		if( wp_verify_nonce( $data['edd_create_payment_nonce'], 'edd_create_payment_nonce' ) ) {

			global $edd_options;

			$data['downloads'] = array_values( $data['downloads'] );

			if( $data['downloads'][0]['id'] == 0 ) {
				wp_die( sprintf( __( 'Please select at least one %s to add to the payment.', 'edd-manual-purchases' ), edd_get_label_singular() ) );
			}

			$by_user_id = false;
			if ( ! empty( $data['email'] ) ) {
				$user = strip_tags( trim( $data['email'] ) );
				$by_user_id = false;
			} elseif ( empty( $data['email'] ) && ! empty( $data['customer'] ) ) {
				$user = strip_tags( trim( $data['customer'] ) );
			} else {
				$user = null;
			}

			if( null == $user ) {
				wp_die( __( 'Please select a customer or create a new one.', 'edd-manual-purchases' ) );
			}

			$payment  = new EDD_Payment;
			$customer = new EDD_Customer( $user, $by_user_id );
			$user_id  = ( $by_user_id == true ) ? $user : 0;
			$email    = ( $by_user_id == false ) ? $user : '';
			$first    = isset( $data['first'] ) ? sanitize_text_field( $data['first'] ) : '';
			$last     = isset( $data['last'] ) ? sanitize_text_field( $data['last'] ) : '';

			if( ! $customer->id > 0 ) {

				$user = ( $by_user_id == false ) ? get_user_by( 'email', $user ) : get_user_by( 'id', $user );
				if ( $user ) {
					$user_id = $user->ID;
					$email = $user->user_email;
				}

				$customer->create( array(
					'email'   => $email,
					'name'    => $first . ' ' . $last,
					'user_id' => $user_id
				) );

			} else {
				$email = $customer->email;
			}

			$total                = 0.00;
			$payment->customer_id = $customer->id;
			$payment->user_id     = $user_id;
			$payment->first_name  = $first;
			$payment->last_name   = $last;
			$payment->email       = $email;

			// Make sure the user info data is set
			$payment->user_info = array(
				'first_name' => $first,
				'last_name'  => $last,
				'id'         => $user_id,
				'email'      => $email,
			);

			$cart_details = array();

			$total = 0;
			foreach( $data['downloads'] as $key => $download ) {

				// calculate total purchase cost

				if( isset( $download['price_id'] ) && empty( $download['amount'] ) ) {

					$prices     = get_post_meta( $download['id'], 'edd_variable_prices', true );
					$price_key  = $download['options']['price_id'];
					$item_price = $prices[ $download['price_id'] ]['amount'];

				} elseif ( empty( $download['amount'] ) ) {

					$item_price = edd_get_download_price( $download['id'] );

				}

				$item_tax   =

				$args = array(
					'quantity'   => ! empty( $download['quantity'] ) ? absint( $download['quantity'] )            : 1,
					'price_id'   => isset( $download['price_id'] )   ? $download['price_id']                      : null,
					'item_price' => ! empty( $download['amount'] )   ? edd_sanitize_amount( $download['amount'] ) : $item_price,
				);

				$args['tax']      = ! empty( $download['tax'] )      ? edd_sanitize_amount( $download['tax'] * $args['quantity'] )    : 0;

				$payment->add_download( $download['id'], $args );

				$total += ( $args['item_price'] * $args['quantity'] );

			}

			if( ! empty( $data['amount'] ) ) {
				$total = edd_sanitize_amount( strip_tags( trim( $data['amount'] ) ) );
				$payment->total = $total;
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
			$payment->status   = 'pending';
			$payment->currency = edd_get_currency();
			$payment->gateway  = sanitize_text_field( $_POST['gateway'] );

			if( ! empty( $_POST['transaction_id'] ) ) {
				$payment->transaction_id = sanitize_text_field( $_POST['transaction_id'] );
			}

			$payment->save();

			if ( isset( $_POST['status'] ) && 'pending' !== $_POST['status'] ) {
				$payment->status = $_POST['status'];
				$payment->save();
			}

			if( empty( $data['receipt'] ) || $data['receipt'] != '1' ) {
				remove_action( 'edd_complete_purchase', 'edd_trigger_purchase_receipt', 999 );
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
