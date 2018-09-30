let mix = require('laravel-mix');

mix.js('resources/assets/js/app.js', 'public/js')
    .js('resources/assets/workers/painter.js', 'public/js/painter.js')
    .js('resources/assets/workers/labler.js', 'public/js/labler.js')
    .sass('resources/assets/sass/app.scss', 'public/css')

    .copy('resources/assets/images/*.*', 'public/images')

    .js('node_modules/dragscroll/dragscroll.js', 'public/js/vendor.js')

    .version();
