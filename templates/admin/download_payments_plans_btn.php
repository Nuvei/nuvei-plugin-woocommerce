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
					type="button" 
					name="<?php echo esc_attr($field); ?>" 
					id="<?php echo esc_attr($field); ?>" 
					style="<?php echo esc_attr($data['css']); ?>" 
					<?php echo esc_attr($this->get_custom_attribute_html($data)); ?>
			>
				<?php echo esc_html($data['title']); ?>
			</button>

				<?php echo esc_html($this->get_description_html($data)); ?>
		</fieldset>

		<div class="blockUI blockOverlay custom_loader" style="height: 100%; position: absolute; width: 100%; top: 0px; display: none;"></div>
	</td>
</tr>
