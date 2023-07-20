<?php $field = $this->plugin_id . $this->id . '_' . $key; ?>

<tr valign="top">
	<th scope="row" class="titledesc">
		<label for="<?php echo esc_attr($field); ?>"><?php echo esc_html($data['title']); ?></label>
		<?php echo esc_html($this->get_tooltip_html($data)); ?>
	</th>

	<td class="forminp" style="position: relative;">
		<fieldset>
			<legend class="screen-reader-text">
				<span><?php echo wp_kses_post($data['title']); ?></span>
			</legend>

            <select class="<?php echo esc_attr($data['class']); ?>" 
                    id="nuvei_block_pms_multiselect" 
                    onchange="nuveiDisablePm(this.value)"
            >
				<?php foreach ($data['merchant_pms'] as $val => $name) : ?>
					<option value="<?php echo esc_attr($val); ?>" 
                            id="nuvei_block_pm_<?php echo esc_attr($val); ?>" 
                            <?php if (in_array($val, $data['nuvei_blocked_pms'])) : ?> style="display: none;"<?php endif; ?>><?php echo wp_kses_post($name); ?></option>
				<?php endforeach; ?>
			</select>
			<br/>
			<br/>
			
			<input type="text" 
				   id="woocommerce_nuvei_pm_black_list_visible" 
				   class="input-text regular-input" 
				   readonly="" 
				   value="<?php echo esc_attr($data['nuvei_blocked_pms_visible']); ?>" />
			
			<input type="hidden" name="woocommerce_nuvei_pm_black_list" 
				   id="woocommerce_nuvei_pm_black_list" 
				   readonly="" 
				   value="<?php echo esc_attr($this->get_setting('pm_black_list', '')); ?>" />
			
			<button type="button" class="button-secondary" onclick="nuveiCleanBlockedPMs()">
				<?php echo esc_html('Clean', 'nuvei_checkout_woocommerce'); ?>
			</button>
		</fieldset>
	</td>
</tr>
