# plugins-woocommerce

The cashpresso WooCommerce plugin.

To install, zip the folder 'cashpresso-woocommerce' and upload in wordpress or move the folder as is to wp-content/plugins/ directory.

## Stock Management

WooCommerce has a default stock management which cancels unpaid orders 60 minutes after checkout, if no payment is registered.

To remedy, either disable the stock management or change the validity period from 60 minutes to the duration that is set in the validUntil field in the cashpresso plugin configuration.

!**Attention**! validUntil is in hours, whereas **Hold stock (minutes)** is in minutes.

If the durations do not match, the process still works, but the downside is, that the orders will be set to 'cancelled' after some time (only in wooCommerce, not at cashpresso); until the cashpresso validUntil is reached however, they can still be completed by the customer at which point the status changes to 'processing''.
