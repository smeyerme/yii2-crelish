const path = require('path');
const { VueLoaderPlugin } = require('vue-loader');

module.exports = {
  entry: './js/image-editor.js',
  output: {
    path: path.resolve(__dirname, 'dist'),
    filename: 'image-editor.js',
    publicPath: '/crelish/resources/image-editor/dist/'
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
  resolve: {
    extensions: ['.js', '.vue'],
    alias: {
      'vue': 'vue/dist/vue.esm-bundler.js'
    }
  },
  plugins: [
    new VueLoaderPlugin()
  ]
}; 