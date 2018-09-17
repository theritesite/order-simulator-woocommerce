const path = require( 'path' );
const CopyWebpackPlugin = require( 'copy-webpack-plugin' );
const browserSyncPlugin = require( 'browser-sync-webpack-plugin' );
const WebpackZipPlugin = require('webpack-zip-plugin')
const MiniCssExtractPlugin = require("mini-css-extract-plugin");

const pluginSlug = 'order-simulator-woocommerce';

const buildFolder = path.resolve( __dirname, pluginSlug );
// const vendorFolder = path.resolve( buildFolder, 'vendor' );

// const devFolder = '/Users/parker/sites/wordpress/wp-content/plugins/' + pluginSlug;
// const endPath = '/Users/parker/Documents/theritesites/completed_plugins';


const devFolder = '/var/www/wpdev.com/public_html/wp-content/plugins/' + pluginSlug; // Corsair
const endPath = '/home/parkerm34/Documents/theritesites/completed_plugins'; // Corsair

const endFolder = endPath + '/' + pluginSlug;


const config = env => {

    const pluginList = [];
    console.log(env);

    if(env === 'production' ) {
        pluginList.push(
            new CopyWebpackPlugin( [
                { from: path.resolve( __dirname, 'README.*' ), to: buildFolder },
                { from: path.resolve( __dirname, 'fakenames.sql' ), to: buildFolder },
                { from: path.resolve( __dirname, '*.php' ), to: buildFolder },
                /** Above is for zip folder. Below is for repositories. **/
                { from: path.resolve( __dirname, 'README.*' ), to: endFolder },
                { from: path.resolve( __dirname, 'fakenames.sql' ), to: endFolder },
                { from: path.resolve( __dirname, '*.php' ), to: endFolder },
            ], {

                copyUnmodified: true
            } ),
            new WebpackZipPlugin({
                initialFile: pluginSlug,
                endPath: endPath,
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
            new CopyWebpackPlugin( [
                { from: path.resolve( __dirname, 'README.*' ), to: devFolder },
                { from: path.resolve( __dirname, 'fakenames.sql' ), to: devFolder },
                { from: path.resolve( __dirname, '*.php' ), to: devFolder }
            ], {

                copyUnmodified: false
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