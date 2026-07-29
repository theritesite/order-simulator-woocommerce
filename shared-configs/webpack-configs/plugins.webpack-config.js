const CopyWebpackPlugin = require( 'copy-webpack-plugin' );
const path = require( 'path' );

module.exports = ( mode, loc, pluginSlug, buildFolder, baseDir ) => {
	var devFolder = '';
	var endPath = '';
	console.log(mode);
	console.log(loc);

	if ( loc == "m1" ) {
		devFolder = '/Users/parkermathewson/mac-sites/wp56tester/wp-content/plugins/' + pluginSlug; // M1
		endPath = '/Users/parkermathewson/Library/Mobile\ Documents/com~apple~CloudDocs/theritesites/completed_pluginsv2'; // M1
		buildPath = '/Users/parkermathewson/theritesites/completed_pluginsv2'; // M1
		console.log("defined loc for m1");
	}
	if ( loc === "mac" ) {
		devFolder = '/Users/parker/sites/localwptest/wp-content/plugins/' + pluginSlug; // Mac
		endPath = '/Users/parker/Documents/theritesites/completed_plugins'; // Mac
		buildPath = '/Users/parker/Documents/theritesites/completed_plugins'; // M1
	}
	const endFolder = endPath + '/' + pluginSlug;

	let definePlugin = []
	if( mode === 'production' ) {
		console.log("build folder:" + buildFolder)
		definePlugin = new CopyWebpackPlugin( {
			patterns: [
				{ from: path.resolve( baseDir, 'README.*' ), to: buildFolder },
				{ from: path.resolve( baseDir, 'fakenames.sql' ), to: buildFolder },
				{ from: path.resolve( baseDir, '*.php' ), to: buildFolder },
				/** Above is for zip folder. Below is for repositories. **/
				{ from: path.resolve( baseDir, 'README.*' ), to: endFolder },
				{ from: path.resolve( baseDir, 'fakenames.sql' ), to: endFolder },
				{ from: path.resolve( baseDir, '*.php' ), to: endFolder },
			]
		} );
	} else {
		console.log("in development")
		console.log("dev folder: " + devFolder );
		definePlugin = new CopyWebpackPlugin( {
			patterns: [
				{ from: path.resolve( baseDir, 'README.*' ), to: devFolder },
				{ from: path.resolve( baseDir, 'fakenames.sql' ), to: devFolder },
				{ from: path.resolve( baseDir, '*.php' ), to: devFolder }
			]
		} )
	}
	return {
		definePlugin: definePlugin
	};
}