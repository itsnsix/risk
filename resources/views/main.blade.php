<!--
　　 ∧__∧
　 (  ･ω･) 死にまーす
＿(_つ /￣￣￣/ ・・・・・はい
　　＼/　　  /
　　　￣￣￣￣
-->
<!doctype html>
<html lang="{{app()->getLocale()}}">
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{env('APP_NAME', 'ww3.lol')}}</title>

        <!-- Fonts -->
        <link href="https://fonts.googleapis.com/css?family=Raleway:100,600" rel="stylesheet" type="text/css">
        <link rel="shortcut icon" href="/favicon.ico" type="image/x-icon">
        <link rel="icon" href="/favicon.ico" type="image/x-icon">

        <!-- Styles -->
        <link href="{{ mix('/css/app.css') }}" rel="stylesheet" type="text/css">
        <link href="{{ mix('/css/vendor.css') }}" rel="stylesheet" type="text/css">
    </head>
    <body>
        <div id="app">
            <main-page></main-page>
        </div>

        <script src="{{ mix('js/vendor.js') }}"></script>
        <script src="{{ mix('js/app.js') }}"></script>
        @include('analytics')
    </body>
</html>
