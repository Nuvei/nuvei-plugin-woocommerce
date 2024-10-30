<br />
<div class="page_collapsible orders_details_items">
    <?= esc_html__( 'Nuvei Order Notes', 'nuvei-payments-for-woocommerce' ); ?><span></span>
</div>

<div class="wcfm-container">
    <div class="wcfm-content">
        <table cellpadding="0" cellspacing="0" class="woocommerce_order_items">
            <?php foreach($notes as $note): ?>
            <tr>
                <td>
                    <?= $note->content; ?>
                    <br />
                    <i><?= strip_tags($note->date_created->date('Y-m-d H:i')); ?></i>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>

        <div class="wcfm-clearfix"></div>
    </div>
</div>