const path = require( 'path' );
const defaultConfig = require( "@wordpress/scripts/config/webpack.config" );
const getNewPreconfiguredPlugins = require("./shared-configs/webpack-configs/plugins.webpack-config")

const pluginSlug = 'order-simulator-woocommerce';

const buildFolder  = path.resolve( __dirname, pluginSlug );

const config = env => {
	console.log(env.NODE_ENV);
	console.log(env.LOC);

	const pluginList = [
		...defaultConfig.plugins
	];

	let newPluginsAvailable = getNewPreconfiguredPlugins(env.NODE_ENV, env.LOC, pluginSlug, buildFolder, __dirname)

	// console.log(defaultConfig.entry());
	let entries = defaultConfig.entry()
	entries['request'] = path.resolve(__dirname, 'index.js')
	// console.log(entries);

	return {
		...defaultConfig,
		plugins: [...pluginList, newPluginsAvailable.definePlugin],
		entry: {
			...entries,
		}
	}
}

module.exports = config;