<hr />

<div id="custom_loader" class="blockUI blockOverlay" style="height: 100%; position: absolute; top: 0px; width: 100%; z-index: 10; background-color: rgba(255,255,255,0.5); display: none;"></div>

<div class="wc-order-data-row wc-order-bulk-actions wc-order-data-row-toggle" id="nuvei_wcfm_buttons_block">
	<p class="add-items">
		<?php if ( $data['showRefundBtn'] ) : ?>
			<button id="sc_refund_btn" type="button" class="button refund-items" onclick="nuveiShowWcfmRefundBlock();">
				<?php echo esc_html__( 'Refund', 'nuvei-payments-for-woocommerce' ); ?>
			</button>
		<?php endif; ?>
		
		<?php if ( $data['voidQuestion'] ) : ?>
			<button id="sc_void_btn" type="button" onclick="nuveiAction('<?php echo esc_html( $data['voidQuestion'] ); ?>', 'void', <?php echo esc_html( $data['orderId'] ); ?>, null, 1)" class="button generate-items">
				<?php echo esc_html__( 'Void', 'nuvei-payments-for-woocommerce' ); ?>
			</button>
		<?php endif; ?>
		
		<?php if ( $data['settleQuestion'] ) : ?>
			<button id="sc_settle_btn" type="button" onclick="nuveiAction('<?php echo esc_html( $data['settleQuestion'] ); ?>', 'settle', <?php echo esc_html( $data['orderId'] ); ?>, null, 1)" class="button generate-items">
				<?php echo esc_html__( 'Settle', 'nuvei-payments-for-woocommerce' ); ?>
			</button>
		<?php endif; ?>
	</p>
	
	<input type="hidden" id="post_ID" value="<?php echo esc_html( $data['orderId'] ); ?>" />
</div>

<?php if ( $data['showRefundBtn'] ) : ?>
	<div class="wc-order-data-row wc-order-refund-items wc-order-data-row-toggle" id="nuvei_wcfm_refund_block" style="display: none;">
		<table class="wc-order-totals">
			<tbody>
				<tr>
					<td class="label">
						<label for="refund_amount">
							<?php echo esc_html__( 'Refund amount', 'nuvei-payments-for-woocommerce' ); ?>:
						</label>
					</td>

					<td class="total">
						<input type="text" id="refund_amount" name="refund_amount" class="wc_input_price">
					</td>
				</tr>

				<tr>
					<td class="label">
						<label for="refund_reason">
							<?php echo esc_html__( 'Reason for refund (optional)', 'nuvei-payments-for-woocommerce' ); ?>:
						</label>
					</td>

					<td class="total">
						<input type="text" id="refund_reason" name="refund_reason">
					</td>
				</tr>
			</tbody>
		</table>

		<div class="clear"></div>

		<div class="refund-actions" style="text-align: right;">
			<button type="button" class="button cancel-action" onclick="nuveiHideWcfmRefundBlock();">
				<?php echo esc_html__( 'Cancel', 'nuvei-payments-for-woocommerce' ); ?>
			</button>

			<button type="button" class="button button-primary" id="sc_api_refund" onclick="scCreateRefund('<?php echo esc_html__( 'Are you sure about this Refund?', 'nuvei-payments-for-woocommerce' ); ?>', '', 1);">
				<?php echo esc_html__( 'Refund via Nuvei Checkout', 'nuvei-payments-for-woocommerce' ); ?>
			</button>

			<div class="clear"></div>
		</div>
	</div>
<?php endif; ?>
