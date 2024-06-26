jQuery(document).ready(function () {
    jQuery('.single_variation_wrap').on('show_variation', function (event, variation) {
        if (window.C2EcomWizard) {
            var id = jQuery(event.target).closest('.product').find('.c2-financing-label').attr('id');

            if (id) {
                window.C2EcomWizard.refreshAmount(id, variation.display_price.toFixed(2));
            }
        }
    });
});
