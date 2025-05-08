import { getPaymentMethodData } from '@woocommerce/settings';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { __ } from '@wordpress/i18n';
import { decodeEntities } from '@wordpress/html-entities';
import { useEffect } from '@wordpress/element';

const settings = getPaymentMethodData('cashpresso', {}),
    defaultLabel = __('Ratenkauf', 'lnx-cashpresso-woocommerce'),
    label = decodeEntities(settings?.title || '') || defaultLabel,
    Label = (props) => {
        const { PaymentMethodLabel } = props.components;
        return <PaymentMethodLabel text={label} />;
    },
    Edit = (props) => {
        return <>{decodeEntities(settings.description || '')}</>;
    },
    Content = (props) => {
        const { activePaymentMethod, billing, emitResponse, eventRegistration } = props,
            { onPaymentProcessing } = eventRegistration;

        useEffect(() => {
            // init when payment methods are changed
            if (typeof C2EcomCheckout !== 'undefined' && activePaymentMethod === 'cashpresso') {
                C2EcomCheckout.init();
            }
        }, [activePaymentMethod]);

        useEffect(() => {
            // update user data
            const { email, first_name, last_name, country, city, postcode, address_1, address_2, phone } =
                billing.billingAddress;

            if (typeof C2EcomCheckout !== 'undefined') {
                C2EcomCheckout.refreshOptionalData({
                    email,
                    given: first_name,
                    family: last_name,
                    country,
                    city,
                    zip: postcode,
                    addressline: `${address_1} ${address_2}`,
                    phone,
                });
            }
        }, [billing.billingAddress]);

        useEffect(() => {
            // update cart data
            if (typeof C2EcomCheckout !== 'undefined' && activePaymentMethod === 'cashpresso') {
                C2EcomCheckout.refresh(billing.cartTotal.value / 100);
            }
        }, [billing.cartTotal.value]);

        useEffect(() => {
            // add cashpresso token to checkout data
            const unsubscribe = onPaymentProcessing(async () => {
                const cashpressoToken = document.getElementById('cashpressoToken')?.value;

                if (cashpressoToken) {
                    return {
                        type: emitResponse.responseTypes.SUCCESS,
                        meta: {
                            paymentMethodData: {
                                cashpressoToken,
                            },
                        },
                    };
                }

                return {
                    type: emitResponse.responseTypes.ERROR,
                    message: __('Bitte wähle deine Rate aus.', 'lnx-cashpresso-woocommerce'),
                };
            });

            return () => {
                unsubscribe();
            };
        }, [emitResponse.responseTypes.ERROR, emitResponse.responseTypes.SUCCESS, onPaymentProcessing]);

        return (
            <>
                {decodeEntities(settings.description || '')}
                <p>&nbsp;</p>
                <input type="hidden" id="cashpressoToken" name="cashpressoToken" />
                <div id="cashpresso-checkout"></div>
            </>
        );
    };

registerPaymentMethod({
    name: 'cashpresso',
    label: <Label />,
    content: <Content />,
    edit: <Edit />,
    canMakePayment({ cartTotals }) {
        const price = parseInt(cartTotals.total_price, 10) / 100;

        return price >= settings.minPurchaseAmount && price <= settings.maxPurchaseAmount;
    },
    ariaLabel: settings.title,
    supports: { features: settings.supports },
});
