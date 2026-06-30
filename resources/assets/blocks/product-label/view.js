import jQuery from 'jquery';

function registerBlock(block) {
    if (typeof window.C2EcomWizard?.registerLabel !== 'function') {
        return;
    }

    const label = block.querySelector('.c2-financing-label'),
        id = label && label.getAttribute('id'),
        amount = label && parseFloat(label.getAttribute('data-c2-financing-amount'));

    if (id && Number.isFinite(amount)) {
        window.C2EcomWizard.registerLabel(id, amount);
    }
}

function updateBlock(block, price) {
    if (typeof window.C2EcomWizard?.refreshAmount !== 'function') {
        return;
    }

    const label = block.querySelector('.c2-financing-label'),
        id = label && label.getAttribute('id');

    if (id) {
        window.C2EcomWizard.refreshAmount(id, price.toFixed(2));
    }
}

function initBlocks() {
    document.querySelectorAll('[data-c2-block]').forEach(registerBlock);

    // Keep the label in sync with the selected variation, scoped to the product that fired the
    // event so other products' labels on the page (related, upsells) keep their own amount.
    jQuery(document).on('show_variation', '.single_variation_wrap', (event, variation) => {
        const price = parseFloat(variation.display_price);

        if (!Number.isFinite(price)) {
            return;
        }

        const form = event.currentTarget.closest('form.variations_form'),
            productId = form?.dataset.product_id,
            selector = productId
                ? `[data-c2-block][data-c2-product-id="${productId}"]`
                : '[data-c2-block]';

        document.querySelectorAll(selector).forEach((block) => updateBlock(block, price));
    });

    // Pick up blocks injected after page load (paginated/filtered product lists, quick-view).
    if (typeof MutationObserver === 'function') {
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType !== Node.ELEMENT_NODE) {
                        return;
                    }

                    if (node.matches?.('[data-c2-block]')) {
                        registerBlock(node);
                    }

                    node.querySelectorAll?.('[data-c2-block]').forEach(registerBlock);
                });
            });
        });

        observer.observe(document.body, { childList: true, subtree: true });
    }
}

if (document.readyState !== 'loading') {
    initBlocks();
} else {
    document.addEventListener('DOMContentLoaded', initBlocks);
}
