const path = require('path');
const { VueLoaderPlugin } = require('vue-loader');

module.exports = {
  mode: process.env.NODE_ENV || 'development',
  entry: './js/relation-selector.js',
  output: {
    path: path.resolve(__dirname, 'dist'),
    filename: 'relation-selector.js',
  },
  module: {
    rules: [
      {
        test: /\.vue$/,
        loader: 'vue-loader'
      },
      {
        test: /\.js$/,
        loader: 'babel-loader',
        exclude: /node_modules/
      },
      {
        test: /\.css$/,
        use: ['style-loader', 'css-loader']
      }
    ]
  },
  plugins: [
    new VueLoaderPlugin()
  ],
  resolve: {
    extensions: ['.js', '.vue'],
    alias: {
      'vue': '@vue/runtime-dom'
    }
  },
  externals: {
    // Avoid bundling jQuery and select2, as they will be loaded separately
    jquery: 'jQuery',
    select2: 'select2'
  }
}; 