const path = require( 'path' );
const CopyWebpackPlugin = require( 'copy-webpack-plugin' );
const browserSyncPlugin = require( 'browser-sync-webpack-plugin' );
const WebpackZipPlugin = require('webpack-zip-plugin')
const MiniCssExtractPlugin = require("mini-css-extract-plugin");
const { env } = require('process');

const pluginSlug = 'order-simulator-woocommerce';

const buildFolder = path.resolve( __dirname, pluginSlug );
// const vendorFolder = path.resolve( buildFolder, 'vendor' );


const config = env => {

    const pluginList = [];
    console.log(env);


    let devFolder = '';
    let endPath = '';

    if ( env.LOC === "m" ) {
        devFolder = '/Users/parker/sites/wordpress/wp-content/plugins/' + pluginSlug;
        endPath = '/Users/parker/Documents/theritesites/completed_plugins';
    }
    if ( env.LOC === "c" ) {
        devFolder = '/var/www/wpdev.com/public_html/wp-content/plugins/' + pluginSlug; // Corsair
        endPath = '/home/parkerm34/Documents/theritesites/completed_plugins'; // Corsair
    }
    if ( env.LOC === "m1" ) {
        devFolder = '/Users/parkermathewson/mac-sites/wp56tester/wp-content/plugins/' + pluginSlug; // M1
        endPath = '/Users/parkermathewson/Library/Mobile\ Documents/com~apple~CloudDocs/theritesites/completed_pluginsv2'; // M1
        buildPath = '/Users/parkermathewson/theritesites/completed_pluginsv2'; // M1
    }

    const endFolder = endPath + '/' + pluginSlug;



    if(env === 'production' ) {
        pluginList.push(
            new CopyWebpackPlugin( {
                patterns: [
                    { from: path.resolve( __dirname, 'README.*' ), to: buildFolder },
                    { from: path.resolve( __dirname, 'fakenames.sql' ), to: buildFolder },
                    { from: path.resolve( __dirname, '*.php' ), to: buildFolder },
                    /** Above is for zip folder. Below is for repositories. **/
                    { from: path.resolve( __dirname, 'README.*' ), to: endFolder },
                    { from: path.resolve( __dirname, 'fakenames.sql' ), to: endFolder },
                    { from: path.resolve( __dirname, '*.php' ), to: endFolder },
                ]
            } ),
            new WebpackZipPlugin( {
                initialFile: pluginSlug,
                endPath: buildPath,
                zipName: pluginSlug + '.zip'
            } )
        );
    }
    else {
        pluginList.push(
            new browserSyncPlugin({
                files: [
                    './' + pluginSlug + '.php',
                    './includes/*.php',
                    './includes/**/*.php',
                    './',
                    '!./node_modules',
                    '!./yarn-error.log',
                    '!./*.json',
                    '!./Gruntfile.js',
                    '!./README.md',
                    '!./*.xml',
                    '!./*.yml'
                ],
                reloadDelay: 0
            }),
            new CopyWebpackPlugin( {
                patterns: [
                    { from: path.resolve( __dirname, 'README.*' ), to: devFolder },
                    { from: path.resolve( __dirname, 'fakenames.sql' ), to: devFolder },
                    { from: path.resolve( __dirname, '*.php' ), to: devFolder }
                ]
            } ),
        );
    }

    return {
        entry: {
            "build" : path.resolve(__dirname, 'index.js')
        },
        output: {
            publicPath: '/',
            filename: pluginSlug + '.js',
            path: __dirname,
        },
        module: {
            rules: [
                {
                    test: /\.js/,
                    exclude: /node_modules/,
                    use: {
                        loader: 'babel-loader',
                        options: {
                            presets: ['babel-preset-env', 'react']
                        }
                    }
                },
                {
                    test: /\.css$/,
                    use: [
                        {
                            loader: MiniCssExtractPlugin.loader,
                            options: {
                                publicPath: '/' 
                            }
                        },
                        "css-loader"
                    ]
                }
            ]
        },
        plugins: pluginList
    };
};


module.exports = config