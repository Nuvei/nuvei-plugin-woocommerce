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

			<button class="<?php echo esc_attr($data['class']); ?>" 
					id="woocommerce_nuvei_today_log" 
					type="button" 
					style="<?php echo esc_attr($data['css']); ?>" 
					onclick="nuveiGetTodayLog()" 
			>
				<?php echo esc_html($data['title_btn']); ?>
			</button>
			
			<textarea rows="10" cols="20" class="input-text wide-input nuvei_checkout_setting" type="textarea" id="woocommerce_nuvei_today_log_area" style="margin-top: 10px; display: none;"></textarea>
			
			<p class="description"><?php echo wp_kses_post($data['description']); ?></p>
		</fieldset>

		<div class="blockUI blockOverlay custom_loader" style="height: 100%; position: absolute; width: 100%; top: 0px; display: none;"></div>
	</td>
</tr>
