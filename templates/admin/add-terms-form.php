<?php defined( 'ABSPATH' ) || exit; ?>

<div id="nuvei_plan_attr_terms">
	<h2><?php esc_html__( 'Nuvei Payment Plan settings', 'nuvei-payments-for-woocommerce' ); ?></h2>
	
	<div class="form-field term-group">
		<label for=""><?php echo esc_html__( 'Plan ID', 'nuvei-payments-for-woocommerce' ); ?></label>
		
		<select name="planId" id="planId" required="" onchange="nuveiFillPlanData(this.value)">
			<option value="">
				<?php echo esc_html__( 'Without Plan', 'nuvei-payments-for-woocommerce' ); ?>
			</option>
			
			<?php
			if ( ! empty( $plans_list ) ) :
				foreach ( $plans_list as $plan ) :
					?>
						<option value="<?php echo esc_attr( $plan['planId'] ); ?>">
							<?php echo esc_html( $plan['name'] ); ?>
						</option>
					<?php
				endforeach;
			endif;
			?>
		</select>
	</div>
	
	<div class="form-field term-group">
		<label for=""><?php echo esc_html__( 'Recurring Amount', 'nuvei-payments-for-woocommerce' ); ?></label>
		<input type="number" step=".01" min="0" name="recurringAmount" id="recurringAmount" min="0" required="" />
	</div>
	
	<div class="form-field term-group">
		<label for=""><?php echo esc_html__( 'Recurring Period', 'nuvei-payments-for-woocommerce' ); ?></label>
		
		<select name="recurringPeriodUnit" id="recurringPeriodUnit" class="nuvei_units">
			<option value="day"><?php echo esc_html__( 'Days', 'nuvei-payments-for-woocommerce' ); ?></option>
			<option value="month"><?php echo esc_html__( 'Month', 'nuvei-payments-for-woocommerce' ); ?></option>
			<option value="year"><?php echo esc_html__( 'Years', 'nuvei-payments-for-woocommerce' ); ?></option>
		</select>
		
		<input type="number" min="1" step="1" name="recurringPeriodPeriod" id="recurringPeriodPeriod" class="nuvei_periods" required="" />
	</div>
	
	<div class="form-field term-group">
		<label for=""><?php echo esc_html__( 'Recurring End After', 'nuvei-payments-for-woocommerce' ); ?></label>
		
		<select name="endAfterUnit" id="endAfterUnit" class="nuvei_units">
			<option value="day"><?php echo esc_html__( 'Days', 'nuvei-payments-for-woocommerce' ); ?></option>
			<option value="month"><?php echo esc_html__( 'Month', 'nuvei-payments-for-woocommerce' ); ?></option>
			<option value="year"><?php echo esc_html__( 'Years', 'nuvei-payments-for-woocommerce' ); ?></option>
		</select>
		
		<input type="number" min="1" step="1" name="endAfterPeriod" id="endAfterPeriod" class="nuvei_periods" required="" />
	</div>
	
	<div class="form-field term-group">
		<label for=""><?php echo esc_html__( 'Trial Period', 'nuvei-payments-for-woocommerce' ); ?></label>
		
		<select name="startAfterUnit" id="startAfterUnit" class="nuvei_units">
			<option value="day"><?php echo esc_html__( 'Days', 'nuvei-payments-for-woocommerce' ); ?></option>
			<option value="month"><?php echo esc_html__( 'Month', 'nuvei-payments-for-woocommerce' ); ?></option>
			<option value="year"><?php echo esc_html__( 'Years', 'nuvei-payments-for-woocommerce' ); ?></option>
		</select>
		
		<input type="number" min="0" step="1" name="startAfterPeriod" id="startAfterPeriod" class="nuvei_periods" required="" />
	</div>
</div>