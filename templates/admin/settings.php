<h2>
	<?php 
		echo esc_html__('Nuvei Checkout', 'nuvei_checkout_woocommerce');
		wc_back_link(
			__( 'Return to payments', 'nuvei_checkout_woocommerce' ),
			admin_url( 'admin.php?page=wc-settings&tab=checkout' )
		);
    ?>
</h2>

<p><?php echo esc_html__('Please check and fill the settings of the plugin.', 'nuvei_checkout_woocommerce'); ?></p>

<?php if (is_plugin_active('woocommerce-subscriptions' . DIRECTORY_SEPARATOR . 'woocommerce-subscriptions.php')
    && 'no' == $this->get_option('disable_wcs_alert', 'no')
): ?>
    <div class="error notice">
        <p><?php echo esc_html__('Looks like WCS plugin is activated. Please, do NOT USE products with WC Subscription and products with Nuvei Subscription in same site!!!', 'nuvei_checkout_woocommerce'); ?></p>
    </div>
<?php endif; ?>

<nav class="nav-tab-wrapper woo-nav-tab-wrapper">
	<a href="#nuvei_base_settings" class="nuvei_settings_tabs nav-tab" id="nuvei_base_settings_tab">
		<?php echo esc_html__('General Settings', 'nuvei_checkout_woocommerce'); ?>
	</a>
	<a href="#nuvei_advanced_settings" class="nuvei_settings_tabs nav-tab "id="nuvei_advanced_settings_tab">
		<?php echo esc_html__('Advanced Settings', 'nuvei_checkout_woocommerce'); ?>
	</a>
	<a href="#nuvei_tools" class="nuvei_settings_tabs nav-tab "id="nuvei_tools_tab">
		<?php echo esc_html__('Help Tools', 'nuvei_checkout_woocommerce'); ?>
	</a>
</nav>

<div id="nuvei_base_settings_cont" class="nuvei_checkout_settings_cont" style="display: none;">
	<table class="form-table">
		<?php
			$this->init_form_base_fields();
			$this->generate_settings_html();
		?>
	</table>
</div>

<div id="nuvei_advanced_settings_cont" class="nuvei_checkout_settings_cont" style="display: none;">
	<table class="form-table">
		<?php
			$this->init_form_advanced_fields();
			$this->generate_settings_html();
		?>
		
	</table>
</div>

<div id="nuvei_tools_cont" class="nuvei_checkout_settings_cont" style="display: none;">
	<table class="form-table">
		<?php
			$this->init_form_tools_fields();
			$this->generate_settings_html();
		?>
	</table>
</div>
