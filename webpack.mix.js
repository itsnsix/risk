let mix = require('laravel-mix');

mix.js('resources/assets/js/app.js', 'public/js')
   .sass('resources/assets/sass/app.scss', 'public/css')

    .js('node_modules/dragscroll/dragscroll.js', 'public/js/vendor.js')
   .sass('resources/assets/sass/vendor.scss', 'public/css')

   .version();
