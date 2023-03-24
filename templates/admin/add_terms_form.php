<style>
	#nuvei_plan_attr_terms input {
		-moz-appearance: textfield !important;
	}

	#nuvei_plan_attr_terms input::-webkit-outer-spin-button,
	#nuvei_plan_attr_terms input::-webkit-inner-spin-button {
		-webkit-appearance: none;
		margin: 0;
	}
	
	select.nuvei_units {
		float: left;
		margin-right: 10px;
	}
	
	input.nuvei_periods {
		width: auto !important;
	}
</style>

<div id="nuvei_plan_attr_terms">
	<h2><?php esc_html__('Nuvei Payment Plan settings', 'nuvei_checkout_woocommerce'); ?></h2>
	
	<div class="form-field term-group">
		<label for=""><?php echo esc_html__('Plan ID', 'nuvei_checkout_woocommerce'); ?></label>
		
		<select name="planId" id="planId" required="" onchange="nuveiFillPlanData(this.value)">
			<option value="">
				<?php echo esc_html__('Without Plan', 'nuvei_checkout_woocommerce'); ?>
			</option>
			
			<?php 
			if (!empty($plans_list)) : 
				foreach ($plans_list as $plan) : 
					?>
						<option value="<?php echo esc_attr($plan['planId']); ?>">
							<?php echo esc_html($plan['name']); ?>
						</option>
					<?php 
				endforeach;
			endif; 
			?>
		</select>
	</div>
	
	<div class="form-field term-group">
		<label for=""><?php echo esc_html__('Recurring Amount', 'nuvei_checkout_woocommerce'); ?></label>
		<input type="number" step=".01" min="0" name="recurringAmount" id="recurringAmount" min="0" required="" />
	</div>
	
	<div class="form-field term-group">
		<label for=""><?php echo esc_html__('Recurring Period', 'nuvei_checkout_woocommerce'); ?></label>
		
		<select name="recurringPeriodUnit" id="recurringPeriodUnit" class="nuvei_units">
			<option value="day"><?php echo esc_html__('Days', 'nuvei_checkout_woocommerce'); ?></option>
			<option value="month"><?php echo esc_html__('Month', 'nuvei_checkout_woocommerce'); ?></option>
			<option value="year"><?php echo esc_html__('Years', 'nuvei_checkout_woocommerce'); ?></option>
		</select>
		
		<input type="number" min="1" step="1" name="recurringPeriodPeriod" id="recurringPeriodPeriod" class="nuvei_periods" required="" />
	</div>
	
	<div class="form-field term-group">
		<label for=""><?php echo esc_html__('Recurring End After', 'nuvei_checkout_woocommerce'); ?></label>
		
		<select name="endAfterUnit" id="endAfterUnit" class="nuvei_units">
			<option value="day"><?php echo esc_html__('Days', 'nuvei_checkout_woocommerce'); ?></option>
			<option value="month"><?php echo esc_html__('Month', 'nuvei_checkout_woocommerce'); ?></option>
			<option value="year"><?php echo esc_html__('Years', 'nuvei_checkout_woocommerce'); ?></option>
		</select>
		
		<input type="number" min="1" step="1" name="endAfterPeriod" id="endAfterPeriod" class="nuvei_periods" required="" />
	</div>
	
	<div class="form-field term-group">
		<label for=""><?php echo esc_html__('Trial Period', 'nuvei_checkout_woocommerce'); ?></label>
		
		<select name="startAfterUnit" id="startAfterUnit" class="nuvei_units">
			<option value="day"><?php echo esc_html__('Days', 'nuvei_checkout_woocommerce'); ?></option>
			<option value="month"><?php echo esc_html__('Month', 'nuvei_checkout_woocommerce'); ?></option>
			<option value="year"><?php echo esc_html__('Years', 'nuvei_checkout_woocommerce'); ?></option>
		</select>
		
		<input type="number" min="0" step="1" name="startAfterPeriod" id="startAfterPeriod" class="nuvei_periods" required="" />
	</div>
</div>

<script>
	var nuveiPlans = JSON.parse(scTrans.nuveiPaymentPlans);
	
	function nuveiFillPlanData(_planId) {
		if('' == _planId) {
			return;
		}
		
		for(var nuveiPlData in nuveiPlans) {
			if(_planId == nuveiPlans[nuveiPlData].planId) {
				// Recurring Amount
				jQuery('#recurringAmount').val(nuveiPlans[nuveiPlData].recurringAmount);
				
				// Recurring Units and Period
				if(nuveiPlans[nuveiPlData].recurringPeriod.year > 0) {
					jQuery('#recurringPeriodUnit').val('year');
					jQuery('#recurringPeriodPeriod').val(nuveiPlans[nuveiPlData].recurringPeriod.year);
				}
				else if(nuveiPlans[nuveiPlData].recurringPeriod.month > 0) {
					jQuery('#recurringPeriodUnit').val('month');
					jQuery('#recurringPeriodPeriod').val(nuveiPlans[nuveiPlData].recurringPeriod.month);
				}
				else {
					jQuery('#recurringPeriodUnit').val('day');
					jQuery('#recurringPeriodPeriod').val(nuveiPlans[nuveiPlData].recurringPeriod.day);
				}
				
				// Recurring End-After Units and Period
				if(nuveiPlans[nuveiPlData].endAfter.year > 0) {
					jQuery('#endAfterUnit').val('year');
					jQuery('#endAfterPeriod').val(nuveiPlans[nuveiPlData].endAfter.year);
				}
				else if(nuveiPlans[nuveiPlData].endAfter.month > 0) {
					jQuery('#endAfterUnit').val('month');
					jQuery('#endAfterPeriod').val(nuveiPlans[nuveiPlData].endAfter.month);
				}
				else {
					jQuery('#endAfterUnit').val('day');
					jQuery('#endAfterPeriod').val(nuveiPlans[nuveiPlData].endAfter.day);
				}
				
				// Recurring Trial Units and Period
				if(nuveiPlans[nuveiPlData].startAfter.year > 0) {
					jQuery('#startAfterUnit').val('year');
					jQuery('#startAfterPeriod').val(nuveiPlans[nuveiPlData].startAfter.year);
				}
				else if(nuveiPlans[nuveiPlData].startAfter.month > 0) {
					jQuery('#startAfterUnit').val('month');
					jQuery('#startAfterPeriod').val(nuveiPlans[nuveiPlData].startAfter.month);
				}
				else {
					jQuery('#startAfterUnit').val('day');
					jQuery('#startAfterPeriod').val(nuveiPlans[nuveiPlData].startAfter.day);
				}

				break;
			}
		}
	}
</script>
