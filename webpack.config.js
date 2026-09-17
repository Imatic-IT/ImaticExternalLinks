const path = require('path');
const TerserPlugin = require('terser-webpack-plugin');

const isProduction = process.env.NODE_ENV === 'production';

module.exports = {
  mode: isProduction ? 'production' : 'development',
  devtool: isProduction ? false : 'source-map',
  entry: {
    main: './src/index.tsx',
  },
  output: {
    path: path.resolve(__dirname, './files'),
    filename: 'index.js',
    // Lazy chunks (if introduced later, e.g. the Nextcloud picker) resolve
    // through Mantis' plugin_file.php router; the public path is set at runtime
    // in src/index.tsx. Keep chunk names within plugin_file.php's allowed set.
    chunkFilename: '[name].[contenthash].chunk.js',
    publicPath: '',
  },
  resolve: {
    extensions: ['.ts', '.tsx', '.js'],
  },
  module: {
    rules: [
      {
        test: /\.tsx?$/,
        loader: 'ts-loader',
        exclude: /node_modules/,
      },
      {
        test: /\.css$/i,
        use: ['style-loader', 'css-loader'],
      },
    ],
  },
  optimization: isProduction
    ? {
        minimize: true,
        minimizer: [new TerserPlugin()],
      }
    : {},
};
