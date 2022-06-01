<h2>
	<?php 
		echo esc_html__('Nuvei Checkout', 'nuvei_checkout_woocommerce');
		wc_back_link(
			__( 'Return to payments', 'nuvei_checkout_woocommerce' ),
			admin_url( 'admin.php?page=wc-settings&tab=checkout' )
		);
    ?>
</h2>

<p><?php esc_html__('Please check and fill the settings of the plugin.', 'nuvei_checkout_woocommerce'); ?></p>

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
	<h3><?php echo esc_html__('Checkout SDK Settings', 'nuvei_checkout_woocommerce'); ?>:</h3>
	<table class="form-table">
		<?php
			$this->init_form_advanced_fields_checkout();
			$this->generate_settings_html();
		?>
		
	</table>
	<hr/>
	
	<h3><?php echo esc_html__('Redirect Payment Page Settings', 'nuvei_checkout_woocommerce'); ?>:</h3>
	<table class="form-table">
		<?php
			$this->init_form_advanced_fields_cashier();
			$this->generate_settings_html();
		?>
	</table>
	<hr/>
</div>

<div id="nuvei_tools_cont" class="nuvei_checkout_settings_cont" style="display: none;">
	<table class="form-table">
		<?php
			$this->init_form_tools_fields();
			$this->generate_settings_html();
		?>
	</table>
</div>
