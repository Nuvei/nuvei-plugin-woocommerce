<h2>
    <?php _e('Nuvei Checkout', 'nuvei_checkout_woocommerce')
    . wc_back_link(__( 'Return to payments', 'woocommerce' ), admin_url( 'admin.php?page=wc-settings&tab=checkout' )); ?>
</h2>

<p><?php _e('Please check and fill the settings of the plugin.', 'nuvei_checkout_woocommerce'); ?></p>

<nav class="nav-tab-wrapper woo-nav-tab-wrapper">
    <a href="#nuvei_base_settings" class="nuvei_settings_tabs nav-tab" id="nuvei_base_settings_tab">
        <?php _e('Base Settings', 'nuvei_checkout_woocommerce'); ?>
    </a>
    <a href="#nuvei_advanced_settings" class="nuvei_settings_tabs nav-tab "id="nuvei_advanced_settings_tab">
        <?php _e('Advanced Settings', 'nuvei_checkout_woocommerce'); ?>
    </a>
    <a href="#nuvei_tools" class="nuvei_settings_tabs nav-tab "id="nuvei_tools_tab">
        <?php _e('Help Tools', 'nuvei_checkout_woocommerce'); ?>
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
    <h3><?php _e('Checkout Settings', 'nuvei_checkout_woocommerce'); ?>:</h3>
    <table class="form-table">
        <?php
            $this->init_form_advanced_fields_checkout();
            $this->generate_settings_html();
        ?>
        
    </table>
    <hr/>
    
    <h3><?php _e('Cashier Settings', 'nuvei_checkout_woocommerce'); ?>:</h3>
    <table class="form-table">
        <?php
            $this->init_form_advanced_fields_cashier();
            $this->generate_settings_html();
        ?>
    </table>
    <hr/>
    
    <h3><?php _e('Other Settings', 'nuvei_checkout_woocommerce'); ?>:</h3>
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
