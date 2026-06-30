import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import { ReactComponent as Logo } from './logo.svg';
import './style.scss';

const Edit = ({ context }) => {
    const blockProps = useBlockProps();

    // Hint when placed outside a product context, where it renders nothing on the frontend.
    const postType = context?.postType;
    const outsideProductContext = postType && postType !== 'product';

    return (
        <div {...blockProps}>
            <a href="#" onClick={(event) => event.preventDefault()}>
                {__('ab', 'lnx-cashpresso-woocommerce')} 12,34 € / {__('Monat', 'lnx-cashpresso-woocommerce')}
            </a>
            {outsideProductContext && (
                <span
                    style={{
                        display: 'block',
                        marginTop: '4px',
                        fontSize: '11px',
                        fontStyle: 'italic',
                        color: '#cc1818',
                    }}
                >
                    {__(
                        'Hinweis: Dieser Block benötigt einen Produktkontext und bleibt außerhalb von Produktvorlagen leer.',
                        'lnx-cashpresso-woocommerce',
                    )}
                </span>
            )}
        </div>
    );
};

registerBlockType('cashpresso/product-label', {
    icon: <Logo width={24} height={24} aria-hidden="true" focusable="false" />,
    edit: Edit,
    save: () => null,
});
