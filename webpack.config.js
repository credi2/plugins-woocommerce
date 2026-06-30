const defaultConfig = require('@wordpress/scripts/config/webpack.config'),
    WooCommerceDependencyExtractionWebpackPlugin = require('@woocommerce/dependency-extraction-webpack-plugin'),
    path = require('path'),
    wcDepMap = {
        '@woocommerce/blocks-registry': ['wc', 'wcBlocksRegistry'],
        '@woocommerce/settings': ['wc', 'wcSettings'],
    },
    wcHandleMap = {
        '@woocommerce/blocks-registry': 'wc-blocks-registry',
        '@woocommerce/settings': 'wc-settings',
    },
    requestToExternal = (request) => {
        if (wcDepMap[request]) {
            return wcDepMap[request];
        }
    },
    requestToHandle = (request) => {
        if (wcHandleMap[request]) {
            return wcHandleMap[request];
        }
    };

// Export configuration.
module.exports = {
    ...defaultConfig,
    entry: {
        'js/checkout': './resources/assets/js/checkout.js',
        'blocks/product-label/index': './resources/assets/blocks/product-label/index.js',
        'blocks/product-label/view': './resources/assets/blocks/product-label/view.js',
    },
    output: {
        path: path.resolve(__dirname, 'cashpresso-woocommerce/assets'),
        filename: '[name].js',
    },
    plugins: [
        // Drop CleanWebpackPlugin so the build keeps the hand-maintained files in the
        // output directory (block.json, render.php, variable.js, …).
        ...defaultConfig.plugins.filter(
            (plugin) =>
                plugin.constructor.name !== 'DependencyExtractionWebpackPlugin' &&
                plugin.constructor.name !== 'CleanWebpackPlugin',
        ),
        new WooCommerceDependencyExtractionWebpackPlugin({
            requestToExternal,
            requestToHandle,
        }),
    ],
};
