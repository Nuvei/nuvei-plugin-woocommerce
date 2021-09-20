<?php
	$plugin_url          = plugin_dir_url(NUVEI_PLUGIN_FILE);
	$force_user_token_id = $force_flag;
?>

<div id="sc_checkout_messages"></div>

<div id="sc_second_step_form" style="display: none;">
	<style><?php echo esc_html(trim($this->get_setting('merchant_style'))); ?></style>
	
	<input type="hidden" name="sc_transaction_id" id="sc_transaction_id" value="" />
	<input type="hidden" name="lst" id="lst" value="" />
	
	<div id="sc_loader_background">
		<div class="sc_modal">
			<div class="sc_content">
				<h3>
					<img src="<?php echo esc_attr($plugin_url); ?>assets/icons/loader.gif" alt="loading..." />
					<?php echo esc_html_e('Processing your Payment...', 'nuvei_woocommerce'); ?>
				</h3>
			</div>
		</div>
	</div>
	
	<ul id="nuvei_apple_pay"></ul>

	<h3 id="upos_list_title">
		<?php echo esc_html_e('Choose from yours preferred payment methods', 'nuvei_woocommerce'); ?>
	</h3>
	<ul id="sc_upos_list"></ul>

	<h3><?php echo esc_html_e('Choose from the payment options', 'nuvei_woocommerce'); ?></h3>
	<ul id="sc_apms_list"></ul>
	
	<?php wp_nonce_field('sc_checkout', 'sc_nonce'); ?>

	<button type="button" onclick="scUpdateCart()" class="button alt" name="woocommerce_checkout_place_order" value="<?php echo esc_html_e('Pay'); ?>" data-value="<?php echo esc_html_e('Pay'); ?>" data-default-text=""<?php echo esc_html_e('Place order'); ?>">
		<?php echo esc_html_e('Pay'); ?>
	</button>
	
	<script>
		var locale			= "<?php echo esc_js(Nuvei_String::format_location(get_locale())); ?>";
		scMerchantId		= scData.merchantId = "<?php echo esc_js($this->get_setting('merchantId')); ?>";
		scMerchantSiteId	= scData.merchantSiteId = "<?php echo esc_js($this->get_setting('merchantSiteId')); ?>";
		scData.env          = '<?php echo ( 'yes' == $this->get_setting('test') ? 'int' : 'prod' ); ?>';
		sfc                 = SafeCharge(scData);
		scFields            = sfc.fields({ locale: locale });
		forceUserTokenId    = <?php echo $force_user_token_id ? 1 : 0; ?>
	</script>
</div>

