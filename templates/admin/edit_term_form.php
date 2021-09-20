<style>
	.nuvei_meta_fileds input {
		-moz-appearance: textfield !important;
	}

	endAfter.nuvei_meta_fileds input::-webkit-outer-spin-button,
	endAfter.nuvei_meta_fileds input::-webkit-inner-spin-button {
		-webkit-appearance: none;
		margin: 0;
	}
	
	select.nuvei_units {
		float: left;
		margin-right: 10px;
	}
	
	th {
		text-align: left;
	}
</style>

<tr class="nuvei_meta_fileds">
	<th><?php echo esc_html__('Plan ID', 'nuvei_woocommerce'); ?></th>
	<td>
		<select name="planId" id="planId">
			<?php 
			if (!empty($plans_list)) : 
				foreach ($plans_list as $plan) : 
					?>
					<option value="<?php echo esc_attr($plan['planId']); ?>" <?php echo esc_html( current($term_meta['planId']) == $plan['planId'] ? 'selected=' : '' ); ?>>
						<?php echo esc_html($plan['name']); ?>
					</option>
					<?php 
				endforeach;
			endif; 
			?>
		</select>
	</td>
</tr>

<tr class="nuvei_meta_fileds">
	<th><?php echo esc_html__('Recurring Amount', 'nuvei_woocommerce'); ?></th>
	<td>
		<input type="number" 
			   min="0" 
			   step=".01" 
			   name="recurringAmount" 
			   value="<?php echo esc_attr(current($term_meta['recurringAmount'])); ?>" 
			   required="" />
	</td>
</tr>

<tr class="nuvei_meta_fileds">
	<th><?php echo esc_html__('Recurring Period', 'nuvei_woocommerce'); ?></th>
	<td>
		<select name="recurringPeriodUnit" id="recurringPeriodUnit" class="nuvei_units">
			<option value="day" <?php echo esc_html( current($term_meta['recurringPeriodUnit']) == 'day' ? 'selected=' : '' ); ?>>
				<?php echo esc_html__('Days', 'nuvei_woocommerce'); ?>
			</option>
			
			<option value="month" <?php echo esc_html( current($term_meta['recurringPeriodUnit']) == 'month' ? 'selected=' : '' ); ?>>
				<?php echo esc_html__('Month', 'nuvei_woocommerce'); ?>
			</option>
			
			<option value="year"  <?php echo esc_html( current($term_meta['recurringPeriodUnit']) == 'year' ? 'selected=' : '' ); ?>>
				<?php echo esc_html__('Years', 'nuvei_woocommerce'); ?>
			</option>
		</select>
		
		<input 
			type="number" 
			min="1" 
			step="1" 
			name="recurringPeriodPeriod" 
			id="recurringPeriodPeriod" 
			value="<?php echo esc_attr(current($term_meta['recurringPeriodPeriod'])); ?>" 
			required="" />
	</td>
</tr>

<tr class="nuvei_meta_fileds">
	<th><?php echo esc_html__('Recurring End After', 'nuvei_woocommerce'); ?></th>
	<td>
		<select name="endAfterUnit" id="endAfterUnit" class="nuvei_units">
			<option value="day" <?php echo esc_html( current($term_meta['endAfterUnit']) == 'day' ? 'selected=' : '' ); ?>>
				<?php echo esc_html__('Days', 'nuvei_woocommerce'); ?>
			</option>
			
			<option value="month" <?php echo esc_html( current($term_meta['endAfterUnit']) == 'month' ? 'selected=' : '' ); ?>>
				<?php echo esc_html__('Month', 'nuvei_woocommerce'); ?>
			</option>
			
			<option value="year" <?php echo esc_html( current($term_meta['endAfterUnit']) == 'year' ? 'selected=' : '' ); ?>>
				<?php echo esc_html__('Years', 'nuvei_woocommerce'); ?>
			</option>
		</select>
		
		<input type="number" 
			   min="1" 
			   step="1" 
			   name="endAfterPeriod" 
			   id="endAfterPeriod" 
			   value="<?php echo esc_attr(current($term_meta['endAfterPeriod'])); ?>" 
			   required="" />
	</td>
</tr>

<tr class="nuvei_meta_fileds">
	<th><?php echo esc_html__('Trial Period', 'nuvei_woocommerce'); ?></th>
	<td>
		<select name="startAfterUnit" id="startAfterUnit" class="nuvei_units" required="">
			<option value="day" <?php echo esc_html( current($term_meta['startAfterUnit']) == 'day' ? 'selected=' : '' ); ?>>
				<?php echo esc_html__('Days', 'nuvei_woocommerce'); ?>
			</option>
			
			<option value="month" <?php echo esc_html( current($term_meta['startAfterUnit']) == 'month' ? 'selected=' : '' ); ?>>
				<?php echo esc_html__('Month', 'nuvei_woocommerce'); ?>
			</option>
			
			<option value="year" <?php echo esc_html( current($term_meta['startAfterUnit']) == 'year' ? 'selected=' : '' ); ?>>
				<?php echo esc_html__('Years', 'nuvei_woocommerce'); ?>
			</option>
		</select>
		
		<input type="number" 
			   min="0" 
			   step="1" 
			   name="startAfterPeriod" 
			   id="startAfterPeriod" 
			   value="<?php echo esc_attr(current($term_meta['startAfterPeriod'])); ?>" required="" />
	</td>
</tr>
