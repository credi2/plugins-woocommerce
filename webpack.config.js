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
        checkout: './resources/assets/js/checkout.js',
    },
    output: {
        path: path.resolve(__dirname, 'cashpresso-woocommerce/assets/js'),
        filename: '[name].js',
    },
    plugins: [
        ...defaultConfig.plugins.filter((plugin) => plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'),
        new WooCommerceDependencyExtractionWebpackPlugin({
            requestToExternal,
            requestToHandle,
        }),
    ],
};
